<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Indexation amoxtli des pièces d'un marché.
 *
 * Les pièces déposées (uploads/boamp/{id}) ne sont jamais modifiées : chaque
 * passage met à jour un corpus dans le workspace (uploads/amoxtli/{id}/corpus),
 * fait de copies des formats lus par amoxtli et de conversions en texte des
 * autres, puis amoxtli synchronise ce seul corpus.
 *
 * @phpstan-type CorpusReport array{
 *     converted: list<string>,
 *     copied: list<string>,
 *     removed: list<string>,
 *     unsupported: list<string>,
 *     failed: list<array{file: string, reason: string}>,
 *     sources: array<string, string>,
 *     fingerprints: array<string, string>,
 * }
 */
class AmoxtliService
{
    /**
     * Formats lus par amoxtli (pandoc), copiés tels quels. amoxtli ne
     * reconnaît que les extensions en minuscules.
     */
    private const NATIVE_EXTENSIONS = ['txt', 'md', 'docx', 'odt', 'rtf', 'html', 'rst', 'epub', 'tex'];

    /** Un PDF de plus de 50 Ko qui donne moins de 100 caractères est un scan. */
    private const PDF_MIN_CHARS = 100;
    private const PDF_SCAN_MIN_SIZE = 50_000;

    private const DOC_LIST_PAGE_SIZE = 200;

    /** Délai d'amoxtli sync par fichier (son défaut) : la synchro entière est bornée par leur somme. */
    private const SYNC_FILE_TIMEOUT = 300;

    /**
     * Toutes les feuilles d'un classeur (.xlsx, .xls) → CSV, chacune précédée
     * de "## Feuille : <nom>", sans lignes vides ni cellules vides en fin de
     * ligne : la mise en forme d'un classeur peut étendre la plage utilisée à
     * des milliers de cellules vides (ex. 1031 colonnes, 2,8 Mo de "","",…
     * pour 170 Ko de .xlsx), que la recherche renvoie ensuite au LLM.
     *
     * Les cellules donnent la valeur affichée par le tableur : résultat des
     * formules, nombres à 15 chiffres significatifs sans ".0", dates en
     * AAAA-MM-JJ (xlrd les rend en numéros de série).
     */
    private const SPREADSHEET_TO_CSV = <<<'PY'
        import csv, datetime, sys

        path = sys.argv[1]
        out = csv.writer(sys.stdout, quoting=csv.QUOTE_ALL, lineterminator="\n")

        def xls_cell(c, datemode):
            import xlrd
            if c.ctype == xlrd.XL_CELL_DATE:
                value = xlrd.xldate_as_datetime(c.value, datemode)
                return value.time() if c.value < 1 else value
            if c.ctype == xlrd.XL_CELL_BOOLEAN:
                return bool(c.value)
            if c.ctype == xlrd.XL_CELL_ERROR:
                return xlrd.error_text_from_code.get(c.value, "")
            return c.value

        def sheets():
            if path.lower().endswith(".xls"):
                import xlrd
                book = xlrd.open_workbook(path)
                for sheet in book.sheets():
                    yield sheet.name, ([xls_cell(c, book.datemode) for c in sheet.row(i)] for i in range(sheet.nrows))
            else:
                import openpyxl
                # Sans data_only, openpyxl rend le texte des formules (=D5*E5) au lieu de leur résultat.
                for sheet in openpyxl.load_workbook(path, data_only=True).worksheets:
                    yield sheet.title, sheet.values

        def text(v):
            if v is None:
                return ""
            if isinstance(v, float):
                v = float("%.15g" % v)
                return int(v) if v.is_integer() else v
            if isinstance(v, datetime.datetime):
                return v.date().isoformat() if v.time() == datetime.time() else v.isoformat(sep=" ")
            return v

