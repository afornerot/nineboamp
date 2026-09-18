<?php

namespace App\Service;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class AmoxtliService
{
    private string $projectDir;
    private string $binaryPath = '/usr/local/bin/amoxtli';

    public function __construct(KernelInterface $kernel)
    {
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

    public function indexMarket(string $id): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $workspacePath = $this->getWorkspacePath($id);
        $marketPath = $this->projectDir.'/uploads/boamp/'.$id;

        if (!is_dir($marketPath)) {
            return;
        }

        if (!is_dir($workspacePath.'/.amoxtli')) {
            $this->initWorkspace($id);
        }

        $this->convertPdfsToText($marketPath);
        $this->convertExcelToText($marketPath);

        $process = new Process([
            $this->binaryPath,
            '-C', $workspacePath,
            'sync',
            $marketPath,
            '--base-dir', $marketPath,
            '--no-wait',
        ]);

        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * @return array{available: bool, initialized: bool, documents: array<int, array{filename: ?string, extension: ?string, size: ?int, indexed_at: ?string, source: ?string, etag: ?string}>}
     */
    public function listIndexedDocuments(string $marketId): array
    {
        $result = [
            'available' => $this->isAvailable(),
            'initialized' => false,
            'documents' => [],
        ];

        if (!$result['available']) {
            return $result;
        }

        $workspacePath = $this->getWorkspacePath($marketId);
        if (!is_dir($workspacePath.'/.amoxtli')) {
            return $result;
        }

        $result['initialized'] = true;

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

        $result['documents'] = array_map(function (array $doc): array {
            $meta = $doc['metadata'] ?? [];
            $source = $doc['source'] ?? null;

            return [
                'filename' => $meta['filename'] ?? ($source ? basename(urldecode(parse_url($source, PHP_URL_PATH) ?? '')) : null),
                'extension' => $meta['extension'] ?? null,
                'size' => isset($meta['size']) ? (int) $meta['size'] : null,
                'indexed_at' => $meta['indexed_at'] ?? ($doc['created_at'] ?? null),
                'source' => $source,
                'etag' => $doc['etag'] ?? null,
            ];
        }, $data['documents']);

        return $result;
    }

    private function convertPdfsToText(string $directory): void
    {
        $pdfFiles = glob($directory.'/*.pdf');

        foreach ($pdfFiles as $pdfFile) {
            $txtFile = preg_replace('/\.pdf$/i', '.txt', $pdfFile);

            if (is_file($txtFile)) {
                continue;
            }

            $process = new Process(['pdftotext', '-layout', $pdfFile, $txtFile]);
            $process->setTimeout(30);
            $process->run();
        }
    }

    private function convertExcelToText(string $directory): void
    {
        $xlsxFiles = glob($directory.'/*.xlsx');

        foreach ($xlsxFiles as $xlsxFile) {
            $txtFile = preg_replace('/\.xlsx$/i', '.txt', $xlsxFile);

            if (is_file($txtFile)) {
                continue;
            }

            $process = new Process([
                'python3', '-c',
                'import openpyxl, csv, sys; wb = openpyxl.load_workbook(sys.argv[1]); ws = wb.active; w = csv.writer(sys.stdout, quoting=csv.QUOTE_ALL, lineterminator="\n"); [w.writerow(row) for row in ws.values]',
                $xlsxFile,
            ]);
            $process->setTimeout(30);

            try {
                $process->run();
                if ($process->isSuccessful()) {
                    file_put_contents($txtFile, $process->getOutput());
                }
            } catch (\Throwable) {
            }
        }
    }

    private function configureWorkspace(string $workspacePath): void
    {
    }
}
