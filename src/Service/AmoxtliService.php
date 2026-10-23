<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Exception\ProcessFailedException;
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

    /**
     * Toutes les feuilles d'un classeur (.xlsx, .xls) → CSV, chacune précédée
     * de "## Feuille : <nom>", sans lignes vides ni cellules vides en fin de
     * ligne : la mise en forme d'un classeur peut étendre la plage utilisée à
     * des milliers de cellules vides (ex. 1031 colonnes, 2,8 Mo de "","",…
     * pour 170 Ko de .xlsx), que la recherche renvoie ensuite au LLM.
     */
    private const SPREADSHEET_TO_CSV = <<<'PY'
        import csv, sys

        path = sys.argv[1]
        out = csv.writer(sys.stdout, quoting=csv.QUOTE_ALL, lineterminator="\n")

        def sheets():
            if path.lower().endswith(".xls"):
                import xlrd
                for sheet in xlrd.open_workbook(path).sheets():
                    yield sheet.name, (sheet.row_values(i) for i in range(sheet.nrows))
            else:
                import openpyxl
                for sheet in openpyxl.load_workbook(path).worksheets:
                    yield sheet.title, sheet.values

        for name, rows in sheets():
            lines = []
            for row in rows:
                cells = ["" if v is None else v for v in row]
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
        return $this->projectDir.'/uploads/amoxtli/'.$id;
    }

    public function getMarketPath(string $id): string
    {
        return $this->projectDir.'/uploads/boamp/'.$id;
    }

    public function getBinaryPath(): string
    {
        return $this->binaryPath;
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function initWorkspace(string $id): void
    {
        if (!$this->isAvailable()) {
            return;
        }

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
            throw new ProcessFailedException($process);
        }

        $this->configureWorkspace($workspacePath);
    }

    /**
     * Met à jour le corpus du marché puis l'index. Avec $rebuild, l'index et le
     * corpus sont d'abord supprimés.
     *
     * @return CorpusReport|null null si amoxtli est absent ou si le marché n'a pas de dossier
     */
    public function indexMarket(string $id, bool $rebuild = false): ?array
    {
        if (!$this->isAvailable() || !is_dir($this->getMarketPath($id))) {
            return null;
        }

        // Le worker et app:amoxtli:reindex peuvent traiter le même marché en même temps.
        $lock = $this->lockFactory->createLock('amoxtli-market-'.$id, 600);
        $lock->acquire(true);

        try {
            $workspacePath = $this->getWorkspacePath($id);
            $corpusPath = $workspacePath.'/corpus';

            if ($rebuild) {
                (new Filesystem())->remove([$workspacePath.'/.amoxtli', $corpusPath, $this->getReportPath($id)]);
            }

            $this->initWorkspace($id);
            $report = $this->buildCorpus($id);

            foreach ($this->syncCorpus($workspacePath, $corpusPath) as $entry => $error) {
                $report['failed'][] = [
                    'file' => $report['sources'][$entry] ?? $entry,
                    'reason' => sprintf('illisible par amoxtli (%s)', $error),
                ];
            }

            file_put_contents($this->getReportPath($id), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $report;
        } finally {
            $lock->release();
        }
    }

    /**
     * Copie ou convertit dans le corpus les pièces nouvelles ou modifiées, et
     * supprime les entrées dont la pièce a disparu. Un échec de conversion
     * n'arrête pas le marché : la pièce est classée dans "failed".
     *
     * @return CorpusReport
     */
    public function buildCorpus(string $id): array
    {
        $marketPath = $this->getMarketPath($id);
        $corpusPath = $this->getWorkspacePath($id).'/corpus';
        $fs = new Filesystem();
        $fs->mkdir($corpusPath, 0775);
        clearstatcache();

        $report = ['converted' => [], 'copied' => [], 'removed' => [], 'unsupported' => [], 'failed' => [], 'sources' => []];
        $converters = $this->converters();

        foreach ($this->listFiles($marketPath) as $file) {
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

            // L'entrée porte la date de sa pièce : une pièce remplacée en change.
            if (is_file($target) && filemtime($target) === filemtime($source)) {
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
                touch($target, (int) filemtime($source));
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

        $process = new Process([
            $this->binaryPath,
            '--json',
            '-C', $workspacePath,
            'doc', 'list',
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

        $sources = $report['sources'] ?? [];
        $marketPath = $this->getMarketPath($marketId);

        $result['documents'] = array_map(function (array $doc) use ($sources, $marketPath): array {
            $meta = $doc['metadata'] ?? [];
            $source = $doc['source'] ?? null;
            $entry = $source ? ltrim(rawurldecode(parse_url($source, PHP_URL_PATH) ?: ''), '/') : null;

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
        }, $data['documents']);

        return $result;
    }

    private function getReportPath(string $id): string
    {
        return $this->getWorkspacePath($id).'/corpus-report.json';
    }

    /**
     * @return array{unsupported?: list<string>, failed?: list<array{file: string, reason: string}>, sources?: array<string, string>}
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
     * @return array<string, string> entrée du corpus → erreur amoxtli
     */
    private function syncCorpus(string $workspacePath, string $corpusPath): array
    {
        $process = new Process([
            $this->binaryPath,
            '--json',
            '-C', $workspacePath,
            'sync',
            $corpusPath,
            '--base-dir', $corpusPath,
        ]);

        $process->setTimeout(120);
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
        $process->run();

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
