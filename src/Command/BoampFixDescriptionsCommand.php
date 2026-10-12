<?php

namespace App\Command;

use App\Entity\Market;
use App\Service\BoampApiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:boamp:fix-descriptions',
    description: 'Corrige les descriptions manquantes des marchés BOAMP et les remet en detected',
)]
class BoampFixDescriptionsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private BoampApiService $boampApi,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ne pas modifier, juste afficher');
        $this->addOption('rescore', null, InputOption::VALUE_NONE, 'Relancer le scoring après correction');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('BOAMP FIX DESCRIPTIONS');

        $repo = $this->em->getRepository(Market::class);
        $qb = $repo->createQueryBuilder('m')
            ->where('m.description IS NULL OR m.description = :empty')
            ->setParameter('empty', '');

        $markets = $qb->getQuery()->getResult();
        $io->info(sprintf('%d marché(s) sans description trouvé(s)', count($markets)));

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

            $description = $this->extractDescription($details);

            if ('' === trim($description)) {
                $description = $this->stringifyValue($details['descripteur_libelle'] ?? '');
            }
            if ('' === trim($description)) {
                $description = $this->stringifyValue($details['objet'] ?? '');
            }

            if ('' === trim($description)) {
                $io->warning('aucune description exploitable');
                $failed++;
                continue;
            }

            if ($input->getOption('dry-run')) {
                $io->note(sprintf('daction: description = "%s"', mb_substr($description, 0, 80)));
            } else {
                $market->setDescription($description);
                $market->setStatus(Market::STATUS_DETECTED);
                $this->em->persist($market);
                $this->em->flush();
                $io->success(sprintf('mis à jour (%d chars)', mb_strlen($description)));
            }

            $updated++;
        }

        $io->newLine();
        $io->info(sprintf('Résumé: %d mis à jour, %d échoué(s)', $updated, $failed));

        if ($input->getOption('rescore') && !$input->getOption('dry-run') && $updated > 0) {
            $io->note('Option --rescore non implémentée dans ce script. Utilisez app:boamp:search单独的.');
        }

        return Command::SUCCESS;
    }

    private function extractDescription(array $details): string
    {
        $donnees = $this->parseDonnees($details);

        return $donnees['FNSimple']['initial']['natureMarche']['description']
            ?? $donnees['EFORMS']['ContractNotice']['cac:ProcurementProject']['cbc:Description']['#text']
            ?? $donnees['OBJET']['OBJET_COMPLET']
            ?? $donnees['OBJET']['description']
            ?? '';
    }

    private function parseDonnees(array $details): array
    {
        $raw = $details['donnees'] ?? '';
        if (!is_string($raw) || '' === trim($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function stringifyValue(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return implode(', ', array_filter($value, fn($v) => is_string($v) || is_numeric($v)));
        }

        return (string) $value;
    }
}
