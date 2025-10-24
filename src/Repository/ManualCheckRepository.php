<?php

namespace App\Repository;

use App\Entity\ManualCheck;
use App\Entity\Audit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ManualCheck>
 */
class ManualCheckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ManualCheck::class);
    }

    /**
     * Find a manual check for a specific audit and criteria
     */
    public function findByAuditAndCriteria(Audit $audit, string $criteriaNumber): ?ManualCheck
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.audit = :audit')
            ->andWhere('m.criteriaNumber = :criteriaNumber')
            ->setParameter('audit', $audit)
            ->setParameter('criteriaNumber', $criteriaNumber)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get all manual checks for an audit
     */
    public function findByAudit(Audit $audit): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.audit = :audit')
            ->setParameter('audit', $audit)
            ->orderBy('m.criteriaNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get manual checks statistics for an audit
     */
    public function getStatistics(Audit $audit): array
    {
        $results = $this->createQueryBuilder('m')
            ->select('m.status, COUNT(m.id) as count')
            ->andWhere('m.audit = :audit')
            ->setParameter('audit', $audit)
            ->groupBy('m.status')
            ->getQuery()
            ->getResult();

        $stats = [
            'not_checked' => 0,
            'conform' => 0,
            'non_conform' => 0,
            'total' => 0
        ];

        foreach ($results as $result) {
            $stats[$result['status']] = (int) $result['count'];
            $stats['total'] += (int) $result['count'];
        }

        return $stats;
    }
}