        for name, rows in sheets():
            lines = []
            for row in rows:
                cells = [text(v) for v in row]
                while cells and cells[-1] == "":
                    cells.pop()
                if cells:
                    lines.append(cells)
            if lines:
                sys.stdout.write("## Feuille : %s\n\n" % name)
                out.writerows(lines)
                sys.stdout.write("\n")
        PY;

    private string $projectDir;
    private string $binaryPath = '/usr/local/bin/amoxtli';

    public function __construct(
        KernelInterface $kernel,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
        $this->projectDir = $kernel->getProjectDir();
    }

    public function isAvailable(): bool
    {
        return is_file($this->binaryPath) && is_executable($this->binaryPath);
    }

    public function getWorkspacePath(string $id): string
    {
        return $this->projectDir.'/uploads/amoxtli/'.$this->checkMarketId($id);
    }

    public function getMarketPath(string $id): string
    {
        return $this->projectDir.'/uploads/boamp/'.$this->checkMarketId($id);
    }

    public function getBinaryPath(): string
    {
        return $this->binaryPath;
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    /**
     * Crée le workspace du marché s'il n'existe pas. Pendant une indexation du
     * marché, ne fait rien : l'indexation le crée elle-même, et une
     * reconstruction le supprime puis le recrée.
     */
    public function initWorkspace(string $id): void
    {
        if (!$this->isAvailable() || is_dir($this->getWorkspacePath($id).'/.amoxtli')) {
            return;
        }

        $lock = $this->createMarketLock($id);
        if (!$lock->acquire()) {
            return;
        }

        try {
            $this->createWorkspace($id);
        } finally {
            $lock->release();
        }
    }

    /**
     * Met à jour le corpus du marché puis l'index. Avec $rebuild, l'index et le
     * corpus sont d'abord supprimés.
     *
     * Un marché sans dossier de pièces n'a rien à indexer : un index existant
     * est vidé, sinon rien n'est créé.
     *
     * @return CorpusReport|null null si amoxtli est absent, ou si le marché n'a ni pièces ni index
     */
    public function indexMarket(string $id, bool $rebuild = false): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        // Le worker et app:amoxtli:reindex peuvent traiter le même marché en même temps.
        $lock = $this->createMarketLock($id);
        $lock->acquire(true);

        try {
            $fs = new Filesystem();
            $workspacePath = $this->getWorkspacePath($id);
            $corpusPath = $workspacePath.'/corpus';

            if ($rebuild) {
                $fs->remove([$workspacePath.'/.amoxtli', $corpusPath, $this->getReportPath($id)]);
            }

            if (!is_dir($this->getMarketPath($id)) && !is_dir($workspacePath.'/.amoxtli')) {
                return null;
            }

            $this->createWorkspace($id);
            $report = $this->buildCorpus($id);

            // amoxtli garde la version précédente d'un fichier qu'il ne sait
            // pas lire : retirée du corpus, l'entrée quitte l'index au sync suivant.
            while ([] !== $failed = $this->syncCorpus($workspacePath, $corpusPath, count($report['sources']))) {
                $removed = false;
                foreach ($failed as $entry => $error) {
                    $removed = $removed || is_file($corpusPath.'/'.$entry);
                    $fs->remove($corpusPath.'/'.$entry);
                    $report['failed'][] = [
                        'file' => $report['sources'][$entry] ?? $entry,
                        'reason' => sprintf('illisible par amoxtli (%s)', $error),
                    ];
                    unset($report['sources'][$entry]);
                }

                if (!$removed) {
                    break;
                }
            }

            // Un nom de pièce qui n'est pas en UTF-8 ne doit pas empêcher d'écrire le rapport.
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            // dumpFile() écrit puis renomme : la page du marché ne lit jamais un rapport à moitié écrit.
            $fs->dumpFile($this->getReportPath($id), $json);

            return $report;
        } finally {
            $lock->release();
        }
    }

