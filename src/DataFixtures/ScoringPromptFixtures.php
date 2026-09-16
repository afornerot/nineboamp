<?php

namespace App\DataFixtures;

use App\Entity\ScoringPrompt;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;

class ScoringPromptFixtures extends Fixture
{
    public function __construct(private Connection $connection)
    {
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $this->connection->executeStatement('SET NAMES utf8mb4');

        $dir = '/app/src/DataFixtures/data/scoring';
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir.'/*.md');
        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            $basename = basename($file, '.md');
            $existing = $manager->getRepository(ScoringPrompt::class)->findOneBy(['sourceFile' => $basename]);
            if ($existing) {
                continue;
            }

            $content = file_get_contents($file);
            if (false === $content || '' === trim($content)) {
                continue;
            }

            $prompt = new ScoringPrompt();
            $prompt->setSourceFile($basename);
            $prompt->setRole($content);
            $manager->persist($prompt);
        }

        $manager->flush();
    }
}
