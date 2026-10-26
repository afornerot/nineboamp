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

    public function testDocSavedInAnotherFormatIsReadInThatFormat(): void
    {
        // Enregistrés au format Word 2007+, en RTF ou en page web sous l'extension .doc : antiword les refuse.
        $this->docx('AE.docx', "# Acte\n\nCorps ooxmldoc.");
        rename($this->marketPath.'/AE.docx', $this->marketPath.'/AE.doc');
        $process = new Process(['pandoc', '-f', 'markdown', '-s', '-t', 'rtf', '-o', $this->marketPath.'/DC1.doc']);
        $process->setInput("# Lettre\n\nCorps rtfdoc.");
        $process->mustRun();
        file_put_contents($this->marketPath.'/DC2.doc', '<html xmlns:w="urn:schemas-microsoft-com:office:word"><body><h1>Déclaration</h1><p>Corps htmldoc.</p></body></html>');
        // Page web à fichier unique : la page en quoted-printable et en Windows-1252, suivie de ses images.
        file_put_contents($this->marketPath.'/DC4.doc', implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: multipart/related; boundary="----=_NextPart_01"',
            '',
            '------=_NextPart_01',
            'Content-Transfer-Encoding: quoted-printable',
            'Content-Type: text/html; charset="windows-1252"',
            '',
            '<html><body><h1>Engagement</h1><p class=3DMsoNormal>Corps mhtml=',
            'doc r=E9ponse.</p></body></html>',
            '',
            '------=_NextPart_01',
            'Content-Transfer-Encoding: base64',
            'Content-Type: image/png',
            '',
            'iVBORw0KGgo=',
            '',
            '------=_NextPart_01--',
            '',
        ]));

        $report = $this->index();

        $this->assertSame(['AE.doc', 'DC1.doc', 'DC2.doc'], $report['copied']);
        $this->assertSame(['DC4.doc'], $report['converted']);
        $this->assertSame(['AE.doc', 'DC1.doc', 'DC2.doc', 'DC4.doc'], $this->indexedFiles());
        $this->assertSame(["# Acte\n\nCorps ooxmldoc."], $this->searchSections('ooxmldoc'));
        $this->assertSame(["# Lettre\n\nCorps rtfdoc."], $this->searchSections('rtfdoc'));
        $this->assertSame(["# Déclaration\n\nCorps htmldoc."], $this->searchSections('htmldoc'));
        $this->assertSame(["# Engagement\n\nCorps mhtmldoc réponse."], $this->searchSections('mhtmldoc'));
    }

    public function testSpreadsheetUnderTheOtherExtensionIsConverted(): void
    {
        copy(self::FIXTURES.'/deux-feuilles.xls', $this->marketPath.'/bpu.xlsx');
        copy(self::FIXTURES.'/deux-feuilles.xlsx', $this->marketPath.'/dqe.xls');

        $report = $this->index();

        $this->assertSame(['bpu.xlsx', 'dqe.xls'], $report['converted']);
        $this->assertSame(['bpu.xlsx.txt', 'dqe.xls.txt'], $this->search('secondfeuillet'));
    }

    public function testOldXlsWithoutOle2ContainerIsConverted(): void
    {
        // Un .xls BIFF2 (Excel 2) : des enregistrements à la suite, sans document OLE2 autour.
        $record = fn (int $id, string $data): string => pack('vv', $id, strlen($data)).$data;
        file_put_contents($this->marketPath.'/dqe.xls', $record(0x0009, pack('vv', 0x0002, 0x0010))
            .$record(0x0004, pack('vv', 0, 0)."\0\0\0".chr(8).'biffdeux')
            .$record(0x000A, ''));

        $report = $this->index();

        $this->assertSame(['dqe.xls'], $report['converted']);
        $this->assertSame(['dqe.xls.txt'], $this->search('biffdeux'));
        $this->assertSame("## Feuille : Sheet 1\n\n\"biffdeux\"\n\n", file_get_contents($this->corpusPath.'/dqe.xls.txt'));
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

    public function testDocumentThatCrashesAmoxtliIsIndexedAsText(): void
    {
        $this->docxThatCrashesAmoxtli('DC2.docx', 'Corps repliclause.');

        $report = $this->index();

        $this->assertSame([], $report['failed']);
        $this->assertSame(['DC2.docx'], $report['converted']);
        $this->assertSame(['DC2.docx.txt'], $this->search('repliclause'));
        $this->assertSame(['DC2.docx'], $this->indexedFiles());

        // Inchangée, elle garde son texte sans repasser par amoxtli.
        $report = $this->index();
        $this->assertSame([], $report['copied']);
        $this->assertSame([], $report['converted']);
        $this->assertSame([], $report['removed']);
        $this->assertSame(['DC2.docx.txt'], $this->search('repliclause'));
    }

    public function testCrashWithoutPandocTextIsRetried(): void
    {
        $this->docxThatCrashesAmoxtli('DC2.docx', 'Corps repliclause.');
        // pandoc introuvable pour le texte brut (code 127), alors qu'amoxtli lit les .docx avec lui.
        $bin = $this->projectDir.'/bin';
        (new Filesystem())->mkdir($bin);
        file_put_contents($bin.'/pandoc', "#!/bin/sh\ncase \" \$* \" in *' -t plain '*) exit 127 ;; esac\nexec /usr/bin/pandoc \"\$@\"\n");
        chmod($bin.'/pandoc', 0755);
        $path = (string) getenv('PATH');
        putenv('PATH='.$bin.':'.$path);
        try {
            $failure = $this->index()['failed'][0];
        } finally {
            putenv('PATH='.$path);
        }

        $this->assertMatchesRegularExpression('/^illisible par amoxtli \(runtime error: slice bounds out of range .* ; échec de pandoc \(code 127\)\)$/', $failure['reason']);
        $this->assertTrue($failure['retry'] ?? false);

        // Retentée au passage suivant, avec pandoc.
        $this->assertSame(['DC2.docx'], $this->index()['converted']);
        $this->assertSame(['DC2.docx.txt'], $this->search('repliclause'));
    }

    public function testFallbackIsDroppedWhenThePieceChanges(): void
    {
        $this->docxThatCrashesAmoxtli('DC2.docx', 'Corps repliclause.');
        $this->index();

        $this->docx('DC2.docx', 'Corps lisibleclause.');
        $this->replacedLater('DC2.docx');
        $report = $this->index();

        $this->assertSame(['DC2.docx'], $report['copied']);
        $this->assertSame([], $report['converted']);
        $this->assertSame(['DC2.docx.txt'], $report['removed']);
        $this->assertSame([], $report['fallbacks']);
        $this->assertSame(['DC2.docx'], $this->search('lisibleclause'));
        $this->assertSame([], $this->search('repliclause'));
    }

    public function testCrashDuringSyncFallsBackOnText(): void
    {
        $this->docx('DC2.docx', 'Corps lisibleclause.');
        $this->index();

        // La pièce n'a pas changé, mais sa copie fait maintenant planter
        // amoxtli : seul le sync la relit (ex. amoxtli mis à jour, workspace recréé).
        $this->docxThatCrashesAmoxtli('copie.docx', 'Corps repliclause.');
        rename($this->marketPath.'/copie.docx', $this->corpusPath.'/DC2.docx');
        $report = $this->index();

        $this->assertSame([], $report['failed']);
        $this->assertSame(['DC2.docx'], $report['converted']);
        $this->assertSame(['DC2.docx.txt'], $this->search('repliclause'));
        $this->assertSame(['DC2.docx'], $this->indexedFiles());
    }

    public function testFallbackIsRedoneWhenAmoxtliChanges(): void
    {
        $this->docxThatCrashesAmoxtli('DC2.docx', 'Corps repliclause.');
        $this->index();

        // Le texte de repli vient d'une autre version d'amoxtli, qui a peut-être été corrigée depuis.
        $reportPath = $this->projectDir.'/uploads/amoxtli/1/corpus-report.json';
        $previous = json_decode((string) file_get_contents($reportPath), true);
        $this->assertStringStartsWith('amoxtli version ', $previous['fallbacks']['DC2.docx']);
        $previous['fallbacks']['DC2.docx'] = 'amoxtli version 0.17.2';
        file_put_contents($reportPath, json_encode($previous));

        $report = $this->index();

        $this->assertSame(['DC2.docx'], $report['converted']);
        $this->assertNotSame('amoxtli version 0.17.2', $report['fallbacks']['DC2.docx']);
        $this->assertSame(['DC2.docx.txt'], $this->search('repliclause'));
    }

    public function testPieceNamedLikeAFallbackTakesItsPlace(): void
    {
        $this->docxThatCrashesAmoxtli('DC2.docx', 'Corps repliclause.');
        $this->index();

        file_put_contents($this->marketPath.'/DC2.docx.txt', 'Notes deposeclause.');
        $report = $this->index();

        $this->assertSame(['DC2.docx.txt'], $report['copied']);
        $this->assertSame(['DC2.docx.txt'], $this->search('deposeclause'));
        $this->assertSame([], $this->search('repliclause'));
        $this->assertSame(['DC2.docx.txt'], $this->indexedFiles());
        $this->assertCount(1, $report['failed']);
        $this->assertSame('DC2.docx', $report['failed'][0]['file']);
        $this->assertStringStartsWith('illisible par amoxtli (runtime error: slice bounds out of range', $report['failed'][0]['reason']);
        $this->assertArrayNotHasKey('retry', $report['failed'][0]);
    }

    public function testUnreadableSpreadsheetIsNotRetried(): void
    {
        file_put_contents($this->marketPath.'/casse.xlsx', 'pas un zip');

        $failure = $this->index()['failed'][0];

        $this->assertSame('échec de python3 (BadZipFile: File is not a zip file)', $failure['reason']);
        $this->assertArrayNotHasKey('retry', $failure);
        $this->assertSame([], $this->index()['converted']);
    }

    public function testSpreadsheetFailureGivesItsCause(): void
    {
        // openpyxl enveloppe l'erreur d'un style inconnu dans un message qui
        // finit par "Please see the exception for more details.".
        copy(self::FIXTURES.'/deux-feuilles.xlsx', $this->marketPath.'/bpu.xlsx');
        $zip = new \ZipArchive();
        $zip->open($this->marketPath.'/bpu.xlsx');
        $zip->addFromString('xl/styles.xml', str_replace('<left/>', '<left style="inconnu"/>', (string) $zip->getFromName('xl/styles.xml')));
        $zip->close();

        $failure = $this->index()['failed'][0];

        $this->assertStringStartsWith('échec de python3 (ValueError: Value must be one of', $failure['reason']);
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

    private function docxThatCrashesAmoxtli(string $file, string $body): void
    {
        // amoxtli 0.17.3 plante sur un titre de plus de 250 mots qui mêle texte
        // et mise en forme (runtime error: slice bounds out of range).
        $words = implode(' ', array_map(fn (int $i): string => 'mot'.$i, range(1, 300)));
        $this->docx($file, "# Titre $words *gras* $words\n\n$body");
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
        return array_column($this->searchResults($word), 'source');
    }

    /**
     * @return list<string> texte des sections trouvées, titres compris
     */
    private function searchSections(string $word): array
    {
        return array_merge([], ...array_map(fn (array $result): array => array_column($result['sections'] ?? [], 'content'), $this->searchResults($word)));
    }

    /**
     * @return list<array<string, mixed>> résultats d'amoxtli search
     */
    private function searchResults(string $word): array
    {
        $process = new Process(['/usr/local/bin/amoxtli', '--json', '-C', $this->projectDir.'/uploads/amoxtli/1', 'search', $word]);
        $process->mustRun();

        return json_decode($process->getOutput(), true)['results'] ?? [];
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
