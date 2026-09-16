<?php

namespace App\Command;

use App\Entity\Product;
use App\Service\ProductMetadataExtractor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:dev:probe-metadata',
    description: 'Test direct du ProductMetadataExtractor (debug).',
)]
class ProbeMetadataCommand extends Command
{
    public function __construct(
        private ProductMetadataExtractor $extractor,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('PRODUCT METADATA EXTRACTOR PROBE');

        $product = new Product();
        $product->setName('Ninegate');
        $product->setFiche(<<<'MD'
# Ninegate

Ninegate est un portail collaboratif open source conçu pour centraliser et distribuer
l'information de manière intelligente. Il s'adapte à chaque utilisateur, affichant
des contenus spécifiques (pages, flux RSS, annonces, calendriers, blogs) en fonction
de son profil. La richesse de Ninegate repose sur une vaste bibliothèque de widgets
qui permet à chaque utilisateur de construire ses propres pages et de gérer
l'information à sa guise, offrant une flexibilité et une personnalisation maximales.

Pour une administration simplifiée, Ninegate est doté d'une intégration poussée
avec les annuaires LDAP. Cette fonctionnalité assure une synchronisation
bidirectionnelle des données. Il peut ainsi importer des informations depuis
l'annuaire (synchronisation descendante) et, si nécessaire, exporter des données
vers celui-ci (synchronisation ascendante). Ce lien direct avec l'annuaire permet
une gestion centralisée des utilisateurs et de l'authentification, ce qui renforce
la sécurité et l'efficacité de l'ensemble du système.

Développé par Cadoles, Ninegate est un outil qui allie l'accessibilité d'un
portail collaboratif à la robustesse d'un système conçu pour s'intégrer à des
environnements informatiques complexes.
MD);

        $io->text('Fiche longue de ' . mb_strlen($product->getFiche()) . ' caractères');

        $result = $this->extractor->extractMissing($product);

        $io->section('Résultat');
        $io->listing([
            'keywords (' . count($result['keywords']) . ') : ' . implode(', ', $result['keywords']),
            'sectors (' . count($result['sectors']) . ') : ' . implode(', ', $result['sectors']),
        ]);

        return Command::SUCCESS;
    }
}
