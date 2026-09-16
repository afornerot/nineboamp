<?php

namespace App\Repository;

use App\Entity\ScoringPrompt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ScoringPromptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScoringPrompt::class);
    }

    public function findLatest(): ?ScoringPrompt
    {
        return $this->createQueryBuilder('sp')
            ->orderBy('sp.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestOrCreate(): ScoringPrompt
    {
        $latest = $this->findLatest();
        if ($latest) {
            return $latest;
        }

        $prompt = new ScoringPrompt();
        $this->getEntityManager()->persist($prompt);

        return $prompt;
    }
}
