<?php

namespace App\Repository;

use App\Entity\Market;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Market::class);
    }

    /**
     * @return Market[]
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.status = :status')
            ->setParameter('status', $status)
            ->orderBy('m.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Market[]
     */
    public function findByPriority(string $priority): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.priority = :priority')
            ->setParameter('priority', $priority)
            ->orderBy('m.score', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
