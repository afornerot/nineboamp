<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Propose une réécriture du formulaire produit (fiche au gabarit, description,
 * mots-clés, secteurs) à partir de ce qui y est saisi et d'une consigne libre.
 * Ne modifie aucun produit : le résultat remplit le formulaire, que
 * l'utilisateur relit puis valide.
 */
class ProductSheetGenerator
{
    public const MAX_FICHE_INPUT_LENGTH = 30000;
    public const MAX_INSTRUCTION_LENGTH = 2000;

    private const MAX_DESCRIPTION_LENGTH = 300;
    private const MAX_KEYWORDS = 10;
    private const MAX_SECTORS = 5;
    private const MAX_TERM_LENGTH = 30;
    private const MAX_SECTOR_LENGTH = 60;

    /**
     * La génération d'une fiche entière dépasse le délai par défaut d'AiService.
     */
    private const TIMEOUT = 180;

    private const SECTIONS = [
        'description' => 'DESCRIPTION',
        'keywords' => 'MOTS-CLES',
        'sectors' => 'SECTEURS',
        'fiche' => 'FICHE',
    ];

    public function __construct(
        private AiService $ai,
        private PromptLoader $promptLoader,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{name?: string, description?: string, keywords?: string, sectors?: string, fiche?: string} $current contenu actuel du formulaire
     *
     * @throws \RuntimeException si la génération est impossible (rien à réécrire, IA muette, réponse inexploitable)
     *
     * @return array{description: string, keywords: string, sectors: string, fiche: string}
     */
    public function generate(array $current, string $instruction = ''): array
    {
        $name = trim($current['name'] ?? '');
        $fiche = trim($current['fiche'] ?? '');
        $instruction = trim($instruction);

        if ('' === $name) {
            throw new \RuntimeException('Renseignez le nom du produit avant de lancer la génération.');
        }
        if ('' === $fiche && '' === $instruction && '' === trim($current['description'] ?? '')) {
            throw new \RuntimeException("Rien à réécrire : saisissez au moins une ébauche de fiche, une description ou une consigne pour l'IA.");
        }

        $system = $this->promptLoader->renderSystem('product.generate.system');
        $user = $this->promptLoader->renderUser('product.generate.user', [
            'name' => $name,
            'description' => $this->orNone($current['description'] ?? ''),
            'keywords' => $this->orNone($current['keywords'] ?? ''),
            'sectors' => $this->orNone($current['sectors'] ?? ''),
            'instruction' => '' !== $instruction ? mb_substr($instruction, 0, self::MAX_INSTRUCTION_LENGTH) : 'Aucune consigne particulière.',
            'fiche' => '' !== $fiche ? mb_substr($fiche, 0, self::MAX_FICHE_INPUT_LENGTH) : '(fiche vide)',
        ]);
        if ('' === $system || '' === $user) {
            throw new \RuntimeException('Prompts product.generate.*.md introuvables.');
        }

        $meta = $this->promptLoader->load('product.generate.system');
        $response = $this->ai->ask($user, $system, $meta?->getTemperature() ?? 0.3, $meta?->getMaxTokens() ?? 8192, self::TIMEOUT);
        if ('' === trim($response)) {
            throw new \RuntimeException("L'IA n'a pas répondu. Vérifiez la configuration IA et les logs.");
        }

        $sections = $this->parse($response);
        if ('' === $sections['fiche']) {
            $this->logger->warning('ProductSheetGenerator: réponse LLM sans fiche', ['product' => $name, 'response' => mb_substr($response, 0, 500)]);

            throw new \RuntimeException("La réponse de l'IA n'est pas exploitable. Réessayez.");
        }

        return [
            'description' => $this->cleanDescription($sections['description']),
            'keywords' => implode(', ', $this->cleanList($sections['keywords'], self::MAX_KEYWORDS, self::MAX_TERM_LENGTH)),
            'sectors' => implode(', ', $this->cleanList($sections['sectors'], self::MAX_SECTORS, self::MAX_SECTOR_LENGTH)),
            'fiche' => $sections['fiche'],
        ];
    }

    /**
     * Découpe la réponse en blocs « ===NOM=== ». Ce format évite d'encoder une
     * fiche Markdown entière dans une chaîne JSON, source d'erreurs d'échappement.
     *
     * @return array{description: string, keywords: string, sectors: string, fiche: string}
     */
    private function parse(string $response): array
    {
        $sections = ['description' => '', 'keywords' => '', 'sectors' => '', 'fiche' => ''];

        $markers = implode('|', array_map(static fn (string $marker): string => preg_quote($marker, '/'), self::SECTIONS));
        $parts = preg_split('/^\s*={3,}\s*('.$markers.')\s*={3,}\s*$/mu', $response, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            return $sections;
        }

        $keys = array_flip(self::SECTIONS);
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $sections[$keys[$parts[$i]]] = trim($parts[$i + 1]);
        }

        // Un modèle entoure parfois la fiche d'un bloc de code malgré la consigne.
        $sections['fiche'] = trim((string) preg_replace('/\A```(?:markdown|md)?\s*\n(.*)\n```\s*\z/su', '$1', $sections['fiche']));

        return $sections;
    }

    private function cleanDescription(string $description): string
    {
        $description = trim((string) preg_replace('/\s+/u', ' ', $description), " \"'`");

        return mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH
            ? mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH - 1).'…'
            : $description;
    }

    /**
     * @return string[]
     */
    private function cleanList(string $raw, int $max, int $maxLength): array
    {
        $clean = [];
        foreach (preg_split('/[,;\n]+/u', $raw) ?: [] as $term) {
            $term = trim((string) preg_replace('/^[\s\-•*\d.]+/u', '', $term));
            $key = mb_strtolower($term);
            if ('' === $term || mb_strlen($term) > $maxLength || isset($clean[$key])) {
                continue;
            }
            $clean[$key] = $term;
        }

        return array_slice(array_values($clean), 0, $max);
    }

    private function orNone(string $value): string
    {
        $value = trim($value);

        return '' !== $value ? $value : '(aucun)';
    }
}
