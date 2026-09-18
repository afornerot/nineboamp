<?php

namespace App\Command;

use App\Service\AmoxtliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:amoxtli:reindex',
    description: 'Réindexe un marché dans amoxtli',
)]
class AmoxtliReindexCommand extends Command
{
    public function __construct(private AmoxtliService $amoxtliService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('marketId', InputArgument::REQUIRED, 'ID du marché à réindexer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $marketId = $input->getArgument('marketId');

        if (!$this->amoxtliService->isAvailable()) {
            $io->error('Le binaire amoxtli n\'est pas disponible.');
            return Command::FAILURE;
        }

        $io->info(sprintf('Réindexation du marché %s...', $marketId));

        $workspacePath = $this->amoxtliService->getWorkspacePath($marketId);
        if (is_dir($workspacePath . '/.amoxtli')) {
            $io->text('Suppression du workspace existant...');
            $this->deleteDirectory($workspacePath . '/.amoxtli');
        }

        $marketPath = $this->amoxtliService->getProjectDir() . '/uploads/boamp/' . $marketId;
        foreach (glob($marketPath . '/*.xlsx') as $xlsxFile) {
            $txtFile = preg_replace('/\.xlsx$/i', '.txt', $xlsxFile);
            if (is_file($txtFile)) {
                unlink($txtFile);
            }
        }

        $this->amoxtliService->indexMarket($marketId);

        $result = $this->amoxtliService->listIndexedDocuments($marketId);
        $io->success(sprintf('%d document(s) indexé(s).', count($result['documents'])));

        return Command::SUCCESS;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
