<?php

namespace App\Repository;

use App\Entity\MarketProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketProduct::class);
    }

    /**
     * @return MarketProduct[]
     */
    public function findByMarket(int $marketId): array
    {
        return $this->createQueryBuilder('mp')
            ->join('mp.product', 'p')
            ->andWhere('mp.market = :marketId')
            ->setParameter('marketId', $marketId)
            ->orderBy('mp.score', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
