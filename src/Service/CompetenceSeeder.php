<?php

namespace App\Service;

use App\Entity\Competence;
use App\Repository\CompetenceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crée les compétences par défaut quand la table est vide : premier démarrage,
 * ou installation antérieure au passage des compétences en base (le contexte
 * entreprise vivait alors en dur dans les prompts).
 */
class CompetenceSeeder
{
    /**
     * @var array<string, string[]>
     */
    public const DEFAULTS = [
        Competence::CATEGORY_PRESENTATION => [
            'Cadoles est une SCOP de services informatiques basée à Dijon, spécialisée dans les logiciels libres et les formats ouverts. 16 salariés, créée en 2011.',
        ],
        Competence::CATEGORY_SAVOIR_FAIRE => [
            'Développement web et logiciel (Symfony, React, Python, Go)',
            'Infrastructure et hébergement (Linux, Kubernetes)',
            'Formation aux logiciels libres',
            'Expertise technique et support',
        ],
        Competence::CATEGORY_OFFRE => [
            'Développement de logiciels sur mesure',
            "Mise en place d'infrastructures open-source",
            'Formation aux outils libres',
            'Intégration de solutions existantes',
        ],
        Competence::CATEGORY_EXCLUSION => [
            "Nous ne sommes PAS des géomètres, des topographes ou des bureaux d'études géotechniques",
            'Nous ne réalisons pas de levés de terrain, de cadastre, ni de bornage',
            'Nous ne répondons pas aux marchés de travaux publics, de voirie, ni de génie civil',
            'Attention : notre produit LaCanne est un kit GPS topographique vendu aux géomètres et topographes. Nous vendons le matériel, nous ne réalisons pas les prestations de relevé ni de levé de terrain',
        ],
    ];

    public function __construct(
        private CompetenceRepository $competenceRepository,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return int nombre de compétences créées (0 si la table n'était pas vide)
     */
    public function seedIfEmpty(): int
    {
        if ($this->competenceRepository->count([]) > 0) {
            return 0;
        }

        $created = 0;
        foreach (self::DEFAULTS as $category => $labels) {
            foreach ($labels as $index => $label) {
                $competence = new Competence();
                $competence->setCategory($category);
                $competence->setLabel($label);
                $competence->setPosition(($index + 1) * 10);
                $this->em->persist($competence);
                ++$created;
            }
        }
        $this->em->flush();

        return $created;
    }
}
