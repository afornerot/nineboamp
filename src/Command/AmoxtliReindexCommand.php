<?php

namespace App\Command;

use App\Service\AmoxtliService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
        $this->addArgument('marketId', InputArgument::OPTIONAL, 'ID du marché à réindexer');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Réindexer tous les marchés qui ont des pièces');
        $this->addOption('purge-legacy-txt', null, InputOption::VALUE_NONE, 'Supprimer d\'abord les .txt que l\'ancienne indexation a écrits à côté des .pdf et .xlsx');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Avec --purge-legacy-txt : lister les .txt sans rien supprimer ni réindexer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $marketId = $input->getArgument('marketId');
        $all = $input->getOption('all');
        $purge = $input->getOption('purge-legacy-txt');
        $dryRun = $input->getOption('dry-run');

        if ((null === $marketId && !$all) || (null !== $marketId && $all)) {
            $io->error('Indiquer un ID de marché ou --all.');
            return Command::INVALID;
        }

        if (null !== $marketId && !ctype_digit((string) $marketId)) {
            $io->error('L\'ID de marché doit être un nombre.');
            return Command::INVALID;
        }

        if ($dryRun && !$purge) {
            $io->error('--dry-run ne s\'utilise qu\'avec --purge-legacy-txt.');
            return Command::INVALID;
        }

        if (!$dryRun && !$this->amoxtliService->isAvailable()) {
            $io->error('Le binaire amoxtli n\'est pas disponible.');
            return Command::FAILURE;
        }

        $marketIds = $all ? $this->amoxtliService->listMarketIds() : [(string) $marketId];

        $failed = 0;
        foreach ($marketIds as $id) {
            try {
                if ($purge) {
                    $this->purgeLegacyTxt($io, $id, $dryRun);
                }
                if (!$dryRun) {
                    $this->reindex($io, $id);
                }
            } catch (\Throwable $e) {
                $io->error(sprintf('Marché %s : %s', $id, $e->getMessage()));
                ++$failed;
            }
        }

        if ($failed > 0) {
            $io->warning(sprintf('%d marché(s) sur %d en échec.', $failed, count($marketIds)));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function reindex(SymfonyStyle $io, string $marketId): void
    {
        $io->info(sprintf('Réindexation du marché %s...', $marketId));

        $report = $this->amoxtliService->indexMarket($marketId, true);
        if (null === $report) {
            $io->text('Aucun dossier de pièces pour ce marché : pas d\'index.');
            return;
        }

        $io->text(sprintf('%d pièce(s) convertie(s), %d copiée(s) telle(s) quelle(s).', count($report['converted']), count($report['copied'])));
        if ([] !== $report['unsupported']) {
            $io->text('Format non pris en charge :');
            $io->listing($report['unsupported']);
        }
        if ([] !== $report['failed']) {
            $io->text('Échecs :');
            $io->listing(array_map(fn (array $failure) => $failure['file'].' : '.$failure['reason'], $report['failed']));
        }

        $result = $this->amoxtliService->listIndexedDocuments($marketId);
        if (null !== $result['error']) {
            $io->warning(sprintf('Index reconstruit, mais sa liste est illisible : %s', $result['error']));
            return;
        }
        $io->success(sprintf('%d document(s) indexé(s).', count($result['documents'])));
    }

    /**
     * Supprime les .txt de l'ancienne indexation (AmoxtliService::findLegacyTxt()).
     * Un .txt déposé par un utilisateur sous le même nom de base serait
     * supprimé lui aussi : relire d'abord --dry-run.
     */
    private function purgeLegacyTxt(SymfonyStyle $io, string $marketId, bool $dryRun): void
    {
        $legacy = array_keys($this->amoxtliService->findLegacyTxt($marketId));
        if ([] === $legacy) {
            return;
        }

        $io->text(sprintf('Marché %s : %d .txt %s', $marketId, count($legacy), $dryRun ? 'à supprimer' : 'supprimé(s)'));
        $io->listing($legacy);

        if (!$dryRun) {
            $marketPath = $this->amoxtliService->getMarketPath($marketId);
            foreach ($legacy as $file) {
                unlink($marketPath.'/'.$file);
            }
        }
    }
}