    /**
     * .txt que l'ancienne indexation écrivait à côté de X.pdf et X.xlsx, à la
     * racine du dossier du marché. Comme ses glob(), elle ne traitait que les
     * extensions en minuscules. Un .txt déposé sous le même nom de base en
     * fait partie : il ne s'en distingue pas.
     *
     * @return array<string, string> .txt → pièce dont il était la conversion
     */
    public function findLegacyTxt(string $id): array
    {
        $marketPath = $this->getMarketPath($id);

        $legacy = [];
        foreach (glob($marketPath.'/*.txt') ?: [] as $path) {
            foreach (['pdf', 'xlsx'] as $extension) {
                $origin = substr($path, 0, -3).$extension;
                if (is_file($path) && is_file($origin)) {
                    $legacy[basename($path)] = basename($origin);
                    break;
                }
            }
        }

        return $legacy;
    }

    /**
     * Copie ou convertit dans le corpus les pièces nouvelles ou modifiées, et
     * supprime les entrées dont la pièce a disparu. Un échec de conversion
     * n'arrête pas le marché : la pièce est classée dans "failed", et n'est
     * retentée que si elle change (ou à la réindexation).
     *
     * À appeler sous le verrou du marché, donc seulement depuis indexMarket().
     *
     * @return CorpusReport
     */
    private function buildCorpus(string $id): array
    {
        $marketPath = $this->getMarketPath($id);
        $corpusPath = $this->getWorkspacePath($id).'/corpus';
        $fs = new Filesystem();
        $fs->mkdir($corpusPath, 0775);
        clearstatcache();

        // Le rapport précédent dit quelle version de chaque pièce le corpus contient.
        $previous = $this->readReport($id);
        $fingerprints = $previous['fingerprints'] ?? [];
        $failures = array_column($previous['failed'] ?? [], 'reason', 'file');

        $report = ['converted' => [], 'copied' => [], 'removed' => [], 'unsupported' => [], 'failed' => [], 'sources' => [], 'fingerprints' => []];
        $converters = $this->converters();

        foreach (is_dir($marketPath) ? $this->listFiles($marketPath) : [] as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $converter = $converters[$extension] ?? null;

            if (null !== $converter) {
                // L'extension d'origine reste dans le nom : devis.pdf et devis.xlsx ne se marchent pas dessus.
                $entry = $file.'.txt';
            } elseif (in_array($extension, self::NATIVE_EXTENSIONS, true)) {
                $entry = substr($file, 0, -strlen($extension)).$extension;
            } else {
                $report['unsupported'][] = $file;
                continue;
            }

            if (isset($report['sources'][$entry])) {
                $report['failed'][] = ['file' => $file, 'reason' => sprintf('même nom dans l\'index que %s', $report['sources'][$entry])];
                continue;
            }

            $report['sources'][$entry] = $file;
            $source = $marketPath.'/'.$file;
            $target = $corpusPath.'/'.$entry;

            // Date et taille, lues avant la conversion : une pièce encore en
            // cours d'écriture, ou remplacée dans la même seconde, change de
            // taille et sera refaite au passage suivant.
            $fingerprint = filemtime($source).':'.filesize($source);
            $report['fingerprints'][$file] = $fingerprint;
            $unchanged = ($fingerprints[$file] ?? null) === $fingerprint;

            if ($unchanged && isset($failures[$file])) {
                unset($report['sources'][$entry]);
                $report['failed'][] = ['file' => $file, 'reason' => $failures[$file]];
                continue;
            }

            if ($unchanged && is_file($target)) {
                continue;
            }

            try {
                if (null === $converter) {
                    $fs->copy($source, $target, true);
                    $report['copied'][] = $file;
                } else {
                    $text = $converter($source);
                    if (!preg_match('/\S/', $text)) {
                        throw new \RuntimeException('aucun texte extrait');
                    }
                    $fs->dumpFile($target, $text);
                    $report['converted'][] = $file;
                }
            } catch (\Throwable $e) {
                // Sans entrée attendue, une version précédente est retirée avec les orphelins.
                unset($report['sources'][$entry]);
                $report['failed'][] = ['file' => $file, 'reason' => $e->getMessage()];
                $this->logger->warning('amoxtli : pièce {file} du marché {id} non indexée : {reason}', [
                    'file' => $file,
                    'id' => $id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        foreach ($this->listFiles($corpusPath) as $entry) {
            if (!isset($report['sources'][$entry])) {
                $fs->remove($corpusPath.'/'.$entry);
                $report['removed'][] = $entry;
            }
        }

        return $report;
    }

    /**
     * @return array{available: bool, initialized: bool, documents: array<int, array{filename: ?string, extension: ?string, size: ?int, indexed_at: ?string, source: ?string, etag: ?string}>, unindexed: list<array{file: string, reason: string}>}
     */
    public function listIndexedDocuments(string $marketId): array
    {
        $result = [
            'available' => $this->isAvailable(),
            'initialized' => false,
            'documents' => [],
            'unindexed' => [],
        ];

        if (!$result['available']) {
            return $result;
        }

        $workspacePath = $this->getWorkspacePath($marketId);
        if (!is_dir($workspacePath.'/.amoxtli')) {
            return $result;
        }

        $result['initialized'] = true;

        $report = $this->readReport($marketId);
        foreach ($report['unsupported'] ?? [] as $file) {
            $result['unindexed'][] = ['file' => $file, 'reason' => 'format non pris en charge'];
        }
        foreach ($report['failed'] ?? [] as $failure) {
            $result['unindexed'][] = $failure;
        }

        // amoxtli pagine la liste (50 documents par défaut).
        $documents = [];
        for ($page = 1;; ++$page) {
            $process = new Process([
                $this->binaryPath,
                '--json',
                '-C', $workspacePath,
                'doc', 'list',
                '--limit', (string) self::DOC_LIST_PAGE_SIZE,
                '--page', (string) $page,
            ]);
            $process->setTimeout(30);

            try {
                $process->run();
            } catch (\Throwable) {
                return $result;
            }

            if (!$process->isSuccessful()) {
                return $result;
            }

            $data = json_decode($process->getOutput(), true);
            if (!is_array($data) || !isset($data['documents']) || !is_array($data['documents'])) {
                return $result;
            }

            array_push($documents, ...$data['documents']);
            if (count($data['documents']) < self::DOC_LIST_PAGE_SIZE || count($documents) >= (int) ($data['total'] ?? 0)) {
                break;
            }
        }

        $sources = $report['sources'] ?? [];
        $marketPath = $this->getMarketPath($marketId);

        $result['documents'] = array_map(function (array $doc) use ($sources, $marketPath): array {
            $meta = $doc['metadata'] ?? [];
            $source = $doc['source'] ?? null;
            $entry = $source ? $this->entryOf($source) : null;

            // Nom de la pièce déposée plutôt que celui de sa conversion (CCTP.pdf, pas CCTP.pdf.txt).
            $file = null !== $entry ? ($sources[$entry] ?? null) : null;
            if (null !== $file) {
                return [
                    'filename' => $file,
                    'extension' => strtolower(pathinfo($file, PATHINFO_EXTENSION)),
                    'size' => is_file($marketPath.'/'.$file) ? (int) filesize($marketPath.'/'.$file) : null,
                    'indexed_at' => $meta['indexed_at'] ?? ($doc['created_at'] ?? null),
                    'source' => $source,
                    'etag' => $doc['etag'] ?? null,
                ];
            }

            return [
                'filename' => $meta['filename'] ?? ($entry ? basename($entry) : null),
                'extension' => $meta['extension'] ?? null,
                'size' => isset($meta['size']) ? (int) $meta['size'] : null,
                'indexed_at' => $meta['indexed_at'] ?? ($doc['created_at'] ?? null),
                'source' => $source,
                'etag' => $doc['etag'] ?? null,
            ];
        }, $documents);

        return $result;
    }

    /**
     * Pièces déposées d'où viennent des résultats de recherche amoxtli : la
     * source d'un résultat est son entrée du corpus (file:///CCTP.pdf.txt
     * pour CCTP.pdf).
     *
     * @param list<string> $sources
     *
     * @return list<string> sans doublons
     */
    public function getPieceNames(string $id, array $sources): array
    {
        $pieces = $this->readReport($id)['sources'] ?? [];

        $names = [];
        foreach ($sources as $source) {
            $entry = $this->entryOf($source);
            $names[] = $pieces[$entry] ?? $entry;
        }

        return array_values(array_unique($names));
    }

    /**
     * Entrée du corpus d'une source amoxtli (file:///Cahier%20des%20charges.pdf.txt).
     */
    private function entryOf(string $source): string
    {
        return ltrim(rawurldecode(parse_url($source, PHP_URL_PATH) ?: ''), '/');
    }

    /**
     * L'id d'un upload vient de la requête : "../amoxtli/5" viserait le
     * workspace d'un autre marché.
     */
    private function checkMarketId(string $id): string
    {
        if (!ctype_digit($id)) {
            throw new \InvalidArgumentException(sprintf('Identifiant de marché invalide : "%s"', $id));
        }

        return $id;
    }

    private function createMarketLock(string $id): SharedLockInterface
    {
        return $this->lockFactory->createLock('amoxtli-market-'.$this->checkMarketId($id), 600);
    }

    /**
     * À appeler sous le verrou du marché.
     */
    private function createWorkspace(string $id): void
    {
        $workspacePath = $this->getWorkspacePath($id);

        if (is_dir($workspacePath.'/.amoxtli')) {
            return;
        }

        $fs = new Filesystem();
        try {
            $fs->mkdir($workspacePath, 0775);
        } catch (IOExceptionInterface $e) {
            throw new \RuntimeException(sprintf('Impossible de créer le répertoire %s : %s', $workspacePath, $e->getMessage()));
        }

        $process = new Process([$this->binaryPath, 'init'], $workspacePath);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            // Un .amoxtli à moitié créé passerait ensuite pour un workspace prêt.
            $fs->remove($workspacePath.'/.amoxtli');

            throw new ProcessFailedException($process);
        }

        $this->configureWorkspace($workspacePath);
    }

    private function getReportPath(string $id): string
    {
        return $this->getWorkspacePath($id).'/corpus-report.json';
    }

    /**
     * @return array{unsupported?: list<string>, failed?: list<array{file: string, reason: string}>, sources?: array<string, string>, fingerprints?: array<string, string>}
     */
    private function readReport(string $id): array
    {
        $path = $this->getReportPath($id);
        if (!is_file($path)) {
            return [];
        }

        $report = json_decode((string) file_get_contents($path), true);

        return is_array($report) ? $report : [];
    }

    /**
     * Synchronise le corpus. Sans --no-wait, amoxtli attend la fin de
     * l'indexation et donne le résultat de chaque fichier ; il sort en erreur
     * dès qu'un fichier échoue, ce qui ne doit pas faire échouer le marché.
     *
     * amoxtli abandonne lui-même un fichier au bout de SYNC_FILE_TIMEOUT : le
     * délai total ne coupe la synchro que si amoxtli ne rend plus la main.
     *
     * @return array<string, string> entrée du corpus → erreur amoxtli
     */
    private function syncCorpus(string $workspacePath, string $corpusPath, int $entries): array
    {
        $process = new Process([
            $this->binaryPath,
            '--json',
            '-C', $workspacePath,
            'sync',
            $corpusPath,
            '--base-dir', $corpusPath,
            '--timeout', self::SYNC_FILE_TIMEOUT.'s',
        ]);

        $process->setTimeout(60 + self::SYNC_FILE_TIMEOUT * max(1, $entries));
        $process->run();

        $data = json_decode($process->getOutput(), true);
        $failed = [];
        foreach (is_array($data['results'] ?? null) ? $data['results'] : [] as $result) {
            if ('failed' === ($result['action'] ?? null) && is_string($result['file'] ?? null)) {
                $failed[substr($result['file'], strlen($corpusPath) + 1)] = (string) ($result['error'] ?? 'erreur inconnue');
            }
        }

        if (!$process->isSuccessful() && [] === $failed) {
            throw new ProcessFailedException($process);
        }

        return $failed;
    }

    /**
     * Convertisseurs en texte, par extension en minuscules. Ils lèvent une
     * exception dont le message dit pourquoi la pièce n'est pas indexée.
     *
     * @return array<string, \Closure(string): string>
     */
    private function converters(): array
    {
        return [
            'pdf' => $this->pdfToText(...),
            'doc' => $this->docToText(...),
            'xlsx' => $this->spreadsheetToText(...),
            'xls' => $this->spreadsheetToText(...),
            'csv' => $this->csvToText(...),
        ];
    }

    private function pdfToText(string $file): string
    {
        $text = $this->run(['pdftotext', '-layout', $file, '-']);

        $chars = strlen((string) preg_replace('/\s+/', '', $text));
        if (0 === $chars || ($chars < self::PDF_MIN_CHARS && filesize($file) > self::PDF_SCAN_MIN_SIZE)) {
            throw new \RuntimeException('PDF image, OCR requis');
        }

        return $text;
    }

    private function docToText(string $file): string
    {
        // catdoc rend le texte le plus propre mais refuse certains .doc (ceux
        // écrits par LibreOffice) qu'antiword lit, tableaux en ASCII compris.
        try {
            // Sans -s cp1252, catdoc devine mal le jeu de caractères des .doc et abîme les accents.
            $text = $this->run(['catdoc', '-s', 'cp1252', '-d', 'utf-8', '-w', $file]);
        } catch (\RuntimeException) {
            $text = '';
        }

        if (!preg_match('/\S/', $text)) {
            $text = $this->run(['antiword', '-m', 'UTF-8.txt', '-w', '0', $file]);
        }

        // Codes des champs de formulaire Word (cases à cocher des AE, DC1, DC2…).
        return str_replace(['FORMCHECKBOX', 'FORMTEXT', 'FORMDROPDOWN'], '', $text);
    }

    private function spreadsheetToText(string $file): string
    {
        return $this->run(['python3', '-c', self::SPREADSHEET_TO_CSV, $file]);
    }

    private function csvToText(string $file): string
    {
        $text = (string) file_get_contents($file);

        // Excel exporte les CSV en Windows-1252.
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // Le message de Symfony reprend toute la ligne de commande, script Python compris.
            throw new \RuntimeException(sprintf('échec de %s (délai de %d s dépassé)', $command[0], $process->getTimeout()));
        }

        if (!$process->isSuccessful()) {
            $lines = preg_split('/\R/', trim($process->getErrorOutput())) ?: [];
            $error = trim((string) end($lines));

            throw new \RuntimeException(sprintf('échec de %s (%s)', $command[0], '' !== $error ? mb_substr($error, 0, 200) : 'code '.$process->getExitCode()));
        }

        return $process->getOutput();
    }

    /**
     * Chemins relatifs des fichiers de $dir, filtrés comme la liste du bundle
     * de fichiers : sans fichiers cachés ni dossiers préfixés "_" (_thumbs).
     *
     * @return list<string>
     */
    private function listFiles(string $dir): array
    {
        $finder = (new Finder())->files()->in($dir)->notPath('#(^|/)_[^/]*/#')->sortByName();

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getRelativePathname();
        }

        return $files;
    }

    private function configureWorkspace(string $workspacePath): void
    {
    }
}
