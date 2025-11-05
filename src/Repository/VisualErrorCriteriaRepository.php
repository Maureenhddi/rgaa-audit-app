<?php

namespace App\Repository;

use App\Entity\VisualErrorCriteria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VisualErrorCriteria>
 */
class VisualErrorCriteriaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VisualErrorCriteria::class);
    }

    /**
     * Trouve un mapping par type d'erreur
     */
    public function findByErrorType(string $errorType): ?VisualErrorCriteria
    {
        return $this->findOneBy(['errorType' => $errorType]);
    }

    /**
     * Récupère tous les mappings sous forme de tableau pour la performance
     */
    public function getAllMappingsAsArray(): array
    {
        $results = $this->createQueryBuilder('v')
            ->getQuery()
            ->getResult();

        $mapping = [];
        foreach ($results as $criteria) {
            $mapping[$criteria->getErrorType()] = [
                'wcag' => $criteria->getWcagCriteria(),
                'rgaa' => $criteria->getRgaaCriteria(),
            ];
        }

        return $mapping;
    }
}
