<?php

namespace App\Repository;

use App\Entity\MarketChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

class MarketChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketChatMessage::class);
    }

    /**
     * Les $limit messages les plus récents du marché, du plus ancien au plus
     * récent. Le tri décroissant porte sur la sélection : avec un tri croissant,
     * le LIMIT gardait les messages les plus anciens et l'historique affiché
     * ignorait toute la conversation récente. $limit = 0 retourne tout.
     *
     * @return MarketChatMessage[]
     */
    public function findRecentForMarket(int $marketId, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.market = :marketId')
            ->setParameter('marketId', $marketId)
            ->orderBy('m.createdAt', SortDirection::Descending)
            ->addOrderBy('m.id', SortDirection::Descending);

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return array_reverse($qb->getQuery()->getResult());
    }

    /**
     * Les $limit messages marqués importants les plus récents, du plus ancien au
     * plus récent (même sélection que findRecentForMarket()).
     *
     * @return MarketChatMessage[]
     */
    public function findImportantForMarket(int $marketId, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.market = :marketId')
            ->andWhere('m.important = :important')
            ->setParameter('marketId', $marketId)
            ->setParameter('important', true)
            ->orderBy('m.createdAt', SortDirection::Descending)
            ->addOrderBy('m.id', SortDirection::Descending);

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return array_reverse($qb->getQuery()->getResult());
    }
}
