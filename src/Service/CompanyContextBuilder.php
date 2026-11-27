<?php

namespace App\Service;

use App\Entity\Competence;
use App\Repository\CompetenceRepository;

/**
 * Rend les compétences actives en Markdown pour le placeholder
 * {{competences}} des prompts système (scoring, rapport, chat).
 */
class CompanyContextBuilder
{
    public const PLACEHOLDER = '{{competences}}';

    private const EMPTY_CONTEXT = "Compétences de l'entreprise non renseignées.";

    public function __construct(
        private CompetenceRepository $competenceRepository,
    ) {
    }

    public function build(): string
    {
        $byCategory = [];
        foreach ($this->competenceRepository->findActiveOrdered() as $competence) {
            // Sur une seule ligne : un retour à la ligne sortirait de la puce, et
            // une ligne commençant par « ### » deviendrait un titre de catégorie.
            $label = trim((string) preg_replace('/\s+/u', ' ', (string) $competence->getLabel()));
            if ('' === $label) {
                continue;
            }
            $byCategory[$competence->getCategory()][] = $label;
        }

        $blocks = [];
        foreach (Competence::CATEGORIES as $category => $title) {
            $labels = $byCategory[$category] ?? [];
            if ([] === $labels) {
                continue;
            }

            // La présentation est un texte libre ; les autres catégories sont des listes titrées.
            $blocks[] = Competence::CATEGORY_PRESENTATION === $category
                ? implode("\n", $labels)
                : '### '.$title."\n".implode("\n", array_map(static fn (string $label): string => '- '.$label, $labels));
        }

        return [] === $blocks ? self::EMPTY_CONTEXT : implode("\n\n", $blocks);
    }

    /**
     * Substitue {{competences}} dans un prompt rendu côté PHP.
     */
    public function apply(string $prompt): string
    {
        if (!str_contains($prompt, self::PLACEHOLDER)) {
            return $prompt;
        }

        return str_replace(self::PLACEHOLDER, $this->build(), $prompt);
    }
}
