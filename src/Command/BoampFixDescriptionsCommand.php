<?php

namespace App\Command;

use App\Entity\Market;
use App\Service\BoampApiService;
use App\Service\BoampFinderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:boamp:fix-descriptions',
    description: 'Corrige les descriptions manquantes ou illisibles des marchés BOAMP',
)]
class BoampFixDescriptionsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private BoampApiService $boampApi,
        private BoampFinderService $finder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ne pas modifier, juste afficher');
        $this->addOption('rescore', null, InputOption::VALUE_NONE, 'Requalifier chaque marché corrigé');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('BOAMP FIX DESCRIPTIONS');

        // « [ » : descripteurs encodés en JSON, faute d'avoir trouvé la description de l'avis.
        $repo = $this->em->getRepository(Market::class);
        $qb = $repo->createQueryBuilder('m')
            ->where('m.description IS NULL OR m.description = :empty OR m.description LIKE :json')
            ->setParameter('empty', '')
            ->setParameter('json', '[%');

        $markets = $qb->getQuery()->getResult();
        $io->info(sprintf('%d marché(s) sans description exploitable trouvé(s)', count($markets)));

        if (0 === count($markets)) {
            $io->success('Aucun marché à corriger.');

            return Command::SUCCESS;
        }

        $io->table(
            ['IDWEB', 'Titre'],
            array_map(fn(Market $m) => [$m->getIdweb(), mb_substr($m->getTitle() ?? '', 0, 60)], $markets)
        );

        $updated = 0;
        $failed = 0;

        foreach ($markets as $market) {
            $idweb = $market->getIdweb();
            $io->write(sprintf('  %s: ', $idweb));

            $details = $this->boampApi->getMarketDetails($idweb);
            if (null === $details) {
                $io->warning('détails indisponibles via API');
                $failed++;
                continue;
            }

            $description = $this->finder->extractDescription($details);

            if ('' === trim($description)) {
                $io->warning('aucune description exploitable');
                $failed++;
                continue;
            }

            if ($input->getOption('dry-run')) {
                $io->note(sprintf('description = "%s"', mb_substr($description, 0, 80)));
                $updated++;
                continue;
            }

            $market->setDescription($description);
            $this->em->flush();

            if ($input->getOption('rescore')) {
                try {
                    $this->finder->rescoreMarket($market);
                } catch (\Throwable $e) {
                    $io->warning(sprintf('description corrigée, requalification échouée : %s', $e->getMessage()));
                    $failed++;
                    continue;
                }
                $io->success(sprintf('corrigé et requalifié (%d/100, priorité %s)', $market->getScore() ?? 0, $market->getPriority() ?? '?'));
            } else {
                $io->success(sprintf('mis à jour (%d chars)', mb_strlen($description)));
            }

            $updated++;
        }

        $io->newLine();
        $io->info(sprintf('Résumé: %d mis à jour, %d échoué(s)', $updated, $failed));

        return Command::SUCCESS;
    }
}
