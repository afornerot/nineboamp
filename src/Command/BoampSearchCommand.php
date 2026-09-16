<?php

namespace App\Command;

use App\Service\BoampFinderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:boamp:search',
    description: 'Recherche et qualification des opportunités BOAMP',
)]
class BoampSearchCommand extends Command
{
    public function __construct(
        private BoampFinderService $boampFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-table', null, InputOption::VALUE_NONE, 'Ne pas afficher le tableau détaillé des marchés qualifiés');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('BOAMP SEARCH');

        $result = $this->boampFinder->run($io);
        $report = $result->getReport();

        $io->section('Bilan');
        $io->writeln(sprintf('  Date d\'exécution : <info>%s</info>', $report->getExecutedAt()?->format('Y-m-d H:i:s') ?? 'N/A'));

        $rows = [
            ['Candidats (deadline ≥ +30j)', $result->getCandidatesCount()],
            ['Marchés trouvés (boucle)', $report->getMarketsFound() ?? 0],
            ['Marchés qualifiés (persistés)', $report->getMarketsQualified() ?? 0],
            ['Marchés notifiés (RocketChat)', $report->getMarketsNotified() ?? 0],
        ];
        $io->table(['Étape', 'Valeur'], $rows);

        $totalSkipped = $result->getSkippedExisting()
            + $result->getSkippedNoIdweb()
            + $result->getSkippedNoDetails()
            + $result->getSkippedParseError()
            + $result->getSkippedScoringError();

        if ($totalSkipped > 0) {
            $io->section('Détail des skips');
            $skips = [
                ['Sans idweb', $result->getSkippedNoIdweb()],
                ['Déjà en base (déjà scorés)', $result->getSkippedExisting()],
                ['Détails indisponibles', $result->getSkippedNoDetails()],
                ['Réponse IA non exploitable (parse)', $result->getSkippedParseError()],
                ['Erreur de scoring (catch)', $result->getSkippedScoringError()],
            ];
            $io->table(['Raison', 'Nombre'], $skips);
        }

        $qualified = $result->getQualifiedMarkets();
        if ([] !== $qualified && !$input->getOption('no-table')) {
            $io->section(sprintf('Marchés qualifiés (%d)', count($qualified)));
            $rows = [];
            foreach ($qualified as $m) {
                $rows[] = [
                    $m['idweb'],
                    $m['priority'],
                    $m['score'],
                    $m['deadline'] ?? 'N/A',
                    mb_substr($m['title'] ?? '', 0, 60),
                    mb_substr($m['buyer'] ?? '', 0, 30),
                ];
            }
            $io->table(['IDWEB', 'Prio.', 'Score', 'Deadline', 'Titre', 'Acheteur'], $rows);
        } elseif ([] !== $qualified) {
            $io->writeln(sprintf('  <info>%d</info> marché(s) qualifié(s) — relancer sans <comment>--no-table</comment> pour le détail.', count($qualified)));
        }

        if ($report->getError()) {
            $io->error('Erreur globale du run : '.$report->getError());

            return Command::FAILURE;
        }

        if (0 === ($report->getMarketsQualified() ?? 0) && 0 === $result->getCandidatesCount()) {
            $io->warning('Aucun candidat retenu. Vérifiez les mots-clés et la deadline (+30j).');
        } elseif (0 === ($report->getMarketsQualified() ?? 0)) {
            $io->warning('Des marchés ont été trouvés mais aucun qualifié par le scoring IA.');
        } else {
            $io->success(sprintf(
                '%d marché(s) qualifié(s) sur %d candidat(s).',
                $report->getMarketsQualified() ?? 0,
                $result->getCandidatesCount()
            ));
        }

        return Command::SUCCESS;
    }
}
