<?php

namespace App\Tests\Service;

use App\Service\AmoxtliService;
use Dompdf\Dompdf;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Process;

/**
 * Test d'intégration : il appelle amoxtli, pdftotext, catdoc et python3, donc
 * à lancer dans le conteneur. Le marché 1 vit dans un projet temporaire.
 *
 * @phpstan-import-type CorpusReport from AmoxtliService
 */
class AmoxtliServiceTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/amoxtli';

    private string $projectDir;
    private string $marketPath;
    private string $corpusPath;
    private LockFactory $lockFactory;
    private AmoxtliService $service;

    protected function setUp(): void
    {
        if (!is_executable('/usr/local/bin/amoxtli')) {
            $this->markTestSkipped('amoxtli absent : test à lancer dans le conteneur.');
        }

        $this->projectDir = sys_get_temp_dir().'/amoxtli-test-'.bin2hex(random_bytes(4));
        $this->marketPath = $this->projectDir.'/uploads/boamp/1';
        $this->corpusPath = $this->projectDir.'/uploads/amoxtli/1/corpus';
        (new Filesystem())->mkdir($this->marketPath);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir);

        $this->lockFactory = new LockFactory(new InMemoryStore());
        $this->service = new AmoxtliService($kernel, $this->lockFactory, new NullLogger());
    }

    protected function tearDown(): void
    {
        if (isset($this->projectDir)) {
            (new Filesystem())->remove($this->projectDir);
        }
    }

    public function testReplacedPdfIsReconverted(): void
    {
        $this->pdf('CCAP.pdf', 'Clause ancienneclause du marché.');
        $this->index();
        $this->assertSame(['CCAP.pdf.txt'], $this->search('ancienneclause'));

        $this->pdf('CCAP.pdf', 'Clause nouvelleclause du marché.');
        $this->replacedLater('CCAP.pdf');
        $report = $this->index();

        $this->assertSame(['CCAP.pdf'], $report['converted']);
        $this->assertSame(['CCAP.pdf.txt'], $this->search('nouvelleclause'));
        $this->assertSame([], $this->search('ancienneclause'));
    }

    public function testUnchangedPiecesAreNotConvertedAgain(): void
    {
        $this->pdf('CCAP.pdf', 'Clause stableclause.');
        file_put_contents($this->marketPath.'/notes.txt', 'Notes stablenote.');
        $this->index();

        $report = $this->index();

        $this->assertSame([], $report['converted']);
        $this->assertSame([], $report['copied']);
        $this->assertSame(['CCAP.pdf', 'notes.txt'], $this->indexedFiles());
    }

    public function testDeletedPdfLeavesTheIndex(): void
    {
        $this->pdf('CCAP.pdf', 'Clause disparueclause.');
        $this->index();
        $this->assertSame(['CCAP.pdf'], $this->indexedFiles());

        unlink($this->marketPath.'/CCAP.pdf');
        $report = $this->index();

        $this->assertSame(['CCAP.pdf.txt'], $report['removed']);
        $this->assertSame([], $this->indexedFiles());
        $this->assertSame([], $this->search('disparueclause'));
    }

    public function testCorpusCoversEveryUploadedFileWithoutTouchingThem(): void
    {
        $this->pdf('devis.pdf', 'Devis pdfdevis.');
        copy(self::FIXTURES.'/deux-feuilles.xlsx', $this->marketPath.'/devis.xlsx');
        file_put_contents($this->marketPath.'/devis.txt', 'Devis txtdevis.');
        $this->pdf('MAJUSCULE.PDF', 'Majuscule majusculepdf.');
        file_put_contents($this->marketPath.'/NOTES.TXT', 'Notes majusculetxt.');
        mkdir($this->marketPath.'/sous-dossier');
        $this->pdf('sous-dossier/annexe.pdf', 'Annexe sousdossierpdf.');
        mkdir($this->marketPath.'/_thumbs');
        $this->pdf('_thumbs/vignette.pdf', 'Vignette vignettepdf.');
        file_put_contents($this->marketPath.'/dce.zip', 'PK');
        $uploaded = $this->files($this->marketPath);

        $report = $this->index();

        $this->assertSame(
            ['MAJUSCULE.PDF', 'NOTES.TXT', 'devis.pdf', 'devis.txt', 'devis.xlsx', 'sous-dossier/annexe.pdf'],
            $this->indexedFiles(),
        );
        $this->assertSame(['dce.zip'], $report['unsupported']);
        $this->assertSame([], $report['failed']);
        $this->assertSame(['devis.pdf.txt'], $this->search('pdfdevis'));
        $this->assertSame(['devis.txt'], $this->search('txtdevis'));
        $this->assertSame(['MAJUSCULE.PDF.txt'], $this->search('majusculepdf'));
        $this->assertSame(['NOTES.txt'], $this->search('majusculetxt'));
        $this->assertSame(['sous-dossier/annexe.pdf.txt'], $this->search('sousdossierpdf'));
        $this->assertSame([], $this->search('vignettepdf'));
        $this->assertSame($uploaded, $this->files($this->marketPath));
    }

    public function testSpreadsheetsKeepEverySheet(): void
    {
        copy(self::FIXTURES.'/deux-feuilles.xlsx', $this->marketPath.'/bpu.xlsx');
        copy(self::FIXTURES.'/deux-feuilles.xls', $this->marketPath.'/dqe.xls');

        $report = $this->index();

        $this->assertSame(['bpu.xlsx', 'dqe.xls'], $report['converted']);
        foreach (['bpu.xlsx.txt', 'dqe.xls.txt'] as $entry) {
            $text = (string) file_get_contents($this->corpusPath.'/'.$entry);
            $this->assertStringContainsString('## Feuille : BPU', $text);
            $this->assertStringContainsString('## Feuille : DQE', $text);
        }
        $this->assertSame(['bpu.xlsx.txt', 'dqe.xls.txt'], $this->search('secondfeuillet'));
    }

    public function testSpreadsheetsGiveTheDisplayedValues(): void
    {
        // 12 × 10,1 vaut 121.19999999999999 en flottant ; la date est stockée en numéro de série.
        copy(self::FIXTURES.'/formules.xlsx', $this->marketPath.'/bpu.xlsx');
        copy(self::FIXTURES.'/formules.xls', $this->marketPath.'/dqe.xls');

        $this->index();

        foreach (['bpu.xlsx.txt', 'dqe.xls.txt'] as $entry) {
            $text = (string) file_get_contents($this->corpusPath.'/'.$entry);
            $this->assertStringContainsString('"Prestation","12","10.1","121.2","2026-01-15"', $text, $entry);
            $this->assertStringContainsString('"Total","","","121.2"', $text, $entry);
        }
    }

    public function testDocIsConverted(): void
    {
        copy(self::FIXTURES.'/acte.doc', $this->marketPath.'/AE.doc');

        $report = $this->index();

        $this->assertSame(['AE.doc'], $report['converted']);
        $this->assertStringContainsString('exécuter', (string) file_get_contents($this->corpusPath.'/AE.doc.txt'));
        $this->assertSame(['AE.doc.txt'], $this->search('quebecdoc'));
    }

    public function testUnreadablePiecesAreReportedWithoutFailingTheMarket(): void
    {
        $this->pdf('scan.pdf', '');
        file_put_contents($this->marketPath.'/casse.docx', 'pas un zip');
        file_put_contents($this->marketPath.'/notes.txt', 'Notes lisiblenote.');

        $report = $this->index();

        $reasons = array_column($report['failed'], 'reason', 'file');
        $this->assertSame('PDF image, OCR requis', $reasons['scan.pdf']);
        $this->assertStringStartsWith('illisible par amoxtli', $reasons['casse.docx']);
        $this->assertSame(['notes.txt'], $this->indexedFiles());

        $unindexed = array_column($this->service->listIndexedDocuments('1')['unindexed'], 'file');
        sort($unindexed);
        $this->assertSame(['casse.docx', 'scan.pdf'], $unindexed);
    }

    public function testPdfReplacedByAScanLeavesTheIndex(): void
    {
        $this->pdf('CCTP.pdf', 'Clause texteclause.');
        $this->index();

        $this->pdf('CCTP.pdf', '');
        $this->replacedLater('CCTP.pdf');
        $report = $this->index();

        $this->assertSame([['file' => 'CCTP.pdf', 'reason' => 'PDF image, OCR requis']], $report['failed']);
        $this->assertSame([], $this->search('texteclause'));
    }

    public function testDocxReplacedByAnUnreadableOneLeavesTheIndex(): void
    {
        $this->docx('CCTP.docx', 'Clause lisibleclause.');
        $this->index();
        $this->assertSame(['CCTP.docx'], $this->search('lisibleclause'));

        // amoxtli garderait l'ancienne version d'un fichier qu'il ne sait plus lire.
        file_put_contents($this->marketPath.'/CCTP.docx', 'pas un zip');
        $this->replacedLater('CCTP.docx');
        $report = $this->index();

        $this->assertStringStartsWith('illisible par amoxtli', array_column($report['failed'], 'reason', 'file')['CCTP.docx']);
        $this->assertSame([], $this->search('lisibleclause'));
        $this->assertSame([], $this->indexedFiles());
    }

    public function testUnchangedFailedPieceIsNotRetried(): void
    {
        file_put_contents($this->marketPath.'/vide.csv', str_repeat(' ', 10));
        $mtime = (int) filemtime($this->marketPath.'/vide.csv');
        $first = $this->index();
        $this->assertSame([['file' => 'vide.csv', 'reason' => 'aucun texte extrait']], $first['failed']);

        // Même taille et même date : la pièce passe pour inchangée, et n'est pas retentée.
        file_put_contents($this->marketPath.'/vide.csv', 'lisiblecsv');
        touch($this->marketPath.'/vide.csv', $mtime);
        $this->assertSame($first['failed'], $this->index()['failed']);

        // La réindexation la retente.
        $report = $this->index(true);
        $this->assertSame(['vide.csv'], $report['converted']);
        $this->assertSame([], $report['failed']);
    }

    public function testPieceRejectedByAmoxtliIsOnlyReportedAsFailed(): void
    {
        file_put_contents($this->marketPath.'/casse.docx', 'pas un zip');

        $report = $this->index();

        $this->assertSame([], $report['copied']);
        $this->assertCount(1, $report['failed']);
        $this->assertStringStartsWith('illisible par amoxtli (exit status', $report['failed'][0]['reason']);
        // Refusée par le convertisseur d'amoxtli : pas retentée tant qu'elle ne change pas.
        $this->assertArrayNotHasKey('retry', $report['failed'][0]);
        // Ni laissée dans le corpus où amoxtli la retenterait.
        $this->assertFileDoesNotExist($this->corpusPath.'/casse.docx');
    }

    public function testUnreadableSpreadsheetIsNotRetried(): void
    {
        file_put_contents($this->marketPath.'/casse.xlsx', 'pas un zip');

        $failure = $this->index()['failed'][0];

        $this->assertStringStartsWith('échec de python3', $failure['reason']);
        $this->assertArrayNotHasKey('retry', $failure);
        $this->assertSame([], $this->index()['converted']);
    }

    public function testFailureUnrelatedToThePieceIsRetried(): void
    {
        file_put_contents($this->marketPath.'/notes.txt', 'Notes.');
        $this->index();

        // Le passage précédent a dépassé un délai : la pièce n'a pas changé depuis.
        $reportPath = $this->projectDir.'/uploads/amoxtli/1/corpus-report.json';
        $previous = json_decode((string) file_get_contents($reportPath), true);
        $previous['failed'] = [['file' => 'notes.txt', 'reason' => 'échec de python3 (délai de 60 s dépassé)', 'retry' => true]];
        unset($previous['sources']['notes.txt']);
        file_put_contents($reportPath, json_encode($previous));
        unlink($this->corpusPath.'/notes.txt');

        $report = $this->index();

        $this->assertSame(['notes.txt'], $report['copied']);
        $this->assertSame([], $report['failed']);
    }

    public function testPieceRewrittenWithinTheSameSecondIsReindexed(): void
    {
        // Pièce indexée pendant que l'upload l'écrit encore.
        file_put_contents($this->marketPath.'/notes.txt', 'Notes tronq');
        $mtime = (int) filemtime($this->marketPath.'/notes.txt');
        $this->index();

        file_put_contents($this->marketPath.'/notes.txt', 'Notes tronquées completenote.');
        touch($this->marketPath.'/notes.txt', $mtime);
        $report = $this->index();

        $this->assertSame(['notes.txt'], $report['copied']);
        $this->assertSame(['notes.txt'], $this->search('completenote'));
    }

    public function testSearchSourcesGiveTheUploadedPieces(): void
    {
        $this->pdf('Cahier des charges.pdf', 'Clause sourceclause.');
        mkdir($this->marketPath.'/annexes');
        file_put_contents($this->marketPath.'/annexes/NOTES.TXT', 'Notes sourceclause.');
        $this->index();

        $sources = $this->searchSources('sourceclause');
        sort($sources);
        $this->assertSame(['file:///Cahier%20des%20charges.pdf.txt', 'file:///annexes/NOTES.txt'], $sources);

        $this->assertSame(
            ['Cahier des charges.pdf', 'annexes/NOTES.TXT', 'inconnu.txt'],
            $this->service->getPieceNames('1', [...$sources, 'file:///inconnu.txt', $sources[0]]),
        );
    }

    public function testMarketIdIsAPlainNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->indexMarket('../amoxtli/1');
    }

    public function testWorkspaceIsLeftToARunningIndexing(): void
    {
        $lock = $this->lockFactory->createLock('amoxtli-market-1');
        $lock->acquire();

        $this->service->initWorkspace('1');
        $this->assertDirectoryDoesNotExist($this->projectDir.'/uploads/amoxtli/1/.amoxtli');

        $lock->release();
        $this->service->initWorkspace('1');
        $this->assertDirectoryExists($this->projectDir.'/uploads/amoxtli/1/.amoxtli');
    }

    public function testIndexingWaitsForTheWorkspace(): void
    {
        file_put_contents($this->marketPath.'/notes.txt', 'Notes premierenote.');
        $this->index();

        file_put_contents($this->marketPath.'/ajout.txt', 'Ajout attentenote.');
        $holder = $this->holdWorkspace(2);
        $report = $this->index();
        $holder->wait();

        $this->assertSame([], $report['failed']);
        $this->assertSame(['ajout.txt'], $this->search('attentenote'));
    }

    public function testListingWaitsForTheWorkspace(): void
    {
        file_put_contents($this->marketPath.'/notes.txt', 'Notes.');
        $this->index();

        $holder = $this->holdWorkspace(2);
        $documents = $this->service->listIndexedDocuments('1')['documents'];
        $holder->wait();

        $this->assertSame(['notes.txt'], array_column($documents, 'filename'));
    }

    public function testRebuildStartsFromScratch(): void
    {
        $this->pdf('CCAP.pdf', 'Clause reconstruiteclause.');
        $this->index();

        $report = $this->index(true);

        $this->assertSame(['CCAP.pdf'], $report['converted']);
        $this->assertSame(['CCAP.pdf.txt'], $this->search('reconstruiteclause'));
    }

    public function testMarketIdsComeFromPiecesAndWorkspaces(): void
    {
        $fs = new Filesystem();
        $fs->mkdir([
            $this->projectDir.'/uploads/boamp/12',
            $this->projectDir.'/uploads/boamp/avatar',
            $this->projectDir.'/uploads/amoxtli/3',
            $this->projectDir.'/uploads/amoxtli/12',
        ]);

        $this->assertSame(['1', '3', '12'], $this->service->listMarketIds());
    }

    public function testFindLegacyTxt(): void
    {
        foreach (['CCTP.pdf', 'CCTP.txt', 'BPU.xlsx', 'BPU.txt', 'Offre.PDF', 'Offre.txt', 'notes.txt'] as $file) {
            touch($this->marketPath.'/'.$file);
        }
        // L'ancienne indexation ne parcourait que la racine du dossier.
        mkdir($this->marketPath.'/sous-dossier');
        touch($this->marketPath.'/sous-dossier/annexe.pdf');
        touch($this->marketPath.'/sous-dossier/annexe.txt');

        $this->assertSame(['BPU.txt' => 'BPU.xlsx', 'CCTP.txt' => 'CCTP.pdf'], $this->service->findLegacyTxt('1'));
    }

    public function testMarketWithoutPiecesFolderLeavesTheIndex(): void
    {
        $this->pdf('CCAP.pdf', 'Clause dossiersupprime.');
        $this->index();

        (new Filesystem())->remove($this->marketPath);
        $report = $this->index();

        $this->assertSame(['CCAP.pdf.txt'], $report['removed']);
        $this->assertSame([], $this->search('dossiersupprime'));

        $this->assertNull($this->service->indexMarket('1', true));
        $this->assertDirectoryDoesNotExist($this->projectDir.'/uploads/amoxtli/1/.amoxtli');
    }

    public function testMarketWithoutPiecesNorIndexIsLeftAlone(): void
    {
        $this->assertNull($this->service->indexMarket('2'));
        $this->assertDirectoryDoesNotExist($this->projectDir.'/uploads/amoxtli/2');
    }

    public function testFileNameNotInUtf8IsReported(): void
    {
        file_put_contents($this->marketPath."/\xE9t\xE9.zip", 'PK');
        file_put_contents($this->marketPath.'/notes.txt', 'Notes latinnote.');

        $this->index();

        $this->assertSame(['été.zip'], array_column($this->service->listIndexedDocuments('1')['unindexed'], 'file'));
    }

    public function testPieceNamedInLatin1IsConvertedOnce(): void
    {
        file_put_contents($this->marketPath."/\xE9t\xE9.csv", "Lot;Prix\nlatinpiece;12\n");

        $this->assertSame(['été.csv'], $this->index()['converted']);
        $this->assertSame([], $this->index()['converted']);

        $this->assertSame(['été.csv.txt'], $this->search('latinpiece'));
        $this->assertSame(['été.csv'], $this->indexedFiles());
    }

    public function testUnreadableListingIsNotAnEmptyIndex(): void
    {
        file_put_contents($this->marketPath.'/notes.txt', 'Notes.');
        $this->index();

        file_put_contents($this->projectDir.'/uploads/amoxtli/1/.amoxtli/config.yaml', 'version: [');
        $result = $this->service->listIndexedDocuments('1');

        $this->assertTrue($result['initialized']);
        $this->assertSame([], $result['documents']);
        $this->assertStringStartsWith('amoxtli doc list : error: could not load', (string) $result['error']);
    }

    public function testEveryDocumentIsListed(): void
    {
        // amoxtli doc list s'arrête à 50 documents par page par défaut.
        for ($i = 1; $i <= 51; ++$i) {
            file_put_contents(sprintf('%s/piece-%02d.txt', $this->marketPath, $i), 'Pièce '.$i);
        }

        $this->index();

        $this->assertCount(51, $this->indexedFiles());
    }

    /**
     * @return CorpusReport
     */
    private function index(bool $rebuild = false): array
    {
        $report = $this->service->indexMarket('1', $rebuild);
        if (null === $report) {
            $this->fail('indexMarket() n\'a pas indexé le marché.');
        }

        return $report;
    }

    private function pdf(string $file, string $text): void
    {
        $dompdf = new Dompdf();
        $dompdf->loadHtml('<p>'.htmlspecialchars($text).'</p>');
        $dompdf->render();
        file_put_contents($this->marketPath.'/'.$file, $dompdf->output());
    }

    private function docx(string $file, string $text): void
    {
        $process = new Process(['pandoc', '-f', 'markdown', '-o', $this->marketPath.'/'.$file]);
        $process->setInput($text);
        $process->mustRun();
    }

    /**
     * Fait tenir le workspace par un autre processus amoxtli pendant
     * $seconds secondes, comme une recherche ou une indexation en cours.
     */
    private function holdWorkspace(int $seconds): Process
    {
        $workspacePath = $this->projectDir.'/uploads/amoxtli/1';
        $holder = Process::fromShellCommandline(sprintf('sleep %d | /usr/local/bin/amoxtli -C "$WORKSPACE" mcp', $seconds), null, ['WORKSPACE' => $workspacePath]);
        $holder->start();

        // Le workspace doit être pris avant que le test ne s'en serve ; une
        // sonde lancée trop tôt le prendrait elle-même.
        $probe = new Process(['/usr/local/bin/amoxtli', '-C', $workspacePath, 'doc', 'list']);
        for ($i = 0; $i < 20 && $holder->isRunning(); ++$i) {
            usleep(100_000);
            $probe->run();
            if (str_contains($probe->getErrorOutput(), 'workspace is already in use')) {
                return $holder;
            }
        }

        $this->fail('Le workspace n\'a pas été pris par l\'autre processus amoxtli : '.$holder->getErrorOutput());
    }

    /**
     * Un nouvel upload donne une date récente à la pièce ; dans un test, il
     * tombe souvent dans la même seconde que le précédent.
     */
    private function replacedLater(string $file): void
    {
        touch($this->marketPath.'/'.$file, time() + 60);
    }

    /**
     * @return list<string> entrées du corpus dont le texte contient $word
     */
    private function search(string $word): array
    {
        $entries = [];
        foreach ($this->searchSources($word) as $source) {
            $entries[] = ltrim(rawurldecode((string) parse_url($source, PHP_URL_PATH)), '/');
        }
        sort($entries);

        return $entries;
    }

    /**
     * @return list<string> sources amoxtli des résultats (file:///…)
     */
    private function searchSources(string $word): array
    {
        $process = new Process(['/usr/local/bin/amoxtli', '--json', '-C', $this->projectDir.'/uploads/amoxtli/1', 'search', $word]);
        $process->mustRun();

        return array_column(json_decode($process->getOutput(), true)['results'] ?? [], 'source');
    }

    /**
     * @return list<string> pièces indexées, sous leur nom d'origine
     */
    private function indexedFiles(): array
    {
        $files = array_map('strval', array_column($this->service->listIndexedDocuments('1')['documents'], 'filename'));
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function files(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = substr($file->getPathname(), strlen($dir) + 1);
        }
        sort($files);

        return $files;
    }
}
