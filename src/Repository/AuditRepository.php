<?php

namespace App\Repository;

use App\Entity\Audit;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Audit>
 */
class AuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Audit::class);
    }

    /**
     * Find audits by user ordered by creation date
     */
    public function findByUserOrderedByDate(User $user, int $limit = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'DESC');

        if ($limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get conformity rate evolution for a user
     */
    public function getConformityEvolution(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.createdAt, a.conformityRate, a.url')
            ->andWhere('a.user = :user')
            ->andWhere('a.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'completed')
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics for user's audits
     */
    public function getUserStatistics(User $user): array
    {
        $result = $this->createQueryBuilder('a')
            ->select('
                COUNT(a.id) as totalAudits,
                AVG(a.conformityRate) as avgConformity,
                SUM(a.criticalCount) as totalCritical,
                SUM(a.majorCount) as totalMajor,
                SUM(a.minorCount) as totalMinor
            ')
            ->andWhere('a.user = :user')
            ->andWhere('a.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'completed')
            ->getQuery()
            ->getSingleResult();

        return [
            'totalAudits' => (int) $result['totalAudits'],
            'avgConformity' => round((float) $result['avgConformity'], 2),
            'totalCritical' => (int) $result['totalCritical'],
            'totalMajor' => (int) $result['totalMajor'],
            'totalMinor' => (int) $result['totalMinor'],
        ];
    }
}
