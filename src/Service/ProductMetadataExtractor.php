<?php

namespace App\Service;

use App\Entity\Product;
use Psr\Log\LoggerInterface;

class ProductMetadataExtractor
{
    private const MAX_KEYWORDS = 10;
    private const MAX_SECTORS = 5;
    private const MAX_FICHE_LENGTH = 4000;
    private const MAX_KEYWORD_LENGTH = 30;
    private const MAX_DESCRIPTION_LENGTH = 300;

    public function __construct(
        private AiService $ai,
        private LoggerInterface $logger,
        private PromptLoader $promptLoader,
    ) {
    }

    /**
     * Extrait UNIQUEMENT les champs vides depuis la fiche (mots-clés, secteurs, description).
     * Les champs manuellement remplis sont préservés tels quels.
     *
     * @return array{keywords: string[], sectors: string[], description: string}
     */
    public function extractMissing(Product $product): array
    {
        $manualKeywords = $this->parseCsv($product->getKeywords());
        $manualSectors = $this->parseCsv($product->getSectors());
        $manualDescription = $this->stringify($product->getDescription());

        $needKeywords = [] === $manualKeywords;
        $needSectors = [] === $manualSectors;
        $needDescription = '' === $manualDescription;

        $result = [
            'keywords' => $manualKeywords,
            'sectors' => $manualSectors,
            'description' => $manualDescription,
        ];

        if (!$needKeywords && !$needSectors && !$needDescription) {
            return $result;
        }

        if (!$product->getFiche()) {
            if ($needKeywords) {
                $result['keywords'] = $product->getName() ? [$product->getName()] : [];
            }

            return $result;
        }

        $userPrompt = $this->buildPrompt($product->getFiche(), $needKeywords, $needSectors, $needDescription);
        $systemPrompt = $this->systemPrompt();
        $userMeta = $this->promptLoader->load('extract.user');
        $temperature = $userMeta?->getTemperature() ?? 0.0;
        $maxTokens = $userMeta?->getMaxTokens() ?? 2048;
        $response = $this->ai->ask($userPrompt, $systemPrompt, $temperature, $maxTokens);

        if ('' === $response) {
            $this->logger->warning('ProductMetadataExtractor: réponse IA vide — vérifiez AI_PROVIDER/AI_MODEL/AI_API_KEY/AI_BASE_URL', [
                'product' => $product->getName(),
                'need' => ['keywords' => $needKeywords, 'sectors' => $needSectors, 'description' => $needDescription],
            ]);
            if ($needKeywords) {
                $result['keywords'] = $product->getName() ? [$product->getName()] : [];
            }

            return $result;
        }

        $parsed = $this->parseResponse($response);

        $nothingUseful = (!$needKeywords || [] === $parsed['keywords'])
            && (!$needSectors || [] === $parsed['sectors'])
            && (!$needDescription || '' === $parsed['description']);
        if ($nothingUseful) {
            $this->logger->warning('ProductMetadataExtractor: réponse LLM non exploitable', [
                'product' => $product->getName(),
                'response' => mb_substr($response, 0, 500),
            ]);
        }

        if ($needKeywords) {
            $result['keywords'] = [] !== $parsed['keywords']
                ? $parsed['keywords']
                : ($product->getName() ? [$product->getName()] : []);
        }
        if ($needSectors) {
            $result['sectors'] = $parsed['sectors'];
        }
        if ($needDescription) {
            $result['description'] = $parsed['description'];
        }

        return $result;
    }

    private function systemPrompt(): string
    {
        $fromFile = $this->promptLoader->renderSystem('extract.system');
        if ('' !== $fromFile) {
            return $fromFile;
        }

        throw new \RuntimeException('Prompt extract.system.md not found in src/DataFixtures/data/scoring/');
    }

