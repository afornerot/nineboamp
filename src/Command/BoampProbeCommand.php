<?php

namespace App\Command;

use App\Service\BoampApiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:boamp:probe',
    description: 'Test direct de la requête API BOAMP avec mots-clés passés en argument.',
)]
class BoampProbeCommand extends Command
{
    public function __construct(
        private BoampApiService $boampApi,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('keywords', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Mots-clés à tester');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('BOAMP PROBE');

        $keywords = $input->getArgument('keywords');
        $io->text('Mots-clés envoyés (' . count($keywords) . ') : ' . implode(', ', array_slice($keywords, 0, 10)));
        if (count($keywords) > 10) {
            $io->text('… et ' . (count($keywords) - 10) . ' autres.');
        }

        $results = $this->boampApi->searchMarkets($keywords, 50, 'dateparution DESC');

        $io->text('Nombre de résultats bruts : ' . count($results));

        if (count($results) > 0) {
            $io->section('Échantillon');
            $deadlineCount = 0;
            $futureCount = 0;
            $now = new \DateTime();
            foreach ($results as $r) {
                if (isset($r['datelimitereponse'])) {
                    ++$deadlineCount;
                    try {
                        $d = new \DateTime($r['datelimitereponse']);
                        if ($d >= $now) {
                            ++$futureCount;
                        }
                    } catch (\Exception) {
                    }
                }
            }
            $io->text("Marchés avec datelimitereponse : $deadlineCount / " . count($results));
            $io->text("Marchés avec deadline future : $futureCount / $deadlineCount");

            foreach (array_slice($results, 0, 3) as $i => $r) {
                $io->writeln(' #' . ($i + 1) . ' - ' . mb_substr($r['objet'] ?? 'N/A', 0, 80));
            }
        }

        return Command::SUCCESS;
    }
}
