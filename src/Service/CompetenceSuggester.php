<?php

namespace App\Service;

use App\Entity\Competence;
use App\Entity\Product;
use App\Repository\CompetenceRepository;
use App\Repository\ProductRepository;
use Psr\Log\LoggerInterface;

/**
 * Propose des compétences à ajouter, déduites du catalogue produits. Ne crée
 * rien : chaque proposition est relue et validée par un administrateur.
 */
class CompetenceSuggester
{
    public const MAX_SUGGESTIONS = 8;

    private const MAX_LABEL_LENGTH = 300;
    private const MAX_REASON_LENGTH = 300;
    private const MAX_FICHE_LENGTH = 2500;
    /**
     * Taille maximale du catalogue dans le prompt, tous produits confondus.
     */
    private const MAX_CATALOGUE_LENGTH = 40000;
    /**
     * Part réservée au nom, à la description et aux mots-clés d'un produit.
     */
    private const PRODUCT_HEADER_LENGTH = 700;
    /**
     * En dessous, un extrait de fiche n'apporte rien : on s'en tient à l'en-tête.
     */
    private const MIN_FICHE_LENGTH = 200;

    /**
     * La présentation de l'entreprise ne se déduit pas du catalogue.
     */
    private const SUGGESTABLE = [
        Competence::CATEGORY_SAVOIR_FAIRE,
        Competence::CATEGORY_OFFRE,
        Competence::CATEGORY_EXCLUSION,
    ];

    public function __construct(
        private AiService $ai,
        private PromptLoader $promptLoader,
        private CompanyContextBuilder $companyContext,
        private CompetenceRepository $competenceRepository,
        private ProductRepository $productRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \RuntimeException si la suggestion est impossible (catalogue vide, IA muette ou réponse inexploitable)
     *
     * @return list<array{category: string, label: string, reason: string}>
     */
    public function suggest(): array
    {
        $catalogue = $this->buildCatalogue();
        if ('' === $catalogue) {
            throw new \RuntimeException('Le catalogue produits est vide : rien à analyser.');
        }

        $system = $this->promptLoader->renderSystem('competence.suggest.system');
        $user = $this->promptLoader->renderUser('competence.suggest.user', [
            'competences' => $this->companyContext->build(),
            'catalogue' => $catalogue,
            'max' => (string) self::MAX_SUGGESTIONS,
        ]);
        if ('' === $system || '' === $user) {
            throw new \RuntimeException('Prompts competence.suggest.*.md introuvables.');
        }

        $meta = $this->promptLoader->load('competence.suggest.system');
        $response = $this->ai->ask($user, $system, $meta?->getTemperature() ?? 0.2, $meta?->getMaxTokens() ?? 4096);
        if ('' === trim($response)) {
            throw new \RuntimeException("L'IA n'a pas répondu. Vérifiez la configuration IA et les logs.");
        }

        $data = $this->decode($response);
        if (null === $data) {
            $this->logger->warning('CompetenceSuggester: réponse LLM non exploitable', ['response' => mb_substr($response, 0, 500)]);

            throw new \RuntimeException("La réponse de l'IA n'est pas exploitable. Réessayez.");
        }

        return $this->sanitize($data['suggestions'] ?? []);
    }

    /**
     * Catalogue envoyé au modèle, borné à MAX_CATALOGUE_LENGTH : l'extrait de
     * fiche de chaque produit rétrécit quand le catalogue grandit, pour que
     * l'appel reste dans la fenêtre du modèle et dans le délai d'une requête web.
     */
    private function buildCatalogue(): string
    {
        $products = array_values(array_filter(
            $this->productRepository->findAll(),
            static fn (Product $product): bool => '' !== trim((string) $product->getName()),
        ));
        if ([] === $products) {
            return '';
        }

        $ficheLength = min(self::MAX_FICHE_LENGTH, max(0, intdiv(self::MAX_CATALOGUE_LENGTH, count($products)) - self::PRODUCT_HEADER_LENGTH));

        $blocks = [];
        foreach ($products as $product) {
            $lines = ['### '.trim((string) $product->getName())];
            if ($product->getDescription()) {
                $lines[] = 'Description : '.mb_substr($product->getDescription(), 0, 300);
            }
            if ($product->getKeywords()) {
                $lines[] = 'Mots-clés : '.mb_substr($product->getKeywords(), 0, 300);
            }
            if ($ficheLength >= self::MIN_FICHE_LENGTH && $product->getFiche()) {
                $lines[] = "Fiche (extrait) :\n".mb_substr($product->getFiche(), 0, $ficheLength);
            }
            $blocks[] = implode("\n", $lines);
        }

        return mb_substr(implode("\n\n", $blocks), 0, self::MAX_CATALOGUE_LENGTH);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $response): ?array
    {
        if (!preg_match('/\{[\s\S]*\}/u', $response, $m)) {
            return null;
        }

        try {
            $data = json_decode($m[0], true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Ne garde que les propositions bien formées, d'une catégorie permise et
     * absentes des compétences existantes (actives ou non).
     *
     * @return list<array{category: string, label: string, reason: string}>
     */
    private function sanitize(mixed $suggestions): array
    {
        if (!is_array($suggestions)) {
            return [];
        }

        $known = $this->knownLabels();

        $clean = [];
        foreach ($suggestions as $suggestion) {
            if (!is_array($suggestion)) {
                continue;
            }
            $category = $suggestion['category'] ?? null;
            $label = $suggestion['label'] ?? null;
            if (!is_string($category) || !is_string($label) || !in_array($category, self::SUGGESTABLE, true)) {
                continue;
            }

            $label = trim((string) preg_replace('/\s+/u', ' ', $label));
            $label = trim((string) preg_replace('/^[\-•*]\s*/u', '', $label));
            $key = $this->normalize($label);
            if ('' === $label || mb_strlen($label) > self::MAX_LABEL_LENGTH || isset($known[$key])) {
                continue;
            }
            $known[$key] = true;

            $reason = is_string($suggestion['reason'] ?? null) ? trim($suggestion['reason']) : '';

            $clean[] = [
                'category' => $category,
                'label' => $label,
                'reason' => mb_substr($reason, 0, self::MAX_REASON_LENGTH),
            ];
            if (count($clean) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $clean;
    }

    /**
     * Retire des suggestions celles dont le libellé existe déjà en base.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, array{category: string, label: string, reason: string}> $suggestions
     *
     * @return array<TKey, array{category: string, label: string, reason: string}>
     */
    public function withoutKnown(array $suggestions): array
    {
        $known = $this->knownLabels();

        return array_filter($suggestions, fn (array $suggestion): bool => !isset($known[$this->normalize($suggestion['label'])]));
    }

    /**
     * @return array<string, true> libellés normalisés des compétences en base (actives ou non)
     */
    private function knownLabels(): array
    {
        $known = [];
        foreach ($this->competenceRepository->findAll() as $competence) {
            $known[$this->normalize((string) $competence->getLabel())] = true;
        }

        return $known;
    }

    private function normalize(string $label): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label), ' .;'));
    }
}
