<?php

namespace App\Tests\Service;

use App\Entity\Product;
use App\Entity\Market;
use App\Service\AiService;
use App\Service\BoampApiService;
use App\Service\BoampFinderService;
use App\Service\ProductMetadataExtractor;
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

        $reflectionAi = new \ReflectionClass($service);
        $aiProp = $reflectionAi->getProperty('ai');
        $aiProp->setAccessible(true);
        $mockAi = $this->createMock(AiService::class);
        $mockAi->method('ask')->willReturn('{"score": 75, "priority": "B", "products": [{"name": "Planigram", "score": 75, "relevance": "Bonne correspondance"}]}');
        $aiProp->setValue($service, $mockAi);

        $result = $method->invoke($service, (new Market())->setIdweb('26-12345')->setTitle('Audit des systèmes d\'information'), $details, [$product]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
    }

    private function buildService(): BoampFinderService
    {
        return new BoampFinderService(
            $this->createMock(BoampApiService::class),
            $this->createMock(AiService::class),
            $this->createMock(\App\Service\RocketChatNotifier::class),
            $this->createMock(\App\Repository\ProductRepository::class),
            $this->createMock(\App\Repository\MarketRepository::class),
            $this->createMock(\App\Repository\MarketProductRepository::class),
            $this->createMock(\App\Repository\BoampReportRepository::class),
            $this->createMock(ProductMetadataExtractor::class),
            $this->createMock(\App\Service\PromptLoader::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
        );
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
