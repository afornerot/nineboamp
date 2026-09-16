<?php

namespace App\Service;

use App\Entity\BoampReport;
use App\Entity\Market;
use App\Entity\MarketProduct;
use App\Entity\Product;
use App\Repository\BoampReportRepository;
use App\Repository\MarketProductRepository;
use App\Repository\MarketRepository;
use App\Repository\ProductRepository;
use App\Repository\ScoringPromptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class BoampFinderService
{
    private const MAX_DETAILS = 30;
    private const MIN_SCORE_NOTIFY = 50;
    private const MIN_DAYS_DEADLINE = 30;

    public function __construct(
        private BoampApiService $boampApi,
        private AiService $ai,
        private RocketChatNotifier $notifier,
        private ProductRepository $productRepository,
        private MarketRepository $marketRepository,
        private MarketProductRepository $marketProductRepository,
        private ScoringPromptRepository $scoringPromptRepository,
        private BoampReportRepository $reportRepository,
        private ProductMetadataExtractor $metadataExtractor,
        private PromptLoader $promptLoader,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function run(?SymfonyStyle $io = null): BoampRunResult
    {
        $start = microtime(true);
        $report = new BoampReport();

        $marketsFound = 0;
        $marketsQualified = 0;
        $marketsNotified = 0;
        $skippedExisting = 0;
        $skippedNoIdweb = 0;
        $skippedNoDetails = 0;
        $skippedParseError = 0;
        $skippedScoringError = 0;
        $qualifiedMarkets = [];
        $candidatesCount = 0;

        $io?->section('1/5 — Collecte des produits');
        try {
            $products = $this->productRepository->findAll();
            if (0 === count($products)) {
                throw new \RuntimeException('Aucun produit en base. Ajoutez des fiches produit avant de lancer la recherche.');
            }
            $io?->writeln(sprintf('  → <info>%d</info> produit(s) chargé(s).', count($products)));

            $this->logger->info('BoampFinderService: démarrage', [
                'products_count' => count($products),
            ]);

            $io?->section('2/5 — Génération des mots-clés');
            $keywords = $this->collectKeywords($products);
            $io?->writeln(sprintf('  → <info>%d</info> mot(s)-clé(s) unique(s) généré(s) (max 50 transmis à l\'API).', count($keywords)));
            $io?->writeln(sprintf('  → Échantillon : <comment>%s</comment>', implode(', ', array_slice($keywords, 0, 8))));
            $this->logger->info('BoampFinderService: mots-clés générés', [
                'keywords_count' => count($keywords),
                'keywords_sample' => array_slice($keywords, 0, 10),
            ]);

            $io?->section('3/5 — Recherche OpenDataSoft (BOAMP)');
            $results = $this->boampApi->searchMarkets($keywords, 100, 'dateparution DESC');
            $io?->writeln(sprintf('  → <info>%d</info> résultat(s) brut(s) renvoyé(s) par l\'API.', count($results)));
            $this->logger->info('BoampFinderService: résultats API bruts', [
                'results_count' => count($results),
            ]);

            $candidates = $this->filterCandidates($results);
            $candidatesCount = count($candidates);
            $io?->writeln(sprintf('  → <info>%d</info> candidat(s) retenu(s) après filtre deadline (≥ +%d jours).', $candidatesCount, self::MIN_DAYS_DEADLINE));
            $this->logger->info('BoampFinderService: candidats après filtre deadline', [
                'candidates_count' => $candidatesCount,
            ]);

            $io?->section(sprintf('4/5 — Scoring IA (max %d marchés détaillés)', self::MAX_DETAILS));
            $toScore = array_slice($candidates, 0, self::MAX_DETAILS);
            $io?->writeln(sprintf('  → Scoring de <info>%d</info> marché(s)…', count($toScore)));

            $report->setMarketsFound($marketsFound);
            $report->setMarketsQualified($marketsQualified);
            $report->setMarketsNotified($marketsNotified);
            $this->em->persist($report);
            $this->em->flush();
            $io?->writeln(sprintf('  → Rapport #%d créé, prêt pour le scoring.', $report->getId()));

            $progress = null;
            if (null !== $io) {
                $progress = $io->createProgressBar(max(1, count($toScore)));
                $progress->setFormat('  %current%/%max% [%bar%] %percent:3s%% (%elapsed:6s% / %estimated:-6s%) — %message%');
                $progress->setMessage('démarrage…');
                $progress->start();
            }
            $processed = 0;
            foreach ($toScore as $candidate) {
                ++$marketsFound;
                $idweb = $candidate['idweb'] ?? null;
                if (!$idweb) {
                    ++$skippedNoIdweb;
                    $progress?->setMessage('<comment>sans idweb</comment>');
                    $progress?->advance();
                    continue;
                }

                $progress?->setMessage(sprintf('idweb=%s', $idweb));

                $details = $this->boampApi->getMarketDetails($idweb);
                if (!$details) {
                    ++$skippedNoDetails;
                    $progress?->setMessage(sprintf('%s — détails indisponibles', $idweb));
                    $progress?->advance();
                    continue;
                }

                $existing = $this->marketRepository->find($idweb);
                if ($existing) {
                    if (null !== $existing->getScore()) {
                        ++$skippedExisting;
                        $progress?->setMessage(sprintf('%s — déjà scoré, skip', $idweb));
                        $progress?->advance();
                        continue;
                    }
                    $market = $existing;
                } else {
                    $market = new Market();
                    $market->setIdweb($idweb);
                    $market->setTitle($details['objet'] ?? 'Sans titre');
                    $market->setBuyer($details['nomacheteur'] ?? 'Information non disponible');
                    $market->setUrl("https://www.boamp.fr/pages/avis/?q=idweb:{$idweb}");
                    $market->setRawData(json_encode($details));
                    $market->setDeadline(
                        ($details['datelimitereponse'] ?? null) ? new \DateTime($details['datelimitereponse']) : null
                    );
                    $market->setStatus(Market::STATUS_DETECTED);
                    $this->em->persist($market);
                    $this->em->flush();
                }

                try {
                    $scored = $this->scoreAndQualify($market, $details, $products);
                } catch (\Throwable $e) {
                    ++$skippedScoringError;
                    $this->logger->warning('BoampFinderService: scoring failed pour ce marché', [
                        'idweb' => $idweb,
                        'error' => $e->getMessage(),
                    ]);
                    $progress?->setMessage(sprintf('<error>%s — scoring KO</error>', $idweb));
                    $progress?->advance();
                    sleep(2);
                    continue;
                }
                if (null === $scored) {
                    ++$skippedParseError;
                    $progress?->setMessage(sprintf('%s — IA non exploitable', $idweb));
                    $progress?->advance();
                    sleep(2);
                    continue;
                }

                [$market, $marketProducts] = $scored;
                $this->em->persist($market);
                foreach ($marketProducts as $mp) {
                    $this->em->persist($mp);
                }
                $this->em->flush();

                ++$marketsQualified;
                ++$processed;
                $progress?->setMessage(sprintf(
                    '<info>%s</info> scored %d/100 (priorité %s)',
                    $idweb,
                    $market->getScore() ?? 0,
                    $market->getPriority() ?? '?',
                ));
                $progress?->advance();

                $report->setMarketsFound($marketsFound);
                $report->setMarketsQualified($marketsQualified);
                $report->setMarketsNotified($marketsNotified);
                $this->em->flush();
                $qualifiedMarkets[] = [
                    'idweb' => $market->getIdweb(),
                    'title' => $market->getTitle(),
                    'score' => $market->getScore() ?? 0,
                    'priority' => $market->getPriority() ?? '?',
                    'buyer' => $market->getBuyer() ?? 'N/A',
                    'deadline' => $market->getDeadline()?->format('Y-m-d'),
                ];

                sleep(2);

                /*
                if ($market->getScore() >= self::MIN_SCORE_NOTIFY) {
                    $notified = $this->notifyIfNew($market, $marketProducts);
                    if ($notified) {
                        $marketsNotified++;
                    }
                }
                */
            }
            $progress?->finish();
            $io?->newLine();
            $io?->writeln(sprintf('  → %d marché(s) qualifié(s), %d ignoré(s).', $processed, $marketsFound - $processed));

            $io?->section('5/5 — Persistance du rapport');
            $report->setMarketsFound($marketsFound);
            $report->setMarketsQualified($marketsQualified);
            $report->setMarketsNotified($marketsNotified);
        } catch (\Throwable $e) {
            $io?->error('Erreur durant le run : '.$e->getMessage());
            $report->setError($e->getMessage());
            $this->logger->error('BoampFinderService: erreur', ['error' => $e->getMessage()]);
        }

        $duration = new \DateTime();
        $duration->setTime(0, 0, (int) (microtime(true) - $start));
        $report->setDuration($duration);

        $this->em->persist($report);
        $this->em->flush();
        $io?->writeln(sprintf('  → Rapport #%d persisté (durée %ss).', $report->getId(), number_format(microtime(true) - $start, 2)));

        return new BoampRunResult(
            $report,
            $candidatesCount,
            $skippedExisting,
            $skippedNoIdweb,
            $skippedNoDetails,
            $skippedParseError,
            $skippedScoringError,
            $qualifiedMarkets,
        );
    }

    /**
     * @param Product[] $products
     *
     * @return string[]
     */
    private function collectKeywords(array $products): array
    {
        $keywords = [];

        foreach ($products as $product) {
            $meta = $this->metadataExtractor->extractMissing($product);
            $keywords = array_merge($keywords, $meta['keywords']);
        }

        $keywords = array_values(array_unique($keywords));

        return array_slice($keywords, 0, 50);
    }

    /**
     * @param array<array<string, mixed>> $results
     *
     * @return array<array<string, mixed>>
     */
    private function filterCandidates(array $results): array
    {
        $now = new \DateTime();
        $minDeadline = (clone $now)->modify('+'.self::MIN_DAYS_DEADLINE.' days');

        return array_values(array_filter($results, function ($r) use ($minDeadline) {
            $idweb = $r['idweb'] ?? null;
            if (!$idweb) {
                return false;
            }

            if (isset($r['datelimitereponse'])) {
                try {
                    $deadline = new \DateTime($r['datelimitereponse']);
                    if ($deadline < $minDeadline) {
                        return false;
                    }
                } catch (\Exception) {
                }
            }

            return true;
        }));
    }

    /**
     * @param Product[] $products
     *
     * @return array{Market, MarketProduct[]}|null
     */
    private function scoreAndQualify(Market $market, array $details, array $products): ?array
    {
        $title = $market->getTitle();
        $buyer = $market->getBuyer() ?? 'Information non disponible';
        $donnees = $this->parseDonnees($details);
        $description = $donnees['FNSimple']['initial']['natureMarche']['description']
            ?? $donnees['EFORMS']['ContractNotice']['cac:ProcurementProject']['cbc:Description']['#text']
            ?? $donnees['OBJET']['OBJET_COMPLET']
            ?? $donnees['OBJET']['description']
            ?? '';
        if ('' === trim($description)) {
            $description = $this->stringifyValue($details['descripteur_libelle'] ?? '');
        }
        if ('' === trim($description)) {
            $description = $this->stringifyValue($details['objet'] ?? '');
        }
        $deadlineRaw = $details['datelimitereponse'] ?? null;
        $amount = $this->formatAmount($details);
        $url = $market->getUrl() ?? "https://www.boamp.fr/pages/avis/?q=idweb:{$market->getIdweb()}";

        $prompt = $this->buildScoringPrompt($products, $market->getIdweb(), $title, $buyer, $description, $amount, $deadlineRaw, $details);

        $role = $this->getScoringRole();
        $userPrompt = $this->promptLoader->load('scoring.user');
        $temperature = $userPrompt?->getTemperature() ?? 0.2;
        $maxTokens = $userPrompt?->getMaxTokens() ?? 4096;
        $response = $this->ai->ask($prompt, $role, $temperature, $maxTokens);

        if ('' === $response) {
            return null;
        }

        $parsed = $this->parseScoringResponse($response);

        if (null === $parsed || !isset($parsed['score'], $parsed['priority'], $parsed['products'])) {
            return null;
        }

        $this->recalculateGlobalScore($parsed);

        $market->setDescription($description);
        $market->setDeadline($deadlineRaw ? new \DateTime($deadlineRaw) : null);
        $market->setAmount($amount);
        $market->setScore($parsed['score']);
        $market->setPriority($parsed['priority']);
        $market->setExplanation($parsed['explanation'] ?? null);
        $market->setStatus(Market::STATUS_QUALIFIED);

        $marketProducts = [];
        foreach ($parsed['products'] as $p) {
            $mp = new MarketProduct();
            $mp->setMarket($market);
            $productEntity = $this->findProductByName($products, $p['name']);
            if ($productEntity) {
                $mp->setProduct($productEntity);
            }
            $mp->setScore($p['score'] ?? null);
            $mp->setPriority($p['priority'] ?? null);
            $mp->setRelevance($p['relevance'] ?? null);
            $mp->setScoreDetails($p['scoreDetails'] ?? null);
            $market->addMarketProduct($mp);
            $marketProducts[] = $mp;
        }

        return [$market, $marketProducts];
    }

    /**
     * @param Product[] $products
     */
    private function findProductByName(array $products, string $name): ?Product
    {
        foreach ($products as $p) {
            if ($p->getName() === $name) {
                return $p;
            }
        }

        return $products[0] ?? null;
    }

    private function getScoringRole(): string
    {
        $filePrompt = $this->promptLoader->renderSystem('scoring.role');
        if ('' !== $filePrompt) {
            return $filePrompt;
        }

        $dbPrompt = $this->scoringPromptRepository->findLatest();

        return $dbPrompt?->getRole() ?? 'Tu es un expert en qualification de marchés publics français (BOAMP). Pour chaque marché, tu évalues la correspondance avec les produits de l\'entreprise.';
    }

    /**
     * @param Product[]            $products
     * @param array<string, mixed> $details
     */
    private function buildScoringPrompt(array $products, string $idweb, string $title, string $buyer, string $description, string $amount, ?string $deadline, array $details): string
    {
        $description = $this->stringifyValue($description);

        $catalogue = '';
        foreach ($products as $p) {
            $short = $p->getDescription() ?: ($p->getName() ?? '');
            $keywords = $p->getKeywords() ?: '';
            $sectors = $p->getSectors() ?: '';
            $catalogue .= "- **{$p->getName()}**\n  Description: {$short}\n  Mots-clés: {$keywords}\n  Secteurs: {$sectors}\n\n";
        }

        $rawText = '';
        foreach ($details as $k => $v) {
            if (is_string($v) && 'donnees' !== $k && 'gestion' !== $k && strlen($v) < 2000) {
                $rawText .= "{$k}: {$v}\n";
            }
        }

        $donnees = $this->parseDonnees($details);
        if ([] !== $donnees) {
            $objetComplet = $donnees['FNSimple']['initial']['natureMarche']['description']
                ?? $donnees['EFORMS']['ContractNotice']['cac:ProcurementProject']['cbc:Description']['#text']
                ?? $donnees['OBJET']['OBJET_COMPLET']
                ?? $donnees['OBJET']['description']
                ?? '';
            if ('' !== trim($objetComplet)) {
                $rawText .= "Objet complet: {$objetComplet}\n";
            }
            $intitule = $donnees['FNSimple']['initial']['natureMarche']['intitule']
                ?? $donnees['EFORMS']['ContractNotice']['cac:ProcurementProject']['cbc:Name']['#text']
                ?? '';
            if ('' !== trim($intitule)) {
                $rawText .= "Intitulé: {$intitule}\n";
            }
            $criteres = $donnees['FNSimple']['initial']['procedure']['criteresAttrib']
                ?? $donnees['PROCEDURE']['CRITERES_ATTRIBUTION']['CRITERES_LIBRE']
                ?? '';
            if ('' !== trim($criteres)) {
                $rawText .= "Critères d'attribution: {$criteres}\n";
            }
            $capTech = $donnees['FNSimple']['initial']['procedure']['capaciteTech']
                ?? $donnees['PROCEDURE']['CONDITION_PARTICIPATION']['CAP_TECH']
                ?? '';
            if ('' !== trim($capTech)) {
                $rawText .= "Capacité technique: {$capTech}\n";
            }
            $lieu = $donnees['FNSimple']['initial']['natureMarche']['lieuExecution'] ?? '';
            if ('' !== trim($lieu)) {
                $rawText .= "Lieu d'exécution: {$lieu}\n";
            }
            $duree = $donnees['FNSimple']['initial']['natureMarche']['dureeMois'] ?? '';
            if ('' !== trim($duree)) {
                $rawText .= "Durée (mois): {$duree}\n";
            }
            $valeur = $donnees['FNSimple']['initial']['natureMarche']['valeurEstimee']['valeur'] ?? '';
            if ('' !== trim($valeur)) {
                $rawText .= "Valeur estimée: {$valeur} EUR\n";
            }

            if (isset($donnees['EFORMS']['ContractNotice']['cac:ProcurementProjectLot'])) {
                $lots = $donnees['EFORMS']['ContractNotice']['cac:ProcurementProjectLot'];
                if (is_array($lots)) {
                    foreach ($lots as $i => $lot) {
                        $lotName = $lot['cac:ProcurementProject']['cbc:Name']['#text'] ?? '';
                        $lotDesc = $lot['cac:ProcurementProject']['cbc:Description']['#text'] ?? '';
                        if ('' !== trim($lotName) || '' !== trim($lotDesc)) {
                            $rawText .= "Lot ".($i + 1).": {$lotName}";
                            if ('' !== trim($lotDesc)) {
                                $rawText .= " — {$lotDesc}";
                            }
                            $rawText .= "\n";
                        }
                    }
                }
            }
        }

        $rendered = $this->promptLoader->renderUser('scoring.user', [
            'idweb' => $idweb,
            'title' => $title,
            'buyer' => $buyer,
            'description' => $description,
            'amount' => $amount,
            'deadline' => (string) ($deadline ?? ''),
            'rawText' => $rawText,
            'catalogue' => $catalogue,
        ]);

        if ('' !== $rendered) {
            return $rendered;
        }

        return $this->buildScoringPromptFallback($idweb, $title, $buyer, $description, $amount, (string) ($deadline ?? ''), $rawText, $catalogue);
    }

    /**
     * Fallback dur inline si le fichier prompt manque. Reproduit l'ancien prompt.
     */
    private function buildScoringPromptFallback(
        string $idweb,
        string $title,
        string $buyer,
        string $description,
        string $amount,
        string $deadline,
        string $rawText,
        string $catalogue,
    ): string {
        return "Voici le marché à scorer :\n\n"
            ."IDWEB: {$idweb}\n"
            ."Titre: {$title}\n"
            ."Acheteur: {$buyer}\n"
            ."Description: {$description}\n"
            ."Montant: {$amount}\n"
            ."Date limite: {$deadline}\n\n"
            ."Données brutes BOAMP:\n{$rawText}\n\n"
            ."Catalogue produits de l'entreprise:\n{$catalogue}\n\n"
            ."Donne-moi uniquement un JSON sans aucun texte avant ou après, avec ce format exact :\n"
            ."{\n"
            .'  "score": <note 0-100>,'."\n"
            .'  "priority": "<A si score>=80, B si >=60, C sinon>",'."\n"
            ."  \"products\": [\n"
            ."    {\n"
            .'      "name": "<nom du produit>",'."\n"
            .'      "score": <note 0-100>,'."\n"
            .'      "priority": "<A/B/C>",'."\n"
            .'      "relevance": "<2-3 phrases d\'explication>",'."\n"
            .'      "scoreDetails": "<détail des sous-scores: fonctionnel/40, metier/20, technique/20, commercial/10, complexite/10>"'."\n"
            ."    }\n"
            ."  ]\n"
            ."}\n\n"
            ."Règles : score global = moyenne pondérée des produits. Cite des éléments factuels du marché et des fiches. Si un produit ne matche pas, score=0. Information non disponible = neutre.";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseScoringResponse(string $response): ?array
    {
        $json = trim($response);

        $firstBrace = strpos($json, '{');
        $lastBrace = strrpos($json, '}');

        if (false === $firstBrace || false === $lastBrace || $lastBrace <= $firstBrace) {
            return null;
        }

        $json = substr($json, $firstBrace, $lastBrace - $firstBrace + 1);

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!isset($data['score'], $data['priority'], $data['products'])) {
            return null;
        }

        $data['score'] = max(0, min(100, (int) ($data['score'] ?? 0)));
        $data['priority'] = strtoupper(substr($data['priority'], 0, 1));
        if (!in_array($data['priority'], ['A', 'B', 'C'], true)) {
            $data['priority'] = 'C';
        }

        foreach ($data['products'] as &$p) {
            $p['score'] = max(0, min(100, (int) ($p['score'] ?? 0)));
            $p['priority'] = strtoupper(substr((string) ($p['priority'] ?? 'C'), 0, 1));
            if (!in_array($p['priority'], ['A', 'B', 'C'], true)) {
                $p['priority'] = 'C';
            }
        }

        return $data;
    }

    /**
     * Recalcule le score global : meilleur score produit + bonus multi-produits.
     *
     * @param array<string, mixed> $parsed
     */
    private function recalculateGlobalScore(array &$parsed): void
    {
        $scores = array_column($parsed['products'], 'score');
        if ([] === $scores) {
            return;
        }

        rsort($scores);

        $base = $scores[0];
        $bonus = 0;
        for ($i = 1, $count = count($scores); $i < $count; ++$i) {
            if ($scores[$i] >= 50) {
                $bonus += ($scores[$i] - 50) * 0.15;
            }
        }

        $parsed['score'] = min(100, (int) round($base + $bonus));

        if ($parsed['score'] >= 80) {
            $parsed['priority'] = 'A';
        } elseif ($parsed['score'] >= 60) {
            $parsed['priority'] = 'B';
        } else {
            $parsed['priority'] = 'C';
        }
    }

    /**
     * Parse le champ donnees (JSON string) retourné par l'API BOAMP.
     *
     * @return array<string, mixed>
     */
    private function parseDonnees(array $details): array
    {
        $raw = $details['donnees'] ?? '';
        if (!is_string($raw) || '' === trim($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function formatAmount(array $details): string
    {
        $donnees = $this->parseDonnees($details);
        $val = $donnees['FNSimple']['initial']['natureMarche']['valeurEstimee']['valeur']
            ?? $donnees['OBJET']['CARACTERISTIQUES']['PRIX_ESTIME']
            ?? $details['valeur_total']
            ?? $details['valeur']
            ?? null;
        if ($val && is_numeric($val)) {
            return number_format((float) $val, 0, ',', ' ').' € HT';
        }

        return 'Information non disponible';
    }

    /**
     * @param MarketProduct[] $marketProducts
     */
    private function notifyIfNew(Market $market, array $marketProducts): bool
    {
        if (Market::STATUS_QUALIFIED !== $market->getStatus()) {
            return false;
        }

        $productNames = array_map(
            fn (MarketProduct $mp) => $mp->getProduct()?->getName() ?? '',
            $marketProducts
        );
        $productNames = array_filter($productNames);

        return $this->notifier->sendMarket(
            $market->getIdweb(),
            $market->getTitle(),
            $market->getPriority(),
            $market->getScore(),
            $market->getBuyer(),
            $market->getDescription(),
            $this->buildRelevanceSummary($marketProducts),
            $market->getDeadline()?->format('Y-m-d') ?? 'Information non disponible',
            $market->getAmount(),
            $productNames,
            $market->getUrl(),
        );
    }

    /**
     * @param MarketProduct[] $marketProducts
     */
    private function buildRelevanceSummary(array $marketProducts): string
    {
        $parts = [];
        foreach ($marketProducts as $mp) {
            $name = $mp->getProduct()?->getName() ?? '';
            $relevance = $mp->getRelevance() ?? '';
            if ($name && $relevance) {
                $parts[] = "{$name}: {$relevance}";
            }
        }

        return implode(' | ', $parts) ?: 'Information non disponible';
    }

    /**
     * Convertit une valeur arbitraire (string, array, object, null) en string.
     * Utilisé pour les champs de l'API BOAMP qui peuvent renvoyer des structures
     * imbriquées au lieu d'un libellé simple (ex. descripteur_libelle).
     */
    private function stringifyValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (null === $value) {
            return '';
        }
        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

            return false !== $encoded ? $encoded : '';
        }

        return (string) $value;
    }

    /**
     * Requalifie un marché unique via le scoring IA.
     * Utilise les rawData déjà stockés en BDD.
     *
     * @throws \RuntimeException si les rawData sont manquantes ou le scoring échoue
     */
    public function rescoreMarket(Market $market): Market
    {
        $rawData = $market->getRawData();
        if (null === $rawData) {
            throw new \RuntimeException('Aucune donnée brute disponible pour ce marché.');
        }

        $details = json_decode($rawData, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($details)) {
            throw new \RuntimeException('Données brutes corrompues pour ce marché.');
        }

        $products = $this->productRepository->findAll();
        if (0 === count($products)) {
            throw new \RuntimeException('Aucun produit en base.');
        }

        // Supprimer les MarketProduct existants
        $existing = $this->marketProductRepository->findByMarket($market->getId());
        foreach ($existing as $mp) {
            $market->removeMarketProduct($mp);
            $this->em->remove($mp);
        }

        $scored = $this->scoreAndQualify($market, $details, $products);
        if (null === $scored) {
            throw new \RuntimeException('Le scoring IA n\'a pas retourné de résultat exploitable.');
        }

        [$market, $marketProducts] = $scored;
        $this->em->persist($market);
        foreach ($marketProducts as $mp) {
            $this->em->persist($mp);
        }
        $this->em->flush();

        return $market;
    }
}
