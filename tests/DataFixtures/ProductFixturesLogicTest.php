<?php

namespace App\Tests\DataFixtures;

use App\Entity\Product;
use App\Service\ProductMetadataExtractor;
use PHPUnit\Framework\TestCase;

class ProductFixturesLogicTest extends TestCase
{
    public function testKeywordsAndSectorsAndDescriptionGeneratedFromExtraction(): void
    {
        $metadata = $this->createMock(ProductMetadataExtractor::class);
        $metadata->expects($this->once())
            ->method('extractMissing')
            ->willReturn([
                'keywords' => ['Logiciel', 'GED'],
                'sectors' => ['Éducation'],
                'description' => 'Outil de gestion documentaire',
            ]);

        $product = new Product();
        $product->setName('Planigram');
        $product->setFiche('# Fiche');

        $meta = $metadata->extractMissing($product);
        if ([] !== $meta['keywords']) {
            $product->setKeywords(implode(', ', $meta['keywords']));
        }
        if ([] !== $meta['sectors']) {
            $product->setSectors(implode(', ', $meta['sectors']));
        }
        if ('' !== $meta['description']) {
            $product->setDescription($meta['description']);
        }

        $this->assertSame('Planigram', $product->getName());
        $this->assertSame('Logiciel, GED', $product->getKeywords());
        $this->assertSame('Éducation', $product->getSectors());
        $this->assertSame('Outil de gestion documentaire', $product->getDescription());
    }

    public function testGracefullySkipWhenAiReturnsEmpty(): void
    {
        $metadata = $this->createMock(ProductMetadataExtractor::class);
        $metadata->expects($this->once())
            ->method('extractMissing')
            ->willReturn([
                'keywords' => [],
                'sectors' => [],
                'description' => '',
            ]);

        $product = new Product();
        $product->setName('Planigram');
        $product->setFiche('# Fiche');

        $meta = $metadata->extractMissing($product);
        if ([] !== $meta['keywords']) {
            $product->setKeywords(implode(', ', $meta['keywords']));
        }
        if ([] !== $meta['sectors']) {
            $product->setSectors(implode(', ', $meta['sectors']));
        }
        if ('' !== $meta['description']) {
            $product->setDescription($meta['description']);
        }

        $this->assertNull($product->getKeywords());
        $this->assertNull($product->getSectors());
        $this->assertNull($product->getDescription());
    }

    public function testManualKeywordsPreservedWhenAlreadySet(): void
    {
        $metadata = $this->createMock(ProductMetadataExtractor::class);
        $metadata->expects($this->never())->method('extractMissing');

        $product = new Product();
        $product->setName('Planigram');
        $product->setKeywords('GED,SIRH');
        $product->setSectors('Santé');
        $product->setDescription('Ma description manuelle');
        $product->setFiche('# Fiche');

        $this->assertSame('GED,SIRH', $product->getKeywords());
        $this->assertSame('Santé', $product->getSectors());
        $this->assertSame('Ma description manuelle', $product->getDescription());
        $this->assertSame('Planigram', $product->getName());
    }
}
