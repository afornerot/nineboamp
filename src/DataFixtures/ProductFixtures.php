<?php

namespace App\DataFixtures;

use App\Entity\Product;
use App\Service\ProductMetadataExtractor;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;

class ProductFixtures extends Fixture
{
    public function __construct(
        private Connection $connection,
        private ProductMetadataExtractor $metadataExtractor,
    ) {
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $dir = '/app/src/DataFixtures/data/products';
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir.'/*.md');
        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            $basename = basename($file, '.md');
            $existing = $manager->getRepository(Product::class)->findOneBy(['name' => $basename]);
            if ($existing) {
                continue;
            }

            $content = file_get_contents($file);
            if ('' === trim($content)) {
                continue;
            }

            $product = new Product();
            $product->setName($basename);
            $product->setFiche($content);

            try {
                $meta = $this->metadataExtractor->extractMissing($product);
                if ([] !== $meta['keywords']) {
                    $product->setKeywords(implode(', ', $meta['keywords']));
                }
                if ([] !== $meta['sectors']) {
                    $product->setSectors(implode(', ', $meta['sectors']));
                }
                if ('' !== $meta['description']) {
                    $product->setDescription($meta['description']);
                }
            } catch (\Throwable) {
            }

            $manager->persist($product);
        }

        $manager->flush();
    }
}
