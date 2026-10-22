<?php

namespace App\Tests\Service;

use App\Entity\Product;
use App\Entity\Market;
use App\Entity\MarketProduct;
use App\Repository\MarketProductRepository;
use App\Repository\MarketRepository;
use App\Repository\ProductRepository;
use App\Service\AiService;
use App\Service\BoampApiService;
use App\Service\BoampFinderService;
use App\Service\ProductMetadataExtractor;
use App\Service\ScoringAgentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BoampFinderServiceTest extends TestCase
{
    public function testStringifyValueWithString(): void
    {
        $service = $this->buildService();
        $this->assertSame('hello', $this->callStringify($service, 'hello'));
    }

    public function testStringifyValueWithArray(): void
    {
        $service = $this->buildService();
        $value = ['libelle' => 'Informatique', 'niveau' => 1];
        $this->assertSame('{"libelle":"Informatique","niveau":1}', $this->callStringify($service, $value));
    }

    public function testStringifyValueWithNestedArray(): void
    {
        $service = $this->buildService();
        $value = [
            'descripteur' => [
                'n1' => ['libelle' => 'Services', 'code' => 'A'],
                'n2' => ['libelle' => 'Informatique', 'code' => 'B'],
            ],
        ];
        $result = $this->callStringify($service, $value);
        $this->assertStringContainsString('Informatique', $result);
        $this->assertStringContainsString('Services', $result);
    }

    public function testStringifyValueJoinsListOfLabels(): void
    {
        $service = $this->buildService();
        $value = ['Informatique (prestations de services)', 'Logiciel'];
        $this->assertSame('Informatique (prestations de services), Logiciel', $this->callStringify($service, $value));
    }

    public function testExtractDescriptionReadsMapaCharacteristics(): void
    {
        $details = [
            'objet' => 'Exploitation et maintenance d\'une centrale photovoltaïque',
            'descripteur_libelle' => ['Photovoltaïque', 'Maintenance'],
            'donnees' => json_encode(['MAPA' => ['initial' => [
                'description' => ['objet' => 'Exploitation et maintenance d\'une centrale photovoltaïque'],
                'caracteristiques' => ['principales' => 'La présente consultation concerne l\'exploitation de la centrale.'],
            ]]]),
        ];

        $this->assertSame(
            'La présente consultation concerne l\'exploitation de la centrale.',
            $this->buildService()->extractDescription($details),
        );
    }

    public function testExtractDescriptionFallsBackToMapaObjectWhenCharacteristicsAreEmpty(): void
    {
        // Forme réelle de l'avis 26-101064 : « caracteristiques » est une chaîne vide.
        $details = [
            'objet' => 'La réalisation des prestations pour le déploiement d\'une plateforme d\'aide au conseil agricole',
            'descripteur_libelle' => ['Informatique (prestations de services)', 'Logiciel'],
            'donnees' => json_encode(['MAPA' => ['initial' => [
                'description' => ['objet' => 'Conseil agricole assisté par intelligence artificielle'],
                'caracteristiques' => '',
            ]]]),
        ];

        $this->assertSame(
            'Conseil agricole assisté par intelligence artificielle',
            $this->buildService()->extractDescription($details),
        );
    }

    public function testExtractDescriptionFallsBackToDescriptorsThenObject(): void
    {
        $service = $this->buildService();

        $this->assertSame(
            'Informatique (prestations de services), Logiciel',
            $service->extractDescription(['objet' => 'Titre', 'descripteur_libelle' => ['Informatique (prestations de services)', 'Logiciel']]),
        );
        $this->assertSame('Titre', $service->extractDescription(['objet' => 'Titre', 'descripteur_libelle' => []]));
    }

    public function testStringifyValueWithNull(): void
    {
        $service = $this->buildService();
        $this->assertSame('', $this->callStringify($service, null));
    }

    public function testStringifyValueWithInteger(): void
    {
        $service = $this->buildService();
        $this->assertSame('42', $this->callStringify($service, 42));
    }

    public function testScoreAndQualifyWithArrayDescriptorDoesNotCrash(): void
    {
        $result = $this->scoreAndQualifyWith(['status' => 'done', 'result' => [
            'score' => 75,
            'priority' => 'B',
            'products' => [['name' => 'Planigram', 'score' => 75, 'relevance' => 'Bonne correspondance']],
        ]]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
    }

    public function testScoreAndQualifyUnusableAiResponseIsSkipped(): void
    {
        $result = $this->scoreAndQualifyWith(['status' => 'error', 'error' => 'Impossible de parser la réponse JSON', 'code' => 'parse']);

        $this->assertNull($result);
    }

    public function testScoreAndQualifyAgentFailureCarriesItsReason(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Erreur de connexion à l\'API: context deadline exceeded (job test-job-id)');

        $this->scoreAndQualifyWith(['status' => 'error', 'error' => 'Erreur de connexion à l\'API: context deadline exceeded', 'code' => null]);
    }

    public function testShortReasonFitsOnOneLine(): void
    {
        $reason = $this->callPrivate($this->buildService(), 'shortReason', "Erreur de connexion à l'API: rate limit\nhttp 429: " . str_repeat('x', 500));

        $this->assertStringNotContainsString("\n", $reason);
        $this->assertStringStartsWith("Erreur de connexion à l'API: rate limit http 429: ", $reason);
        $this->assertStringEndsWith('…', $reason);
        $this->assertSame(200, mb_strwidth($reason));
    }

    public function testRunSkipsAlreadyScoredMarketsBeforeFillingSlots(): void
    {
        $productRepository = $this->createMock(ProductRepository::class);
        $productRepository->method('findAll')->willReturn([(new Product())->setName('corpus')]);

        $extractor = $this->createMock(ProductMetadataExtractor::class);
        $extractor->method('extractMissing')->willReturn(['keywords' => ['logiciel'], 'sectors' => [], 'description' => '']);

        $deadline = (new \DateTime('+60 days'))->format(\DATE_ATOM);
        $idweb = static fn (int $i): string => "26-{$i}";
        $results = array_map(static fn (int $i): array => ['idweb' => $idweb($i), 'datelimitereponse' => $deadline], range(1, 40));

        $requested = [];
        $boampApi = $this->createMock(BoampApiService::class);
        $boampApi->method('searchMarkets')->willReturn($results);
        $boampApi->method('getMarketDetails')->willReturnCallback(function (string $id) use (&$requested): ?array {
            $requested[] = $id;

            return null;
        });

        $marketRepository = $this->createMock(MarketRepository::class);
        $marketRepository->method('findScoredIdwebs')->willReturn(array_map($idweb, range(1, 20)));
        $marketRepository->method('findBy')->willReturn([]);

        $result = $this->buildService($boampApi, $productRepository, $marketRepository, $extractor)->run();

        $this->assertSame(40, $result->getCandidatesCount());
        $this->assertSame(20, $result->getSkippedExisting());
        $this->assertSame(array_map($idweb, range(21, 40)), $requested);
    }

    public function testFetchResultsReadsEveryPage(): void
    {
        $page = static fn (int $from, int $count): array => array_map(
            static fn (int $i): array => ['idweb' => "26-{$i}"],
            range($from, $from + $count - 1),
        );

        $offsets = [];
        $boampApi = $this->createMock(BoampApiService::class);
        $boampApi->method('searchMarkets')->willReturnCallback(
            function (array $keywords, int $limit, ?string $sortBy, ?string $type, ?array $departments, int $offset) use (&$offsets, $page): array {
                $offsets[] = $offset;

                return 200 === $offset ? $page(201, 20) : $page($offset + 1, 100);
            },
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $results = $this->callPrivate($this->buildService($boampApi, logger: $logger), 'fetchResults', ['logiciel']);

        $this->assertSame([0, 100, 200], $offsets);
        $this->assertCount(220, $results);
    }

    public function testFetchResultsWarnsWhenCapIsReached(): void
    {
        $boampApi = $this->createMock(BoampApiService::class);
        $boampApi->expects($this->exactly(10))->method('searchMarkets')->willReturn(array_fill(0, 100, ['idweb' => '26-1']));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('plafond'));

        $results = $this->callPrivate($this->buildService($boampApi, logger: $logger), 'fetchResults', ['logiciel']);

        $this->assertCount(1000, $results);
    }

    public function testCollectKeywordsExpandsAcronymAndIgnoresCase(): void
    {
        $keywordsByProduct = [
            'xolo' => ['IA', 'logiciel'],
            'aura' => ['ia', 'Logiciel', 'RGPD'],
        ];
        $extractor = $this->createMock(ProductMetadataExtractor::class);
        $extractor->method('extractMissing')->willReturnCallback(
            static fn (Product $p): array => ['keywords' => $keywordsByProduct[(string) $p->getName()] ?? [], 'sectors' => [], 'description' => ''],
        );

        $keywords = $this->callPrivate(
            $this->buildService(extractor: $extractor),
            'collectKeywords',
            [(new Product())->setName('xolo'), (new Product())->setName('aura')],
        );

        $this->assertSame(['IA', 'intelligence artificielle', 'logiciel', 'RGPD'], $keywords);
    }

    public function testFindProductByNameIgnoresCase(): void
    {
        $corpus = (new Product())->setName('corpus');
        $xolo = (new Product())->setName('xolo');

        $this->assertSame($xolo, $this->callPrivate($this->buildService(), 'findProductByName', [$corpus, $xolo], 'Xolo'));
    }

    public function testRescoreMarketKeepsProductsWhenAgentFails(): void
    {
        $scoringAgent = $this->createMock(ScoringAgentService::class);
        $scoringAgent->method('startScoringJob')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');

        [$service, $market, $old] = $this->buildRescore($scoringAgent, $em);

        try {
            $service->rescoreMarket($market);
            $this->fail('Un scoring sans résultat doit lever une exception.');
        } catch (\RuntimeException) {
        }

        $this->assertSame([$old], $market->getMarketProducts()->getValues());
        $this->assertSame(Market::STATUS_GO, $market->getStatus());
    }

    public function testRescoreMarketReplacesProductsAndKeepsUserStatus(): void
    {
        $scoringAgent = $this->createMock(ScoringAgentService::class);
        $scoringAgent->method('startScoringJob')->willReturn('job-1');
        $scoringAgent->method('waitForResult')->willReturn([
            'score' => 80,
            'priority' => 'A',
            'products' => [['name' => 'corpus', 'score' => 80]],
        ]);
        $em = $this->createMock(EntityManagerInterface::class);

        [$service, $market, $old] = $this->buildRescore($scoringAgent, $em);
        $em->expects($this->once())->method('remove')->with($old);

        $service->rescoreMarket($market);

        $this->assertSame(80, $market->getScore());
        $this->assertSame(Market::STATUS_GO, $market->getStatus());
        $this->assertCount(1, $market->getMarketProducts());
        $this->assertNotContains($old, $market->getMarketProducts());
    }

    private function callPrivate(BoampFinderService $service, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($service, $method))->invoke($service, ...$args);
    }

    /**
     * Lance scoreAndQualify() avec un agent qui annonce 165 s et rend $job.
     */
    private function scoreAndQualifyWith(array $job): ?array
    {
        $service = $this->buildService();

        $product = (new Product())
            ->setName('Planigram')
            ->setKeywords('GED')
            ->setFiche('# Fiche produit');

        $details = [
            'idweb' => '26-12345',
            'objet' => 'Audit des systèmes d\'information',
            'nomacheteur' => 'Ministère X',
            'descripteur_libelle' => ['libelle' => 'Informatique', 'niveau' => 2],
            'datelimitereponse' => '2026-12-31T12:00:00+00:00',
            'montant' => '50000',
            'code_departement' => '75',
        ];

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('scoreAndQualify');
        $method->setAccessible(true);

        $market = (new Market())->setIdweb('26-12345')->setTitle('Audit des systèmes d\'information');
        (new \ReflectionProperty(Market::class, 'id'))->setValue($market, 1);

        $reflectionProp = new \ReflectionClass($service);
        $emProp = $reflectionProp->getProperty('em');
        $emProp->setAccessible(true);
        $mockEm = $this->createMock(EntityManagerInterface::class);
        $emProp->setValue($service, $mockEm);

        $reflectionProp = new \ReflectionClass($service);
        $scoringProp = $reflectionProp->getProperty('scoringAgent');
        $scoringProp->setAccessible(true);
        $mockScoring = $this->createMock(\App\Service\ScoringAgentService::class);
        $mockScoring->method('startScoringJob')->willReturn(['job_id' => 'test-job-id', 'timeout' => 165]);
        $mockScoring->expects($this->once())->method('waitForResult')->with('test-job-id', 165)->willReturn($job);
        $scoringProp->setValue($service, $mockScoring);

        return $method->invoke($service, $market, $details, [$product]);
    }

    private function buildService(
        ?BoampApiService $boampApi = null,
        ?ProductRepository $productRepository = null,
        ?MarketRepository $marketRepository = null,
        ?ProductMetadataExtractor $extractor = null,
        ?LoggerInterface $logger = null,
        ?ScoringAgentService $scoringAgent = null,
        ?MarketProductRepository $marketProductRepository = null,
        ?EntityManagerInterface $em = null,
    ): BoampFinderService {
        return new BoampFinderService(
            $boampApi ?? $this->createMock(BoampApiService::class),
            $scoringAgent ?? $this->createMock(ScoringAgentService::class),
            $this->createMock(\App\Service\RocketChatNotifier::class),
            $productRepository ?? $this->createMock(ProductRepository::class),
            $marketRepository ?? $this->createMock(MarketRepository::class),
            $marketProductRepository ?? $this->createMock(MarketProductRepository::class),
            $this->createMock(\App\Repository\BoampReportRepository::class),
            $extractor ?? $this->createMock(ProductMetadataExtractor::class),
            $this->createMock(\App\Service\PromptLoader::class),
            $em ?? $this->createMock(EntityManagerInterface::class),
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * Marché déjà qualifié puis trié par l'utilisateur, avec une correspondance produit.
     *
     * @return array{BoampFinderService, Market, MarketProduct}
     */
    private function buildRescore(ScoringAgentService $scoringAgent, EntityManagerInterface $em): array
    {
        $product = (new Product())->setName('corpus');
        $market = (new Market())
            ->setIdweb('26-101064')
            ->setTitle('Conseil agricole')
            ->setRawData(json_encode(['objet' => 'Conseil agricole']))
            ->setStatus(Market::STATUS_GO);
        (new \ReflectionProperty($market, 'id'))->setValue($market, 42);
        $old = (new MarketProduct())->setProduct($product)->setScore(30);
        $market->addMarketProduct($old);

        $productRepository = $this->createMock(ProductRepository::class);
        $productRepository->method('findAll')->willReturn([$product]);
        $marketProductRepository = $this->createMock(MarketProductRepository::class);
        $marketProductRepository->method('findByMarket')->with(42)->willReturn([$old]);

        $service = $this->buildService(
            productRepository: $productRepository,
            scoringAgent: $scoringAgent,
            marketProductRepository: $marketProductRepository,
            em: $em,
        );

        return [$service, $market, $old];
    }

    private function callStringify(BoampFinderService $service, mixed $value): string
    {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('stringifyValue');
        $method->setAccessible(true);

        return $method->invoke($service, $value);
    }

    public function testScoringPromptUsesLightCatalogueNotFiche(): void
    {
        $service = $this->buildService();

        $product = (new Product())
            ->setName('Planigram')
            ->setDescription('Description courte pour acheteurs publics')
            ->setKeywords('GED,SIRH')
            ->setSectors('Santé,Éducation')
            ->setFiche('# Fiche complète Markdown avec beaucoup de détails techniques...');

        $details = [
            'idweb' => '26-12345',
            'objet' => 'Audit',
            'nomacheteur' => 'Min',
            'datelimitereponse' => '2026-12-31T12:00:00+00:00',
            'montant' => '50000',
        ];

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildScoringPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke(
            $service,
            [$product],
            '26-12345',
            'Audit',
            'Min',
            'desc',
            '50000',
            '2026-12-31',
            $details,
        );

        $this->assertStringContainsString('Planigram', $prompt);
        $this->assertStringContainsString('Description courte pour acheteurs publics', $prompt);
        $this->assertStringContainsString('GED,SIRH', $prompt);
        $this->assertStringContainsString('Santé,Éducation', $prompt);
        $this->assertStringNotContainsString('# Fiche complète Markdown', $prompt);
    }
}
