<?php

namespace App\Repository;

use App\Entity\AuditCampaign;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditCampaign>
 */
class AuditCampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditCampaign::class);
    }

    /**
     * Find campaigns by project
     */
    public function findByProject(Project $project): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.project = :project')
            ->setParameter('project', $project)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active campaigns by project
     */
    public function findActiveByProject(Project $project): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.project = :project')
            ->andWhere('c.status != :archived')
            ->setParameter('project', $project)
            ->setParameter('archived', 'archived')
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find campaigns by user with filters
     */
    public function findByUserWithFilters(User $user, ?string $search, ?string $status, int $page = 1, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.project', 'p')
            ->where('p.user = :user')
            ->setParameter('user', $user);

        if ($search) {
            $qb->andWhere('c.name LIKE :search OR c.description LIKE :search OR p.name LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if ($status && $status !== 'all') {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->orderBy('c.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count campaigns by user with filters
     */
    public function countByUserWithFilters(User $user, ?string $search, ?string $status): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->innerJoin('c.project', 'p')
            ->where('p.user = :user')
            ->setParameter('user', $user);

        if ($search) {
            $qb->andWhere('c.name LIKE :search OR c.description LIKE :search OR p.name LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if ($status && $status !== 'all') {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Find campaign by ID and user (through project)
     */
    public function findOneByIdAndUser(int $id, User $user): ?AuditCampaign
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.project', 'p')
            ->where('c.id = :id')
            ->andWhere('p.user = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find existing audit for URL in campaign
     */
    public function findExistingAuditByUrl(AuditCampaign $campaign, string $url): ?\App\Entity\Audit
    {
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('a')
            ->from(\App\Entity\Audit::class, 'a')
            ->where('a.campaign = :campaign')
            ->andWhere('a.url = :url')
            ->setParameter('campaign', $campaign)
            ->setParameter('url', $url)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
