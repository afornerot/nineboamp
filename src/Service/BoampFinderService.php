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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

class BoampFinderService
{
    private const MAX_DETAILS = 30;
    private const MIN_SCORE_NOTIFY = 50;
    private const MIN_DAYS_DEADLINE = 30;
    private const MAX_KEYWORDS = 50;
    private const PAGE_SIZE = 100;
    private const MAX_RESULTS = 1000;
    private const MAX_REASON_WIDTH = 200;

    // La recherche BOAMP porte sur des mots entiers : un acronyme ne trouve pas sa forme développée.
    private const KEYWORD_EXPANSIONS = [
        'ia' => 'intelligence artificielle',
    ];

    public function __construct(
        private BoampApiService $boampApi,
        private ScoringAgentService $scoringAgent,
        private RocketChatNotifier $notifier,
        private ProductRepository $productRepository,
        private MarketRepository $marketRepository,
        private MarketProductRepository $marketProductRepository,
        private BoampReportRepository $reportRepository,
        private ProductMetadataExtractor $metadataExtractor,
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
            $io?->writeln(sprintf('  → <info>%d</info> mot(s)-clé(s) unique(s) généré(s) (max %d transmis à l\'API).', count($keywords), self::MAX_KEYWORDS));
            $io?->writeln(sprintf('  → Échantillon : <comment>%s</comment>', implode(', ', array_slice($keywords, 0, 8))));
            $this->logger->info('BoampFinderService: mots-clés générés', [
                'keywords_count' => count($keywords),
                'keywords_sample' => array_slice($keywords, 0, 10),
            ]);

            $io?->section('3/5 — Recherche OpenDataSoft (BOAMP)');
            $results = $this->fetchResults($keywords);
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

            // Écartés avant de remplir les MAX_DETAILS places : sinon les marchés déjà
            // scorés les occupent à chaque passage et les plus anciens ne passent jamais.
            $scoredIdwebs = array_flip($this->marketRepository->findScoredIdwebs(array_column($candidates, 'idweb')));
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $c): bool => !isset($scoredIdwebs[$c['idweb']]),
            ));
            $skippedExisting = $candidatesCount - count($candidates);
            $io?->writeln(sprintf('  → <info>%d</info> déjà scoré(s), <info>%d</info> à scorer.', $skippedExisting, count($candidates)));

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

                $existing = $this->marketRepository->findOneBy(['idweb' => $idweb]);
                if ($existing) {
                    if (null !== $existing->getScore() && Market::STATUS_DETECTED !== $existing->getStatus()) {
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
                    $progress?->setMessage(sprintf('<error>%s — scoring KO : %s</error>', $idweb, OutputFormatter::escape($this->shortReason($e->getMessage()))));
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
                    'id' => $market->getId(),
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

            $io?->section('4b/5 — Scoring des marchés détectés en base');
            $detectedMarkets = $this->marketRepository->findBy(['status' => Market::STATUS_DETECTED]);
            $io?->writeln(sprintf('  → %d marché(x) en statut "détecté" à rescorer…', count($detectedMarkets)));

            foreach ($detectedMarkets as $detectedMarket) {
                $idweb = $detectedMarket->getIdweb();
                $rawData = $detectedMarket->getRawData();
                if (null === $rawData) {
                    continue;
                }

                try {
                    $details = json_decode($rawData, true);
                    if (!is_array($details)) {
                        continue;
                    }
                    $details = array_merge($details, ['objet' => $detectedMarket->getTitle()]);
                } catch (\Throwable) {
                    continue;
                }

                try {
                    $scored = $this->scoreAndQualify($detectedMarket, $details, $products);
                } catch (\Throwable $e) {
                    $this->logger->warning('BoampFinderService: scoring failed pour marché détecté', [
                        'idweb' => $idweb,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                if (null === $scored) {
                    continue;
                }

                [$market, $marketProducts] = $scored;
                $this->em->persist($market);
                foreach ($marketProducts as $mp) {
                    $this->em->persist($mp);
                }
                $this->em->flush();

                ++$marketsQualified;
                $io?->writeln(sprintf('  → <info>%s</info> rescored %d/100 (priorité %s)', $idweb, $market->getScore() ?? 0, $market->getPriority() ?? '?'));
            }

            $io?->section('5/5 — Persistance du rapport');
            $report->setMarketsFound($marketsFound);
            $report->setMarketsQualified($marketsQualified);
            $report->setMarketsNotified($marketsNotified);
            $report->setQualifiedMarketsJson(json_encode($qualifiedMarkets, \JSON_UNESCAPED_UNICODE));
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
            foreach ($meta['keywords'] as $keyword) {
                $keywords[] = $keyword;
                $expansion = self::KEYWORD_EXPANSIONS[mb_strtolower($keyword)] ?? null;
                if (null !== $expansion) {
                    $keywords[] = $expansion;
                }
            }
        }

        // L'API ignore la casse : « IA » et « ia » occuperaient deux places pour rien.
        $unique = [];
        foreach ($keywords as $keyword) {
            $unique[mb_strtolower($keyword)] ??= $keyword;
        }

        return array_slice(array_values($unique), 0, self::MAX_KEYWORDS);
    }

    /**
     * @param string[] $keywords
     *
     * @return array<array<string, mixed>>
     */
    private function fetchResults(array $keywords): array
    {
        $minDeadline = (new \DateTime())->modify('+'.self::MIN_DAYS_DEADLINE.' days');
        $results = [];

        // Toutes les pages, pas seulement les 100 avis les plus récents : sinon un
        // marché publié quelques jours avant le passage n'est jamais vu.
        for ($offset = 0; $offset < self::MAX_RESULTS; $offset += self::PAGE_SIZE) {
            $page = $this->boampApi->searchMarkets(
                $keywords,
                self::PAGE_SIZE,
                'dateparution DESC, idweb DESC',
                offset: $offset,
                minDeadline: $minDeadline,
            );
            $results = array_merge($results, $page);
            if (count($page) < self::PAGE_SIZE) {
                return $results;
            }
        }

        $this->logger->warning('BoampFinderService: plafond de résultats atteint, les avis plus anciens sont ignorés', [
            'max_results' => self::MAX_RESULTS,
        ]);

        return $results;
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
     * Raison d'échec tenant sur une ligne de la barre de progression : une erreur de
     * l'agent embarque parfois le corps HTTP complet (JSON, page HTML). Le message
     * entier reste dans le log.
     */
    private function shortReason(string $message): string
    {
        return mb_strimwidth(trim(preg_replace('/\s+/', ' ', $message) ?? ''), 0, self::MAX_REASON_WIDTH, '…');
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
        $description = $this->extractDescription($details);
        $deadlineRaw = $details['datelimitereponse'] ?? null;
        $amount = $this->formatAmount($details);

        $productsData = [];
        foreach ($products as $p) {
            $productsData[] = [
                'id' => $p->getId(),
                'name' => $p->getName() ?? '',
                'description' => $p->getDescription() ?? '',
                'keywords' => $p->getKeywords() ?? '',
                'sectors' => $p->getSectors() ?? '',
            ];
        }

        $this->em->flush();

        // Lève avec sa raison si l'agent n'a pas créé le job : l'appelant l'affiche.
        $started = $this->scoringAgent->startScoringJob(
            $market->getId(),
            $market->getIdweb() ?? '',
            $title ?? '',
            $buyer,
            $description,
            $amount,
            $deadlineRaw,
            $productsData
        );

        $jobId = $started['job_id'];
        $job = $this->scoringAgent->waitForResult($jobId, $started['timeout']);

        if (!$job->isDone()) {
            // Le LLM a répondu, mais de façon inexploitable : « IA non exploitable ».
            if ('parse' === $job->code()) {
                $this->logger->warning('BoampFinderService: réponse IA non exploitable', ['idweb' => $market->getIdweb(), 'job_id' => $jobId]);

                return null;
            }

            // API en échec, délai dépassé, job perdu : l'appelant l'affiche avec sa raison.
            throw new \RuntimeException(sprintf('%s (job %s)', $job->error, $jobId));
        }

        $market->setDescription($description);
        $market->setDeadline($deadlineRaw ? new \DateTime($deadlineRaw) : null);
        $market->setAmount($amount);
        $market->setStatus(Market::STATUS_QUALIFIED);

        return [$market, $this->applyScoringResult($market, $job->result, $products)];
    }

    /**
     * Applique un résultat de scoring : score/priorité/explication du marché et
     * lignes produit (les lignes précédentes du marché sont remplacées).
     *
     * @param array<string, mixed> $result    payload result du job score
     * @param Product[]|null       $catalogue catalogue de résolution (tous les produits par défaut)
     *
     * @return MarketProduct[]
     *
     * @throws \RuntimeException si aucun produit du catalogue n'est fourni ni disponible
     */
    public function applyScoringResult(Market $market, array $result, ?array $catalogue = null): array
    {
        $catalogue ??= $this->productRepository->findAll();
        if (0 === count($catalogue)) {
            throw new \RuntimeException('Aucun produit en base.');
        }

        $market->setScore(isset($result['score']) && is_numeric($result['score']) ? (int) $result['score'] : 0);
        $market->setPriority(isset($result['priority']) && is_string($result['priority']) ? $result['priority'] : 'C');
        $market->setExplanation(isset($result['explanation']) && is_string($result['explanation']) ? $result['explanation'] : null);

        $existing = null !== $market->getId() ? $this->marketProductRepository->findByMarket($market->getId()) : [];

        $created = [];
        foreach ($result['products'] ?? [] as $payload) {
            if (!is_array($payload)) {
                continue;
            }
            $product = $this->findProduct($catalogue, $payload);
            if (null === $product) {
                continue;
            }

            $mp = new MarketProduct();
            $mp->setMarket($market);
            $mp->setProduct($product);
            $mp->setScore(isset($payload['score']) && is_numeric($payload['score']) ? (int) $payload['score'] : null);
            $mp->setPriority(isset($payload['priority']) ? (string) $payload['priority'] : null);
            $mp->setRelevance(isset($payload['relevance']) ? (string) $payload['relevance'] : null);
            $mp->setScoreDetails(isset($payload['scoreDetails']) ? (string) $payload['scoreDetails'] : null);
            $market->addMarketProduct($mp);
            $this->em->persist($mp);
            $created[] = $mp;
        }

        foreach ($existing as $mp) {
            $market->removeMarketProduct($mp);
            $this->em->remove($mp);
        }

        $this->em->persist($market);
        $this->em->flush();

        return $created;
    }

    /**
     * Résout un produit du scoring par son ID, à défaut par son nom exact.
     *
     * @param Product[]            $catalogue
     * @param array<string, mixed> $payload entrée products[] du scoring
     */
    private function findProduct(array $catalogue, array $payload): ?Product
    {
        $id = isset($payload['id']) && is_numeric($payload['id']) ? (int) $payload['id'] : null;
        if (null !== $id) {
            foreach ($catalogue as $product) {
                if ($product->getId() === $id) {
                    return $product;
                }
            }
        }

        $name = mb_strtolower(trim((string) ($payload['name'] ?? '')));
        if ('' !== $name) {
            foreach ($catalogue as $product) {
                if (mb_strtolower(trim($product->getName() ?? '')) === $name) {
                    return $product;
                }
            }
        }

        $this->logger->warning('BoampFinderService: produit du scoring introuvable, ligne ignorée', [
            'id' => $payload['id'] ?? null,
            'name' => $payload['name'] ?? null,
        ]);

        return null;
    }

    /**
     * Description d'un avis BOAMP selon son schéma (FNSimple, eForms, MAPA, ancien
     * format) ; à défaut ses descripteurs, puis son objet.
     *
     * @param array<string, mixed> $details
     */
    public function extractDescription(array $details): string
    {
        $donnees = $this->parseDonnees($details);
        $candidates = [
            $donnees['FNSimple']['initial']['natureMarche']['description'] ?? null,
            $donnees['EFORMS']['ContractNotice']['cac:ProcurementProject']['cbc:Description']['#text'] ?? null,
            $donnees['MAPA']['initial']['caracteristiques']['principales'] ?? null,
            $donnees['MAPA']['initial']['description']['objet'] ?? null,
            $donnees['OBJET']['OBJET_COMPLET'] ?? null,
            $donnees['OBJET']['description'] ?? null,
            $details['descripteur_libelle'] ?? null,
            $details['objet'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $description = $this->stringifyValue($candidate);
            if ('' !== trim($description)) {
                return $description;
            }
        }

        return '';
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
     * imbriquées au lieu d'un libellé simple. Une liste de libellés (ex.
     * descripteur_libelle) est jointe par des virgules plutôt qu'encodée en JSON.
     */
    private function stringifyValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (null === $value) {
            return '';
        }
        if (is_array($value) && array_is_list($value) && $value === array_filter($value, 'is_scalar')) {
            return implode(', ', $value);
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

        $status = $market->getStatus();

        $scored = $this->scoreAndQualify($market, $details, $products);
        if (null === $scored) {
            throw new \RuntimeException('Le scoring IA n\'a pas retourné de résultat exploitable.');
        }

        [$market] = $scored;

        // Le statut posé par l'utilisateur (go, nogo, répondu…) survit à la requalification.
        if (Market::STATUS_DETECTED !== $status) {
            $market->setStatus($status);
        }

        $this->em->persist($market);
        $this->em->flush();

        return $market;
    }
}
