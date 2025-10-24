<?php

namespace App\Repository;

use App\Entity\AuditResult;
use App\Entity\Audit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditResult>
 */
class AuditResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditResult::class);
    }

    /**
     * Group results by severity for an audit
     */
    public function findGroupedBySeverity(Audit $audit): array
    {
        return $this->createQueryBuilder('ar')
            ->andWhere('ar.audit = :audit')
            ->setParameter('audit', $audit)
            ->orderBy('ar.severity', 'ASC')
            ->addOrderBy('ar.errorType', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get count by severity for an audit
     */
    public function countBySeverity(Audit $audit): array
    {
        $results = $this->createQueryBuilder('ar')
            ->select('ar.severity, COUNT(ar.id) as count')
            ->andWhere('ar.audit = :audit')
            ->setParameter('audit', $audit)
            ->groupBy('ar.severity')
            ->getQuery()
            ->getResult();

        $counts = [
            'critical' => 0,
            'major' => 0,
            'minor' => 0,
        ];

        foreach ($results as $result) {
            $counts[$result['severity']] = (int) $result['count'];
        }

        return $counts;
    }
}
