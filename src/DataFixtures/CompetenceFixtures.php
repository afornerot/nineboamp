<?php

namespace App\DataFixtures;

use App\Service\CompetenceSeeder;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class CompetenceFixtures extends Fixture
{
    public function __construct(
        private CompetenceSeeder $seeder,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $this->seeder->seedIfEmpty();
    }
}
