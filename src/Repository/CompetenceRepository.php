<?php

namespace App\Repository;

use App\Entity\Competence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Competence>
 */
class CompetenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Competence::class);
    }

    /**
     * Ordre proposé pour une nouvelle compétence : à la suite de sa catégorie.
     */
    public function nextPosition(string $category): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->where('c.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max + 10;
    }

    /**
     * Compétences actives, dans l'ordre d'affichage au sein de chaque catégorie.
     *
     * @return Competence[]
     */
    public function findActiveOrdered(): array
    {
        return $this->findBy(['active' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }
}