    private function buildPrompt(string $fiche, bool $needKeywords, bool $needSectors, bool $needDescription): string
    {
        $tasks = [];
        if ($needKeywords) {
            $tasks[] = sprintf(
                "1. 'keywords' : 5 à 10 mots-clés métier de recherche BOAMP, en français, 1 mot par entrée, max %d caractères, pas de phrase.",
                self::MAX_KEYWORD_LENGTH,
            );
        }
        if ($needSectors) {
            $tasks[] = "2. 'sectors' : 1 à 5 secteurs d'activité cibles (acheteurs publics typiques).";
        }
        if ($needDescription) {
            $tasks[] = sprintf(
                "3. 'description' : une phrase courte (max %d caractères) décrivant ce que fait le produit pour les acheteurs publics. Français, sans markdown.",
                self::MAX_DESCRIPTION_LENGTH,
            );
        }

        $schemaParts = [];
        if ($needKeywords) {
            $schemaParts[] = '"keywords":["mot1","mot2"]';
        }
        if ($needSectors) {
            $schemaParts[] = '"sectors":["secteur1"]';
        }
        if ($needDescription) {
            $schemaParts[] = '"description":"phrase courte"';
        }
        $schema = '{'.implode(',', $schemaParts).'}';

        $rendered = $this->promptLoader->renderUser('extract.user', [
            'tasks' => implode("\n", $tasks),
            'schema' => $schema,
            'fiche' => mb_substr($fiche, 0, self::MAX_FICHE_LENGTH),
        ]);

        if ('' !== $rendered) {
            return $rendered;
        }

        return sprintf(
            "Tâche : extraire les éléments suivants depuis cette fiche produit.\n%s\n\n"
            ."FORMAT DE RÉPONSE OBLIGATOIRE : un objet JSON strict, sans markdown, sans texte avant/après.\n"
            ."Schéma attendu : %s\n\n"
            ."Fiche produit :\n----DEBUT----\n%s\n----FIN----",
            implode("\n", $tasks),
            $schema,
            mb_substr($fiche, 0, self::MAX_FICHE_LENGTH),
        );
    }

    /**
     * @return array{keywords: string[], sectors: string[], description: string}
     */
    private function parseResponse(string $response): array
    {
        if ('' === $response) {
            return ['keywords' => [], 'sectors' => [], 'description' => ''];
        }

        $json = $this->extractJsonFromResponse($response);

        if ('' === $json) {
            return ['keywords' => [], 'sectors' => [], 'description' => ''];
        }

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['keywords' => [], 'sectors' => [], 'description' => ''];
        }

        if (!is_array($data)) {
            return ['keywords' => [], 'sectors' => [], 'description' => ''];
        }

        return [
            'keywords' => $this->sanitizeList($data['keywords'] ?? [], self::MAX_KEYWORDS),
            'sectors' => $this->sanitizeList($data['sectors'] ?? [], self::MAX_SECTORS),
            'description' => $this->sanitizeDescription($data['description'] ?? ''),
        ];
    }

    private function extractJsonFromResponse(string $response): string
    {
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/i', $response, $m)) {
            return $m[1];
        }

        if (preg_match('/\{[\s\S]*\}/u', $response, $m)) {
            return $m[0];
        }

        return '';
    }

    /**
     * @return string[]
     */
    private function sanitizeList(mixed $values, int $max): array
    {
        if (!is_array($values)) {
            return [];
        }

        $clean = [];
        foreach ($values as $v) {
            if (!is_string($v)) {
                continue;
            }
            $v = trim($v);
            $v = preg_replace('/^[\s\-•*\d.]+/u', '', $v) ?? $v;
            $v = trim($v);
            if ('' === $v || mb_strlen($v) > self::MAX_KEYWORD_LENGTH) {
                continue;
            }
            $clean[] = $v;
        }

        return array_values(array_slice(array_unique($clean), 0, $max));
    }

    private function sanitizeDescription(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = trim($value);
        $value = (string) (preg_replace('/^[\s\-•*\d.]+/u', '', $value) ?? $value);
        $value = trim($value, "\"'`");

        if (mb_strlen($value) > self::MAX_DESCRIPTION_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_DESCRIPTION_LENGTH - 3).'...';
        }

        return $value;
    }

    /**
     * @return string[]
     */
    private function parseCsv(?string $csv): array
    {
        if (null === $csv || '' === trim($csv)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)))));
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (null === $value) {
            return '';
        }

        return (string) $value;
    }
}
