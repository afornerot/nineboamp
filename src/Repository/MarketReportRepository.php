<?php

namespace App\Repository;

use App\Entity\MarketReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<MarketReport>
 */
class MarketReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketReport::class);
    }

    /**
     * @return MarketReport[]
     */
    public function findAllForMarket(int $marketId): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.market = :marketId')
            ->setParameter('marketId', $marketId)
            ->orderBy('r.createdAt', SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    public function findLatestForMarket(int $marketId): ?MarketReport
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.market = :marketId')
            ->setParameter('marketId', $marketId)
            ->orderBy('r.createdAt', SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
