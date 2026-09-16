<?php

namespace App\Repository;

use App\Entity\MarketChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketChatMessage::class);
    }

    /**
     * @return MarketChatMessage[]
     */
    public function findRecentForMarket(int $marketId, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.market = :marketId')
            ->setParameter('marketId', $marketId)
            ->orderBy('m.createdAt', 'ASC');

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }
}
