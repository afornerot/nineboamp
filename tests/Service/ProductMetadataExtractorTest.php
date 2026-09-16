<?php

namespace App\Tests\Service;

use App\Entity\Product;
use App\Service\AiService;
use App\Service\ProductMetadataExtractor;
use App\Service\PromptLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductMetadataExtractorTest extends TestCase
{
    public function testReturnsManualKeywordsWhenPresent(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->expects($this->never())->method('ask');

        $product = (new Product())
            ->setName('MaSolution')
            ->setKeywords('GED, SIRH')
            ->setSectors('Santé, Éducation')
            ->setDescription('Ma description manuelle')
            ->setFiche('# Fiche');

        $extractor = new ProductMetadataExtractor($ai, $this->createMock(LoggerInterface::class), $this->createMock(PromptLoader::class));

        $result = $extractor->extractMissing($product);

        $this->assertSame(['GED', 'SIRH'], $result['keywords']);
        $this->assertSame(['Santé', 'Éducation'], $result['sectors']);
        $this->assertSame('Ma description manuelle', $result['description']);
    }

    public function testFallsBackToNameWhenNoFiche(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->expects($this->never())->method('ask');

        $product = (new Product())
            ->setName('Planigram')
            ->setSectors('');

        $extractor = new ProductMetadataExtractor($ai, $this->createMock(LoggerInterface::class), $this->createMock(PromptLoader::class));

        $result = $extractor->extractMissing($product);

        $this->assertSame(['Planigram'], $result['keywords']);
        $this->assertSame([], $result['sectors']);
        $this->assertSame('', $result['description']);
    }

    public function testCallsAiWhenFichePresentAndKeywordsEmpty(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->expects($this->once())
            ->method('ask')
            ->willReturn('{"keywords":["Logiciel","GED"],"sectors":["Éducation"],"description":"Logiciel de gestion documentaire"}');

        $product = (new Product())
            ->setName('Planigram')
            ->setFiche('# Fiche produit riche');

        $extractor = new ProductMetadataExtractor($ai, $this->createMock(LoggerInterface::class), $this->createMock(PromptLoader::class));

        $result = $extractor->extractMissing($product);

        $this->assertSame(['Logiciel', 'GED'], $result['keywords']);
        $this->assertSame(['Éducation'], $result['sectors']);
        $this->assertSame('Logiciel de gestion documentaire', $result['description']);
    }

    public function testParseJsonStripsPrefixesAndDedupes(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->method('ask')
            ->willReturn('Voici: {"keywords":["1. Logiciel","-GED","GED"," Hébergement "],"sectors":["Santé","Santé"],"description":" - Service principal."}');

        $product = (new Product())
            ->setName('X')
            ->setFiche('contenu');

        $extractor = new ProductMetadataExtractor($ai, $this->createMock(LoggerInterface::class), $this->createMock(PromptLoader::class));

        $result = $extractor->extractMissing($product);

        $this->assertSame(['Logiciel', 'GED', 'Hébergement'], $result['keywords']);
        $this->assertSame(['Santé'], $result['sectors']);
        $this->assertSame('Service principal.', $result['description']);
    }

    public function testEmptyResponseReturnsEmptyArray(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->method('ask')->willReturn('');

        $product = (new Product())
            ->setName('Planigram')
            ->setFiche('contenu');

        $extractor = new ProductMetadataExtractor($ai, $this->createMock(LoggerInterface::class), $this->createMock(PromptLoader::class));

        $result = $extractor->extractMissing($product);

        $this->assertSame(['Planigram'], $result['keywords']);
        $this->assertSame([], $result['sectors']);
        $this->assertSame('', $result['description']);
    }

    public function testParseJsonReturnsEmptyWhenInvalid(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->method('ask')->willReturn('pas du json du tout');

        $product = (new Product())
            ->setName('Planigram')
            ->setFiche('contenu');

        $extractor = new ProductMetadataExtractor($ai, $this->createMock(LoggerInterface::class), $this->createMock(PromptLoader::class));

        $result = $extractor->extractMissing($product);

        $this->assertSame(['Planigram'], $result['keywords']);
        $this->assertSame([], $result['sectors']);
        $this->assertSame('', $result['description']);
    }

    public function testEmptyAiResponseTriggersWarning(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->method('ask')->willReturn('');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('réponse IA vide'), $this->anything());

        $product = (new Product())
            ->setName('Planigram')
            ->setFiche('contenu');

        $extractor = new ProductMetadataExtractor($ai, $logger, $this->createMock(PromptLoader::class));

        $result = $extractor->extractMissing($product);

        $this->assertSame(['Planigram'], $result['keywords']);
    }

    public function testParseMarkdownCodeBlockResponse(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->method('ask')->willReturn("Voici le résultat:\n```json\n{\"keywords\":[\"GED\",\"SIRH\"],\"sectors\":[\"Santé\"]}\n```\n");

        $product = (new Product())
            ->setName('Planigram')
            ->setFiche('contenu');

        $extractor = new ProductMetadataExtractor($ai, $this->createMock(LoggerInterface::class), $this->createMock(PromptLoader::class));

        $result = $extractor->extractMissing($product);

        $this->assertSame(['GED', 'SIRH'], $result['keywords']);
        $this->assertSame(['Santé'], $result['sectors']);
        $this->assertSame('', $result['description']);
    }

    public function testManualKeywordsPreservedSectorsExtracted(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->expects($this->once())
            ->method('ask')
            ->willReturn('{"sectors":["Éducation"],"description":"Outil pour écoles"}');

        $product = (new Product())
            ->setName('Planigram')
            ->setKeywords('GED,SIRH')
            ->setSectors('')
            ->setFiche('# Fiche');

        $extractor = new ProductMetadataExtractor($ai, $this->createMock(LoggerInterface::class), $this->createMock(PromptLoader::class));

        $result = $extractor->extractMissing($product);

        $this->assertSame(['GED', 'SIRH'], $result['keywords']);
        $this->assertSame(['Éducation'], $result['sectors']);
        $this->assertSame('Outil pour écoles', $result['description']);
    }

    public function testManualSectorsPreservedKeywordsExtracted(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->expects($this->once())
            ->method('ask')
            ->willReturn('{"keywords":["GED","SIRH"],"description":"Outil pour écoles"}');

        $product = (new Product())
            ->setName('Planigram')
            ->setKeywords('')
            ->setSectors('Santé')
            ->setFiche('# Fiche');

        $extractor = new ProductMetadataExtractor($ai, $this->createMock(LoggerInterface::class), $this->createMock(PromptLoader::class));

        $result = $extractor->extractMissing($product);

        $this->assertSame(['GED', 'SIRH'], $result['keywords']);
        $this->assertSame(['Santé'], $result['sectors']);
        $this->assertSame('Outil pour écoles', $result['description']);
    }

    public function testNoAiCallWhenAllFieldsManual(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->expects($this->never())->method('ask');

        $product = (new Product())
            ->setName('Planigram')
            ->setKeywords('GED,SIRH')
            ->setSectors('Santé,Éducation')
            ->setDescription('Description manuelle')
            ->setFiche('# Fiche');

        $extractor = new ProductMetadataExtractor($ai, $this->createMock(LoggerInterface::class), $this->createMock(PromptLoader::class));

        $result = $extractor->extractMissing($product);

        $this->assertSame(['GED', 'SIRH'], $result['keywords']);
        $this->assertSame(['Santé', 'Éducation'], $result['sectors']);
        $this->assertSame('Description manuelle', $result['description']);
    }

    public function testDescriptionExtractedWhenMissing(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->expects($this->once())
            ->method('ask')
            ->willReturn('{"keywords":["Mot1"],"sectors":["S1"],"description":"Description courte générée"}');

        $product = (new Product())
            ->setName('Planigram')
            ->setKeywords('')
            ->setSectors('')
            ->setDescription('')
            ->setFiche('# fiche');

        $extractor = new ProductMetadataExtractor($ai, $this->createMock(LoggerInterface::class), $this->createMock(PromptLoader::class));
        $result = $extractor->extractMissing($product);

        $this->assertSame(['Mot1'], $result['keywords']);
        $this->assertSame(['S1'], $result['sectors']);
        $this->assertSame('Description courte générée', $result['description']);
    }

    public function testDescriptionPreservedWhenManuallySet(): void
    {
        $ai = $this->createMock(AiService::class);
        $ai->expects($this->never())->method('ask');

        $product = (new Product())
            ->setName('Planigram')
            ->setKeywords('K')
            ->setSectors('S')
            ->setDescription('Ma propre description manuelle détaillée')
            ->setFiche('# Fiche');

        $extractor = new ProductMetadataExtractor($ai, $this->createMock(LoggerInterface::class), $this->createMock(PromptLoader::class));
        $result = $extractor->extractMissing($product);

        $this->assertSame('Ma propre description manuelle détaillée', $result['description']);
    }

    public function testDescriptionTruncatedToMaxLength(): void
    {
        $longText = str_repeat('X', 500);
        $ai = $this->createMock(AiService::class);
        $ai->method('ask')
            ->willReturn(json_encode([
                'keywords' => ['K1'],
                'sectors' => ['S1'],
                'description' => $longText,
            ]));

        $product = (new Product())
            ->setName('P')
            ->setFiche('c');

        $extractor = new ProductMetadataExtractor($ai, $this->createMock(LoggerInterface::class), $this->createMock(PromptLoader::class));
        $result = $extractor->extractMissing($product);

        $this->assertLessThanOrEqual(300, mb_strlen($result['description']));
        $this->assertStringEndsWith('...', $result['description']);
    }
}
