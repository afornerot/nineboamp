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

        $this->service = new AmoxtliService($kernel, new LockFactory(new InMemoryStore()), new NullLogger());
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

    public function testRebuildStartsFromScratch(): void
    {
        $this->pdf('CCAP.pdf', 'Clause reconstruiteclause.');
        $this->index();

        $report = $this->index(true);

        $this->assertSame(['CCAP.pdf'], $report['converted']);
        $this->assertSame(['CCAP.pdf.txt'], $this->search('reconstruiteclause'));
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
        $process = new Process(['/usr/local/bin/amoxtli', '--json', '-C', $this->projectDir.'/uploads/amoxtli/1', 'search', $word]);
        $process->mustRun();

        $entries = [];
        foreach (json_decode($process->getOutput(), true)['results'] ?? [] as $result) {
            $entries[] = ltrim(rawurldecode((string) parse_url($result['source'], PHP_URL_PATH)), '/');
        }
        sort($entries);

        return $entries;
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
