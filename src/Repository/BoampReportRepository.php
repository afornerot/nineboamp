<?php

namespace App\Repository;

use App\Entity\BoampReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BoampReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoampReport::class);
    }

    /**
     * @return BoampReport[]
     */
    public function findRecent(int $limit = 30): array
    {
        return $this->createQueryBuilder('br')
            ->orderBy('br.executedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
