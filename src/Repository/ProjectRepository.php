<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * Find all projects for a user
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active projects for a user
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.archivedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find archived projects for a user
     */
    public function findArchivedByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.archivedAt IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('p.archivedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find projects with filters and pagination
     */
    public function findByUserWithFilters(
        User $user,
        ?string $search = null,
        ?string $status = 'all',
        int $page = 1,
        int $limit = 10
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user);

        // Search filter (name or client name)
        if ($search) {
            $qb->andWhere('p.name LIKE :search OR p.clientName LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        // Status filter
        if ($status === 'active') {
            $qb->andWhere('p.archivedAt IS NULL')
                ->andWhere('p.status = :statusValue')
                ->setParameter('statusValue', 'active');
        } elseif ($status === 'archived') {
            $qb->andWhere('p.archivedAt IS NOT NULL');
        } elseif ($status === 'completed') {
            $qb->andWhere('p.status = :statusValue')
                ->setParameter('statusValue', 'completed');
        }

        return $qb->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count projects with filters
     */
    public function countByUserWithFilters(
        User $user,
        ?string $search = null,
        ?string $status = 'all'
    ): int {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user);

        // Search filter
        if ($search) {
            $qb->andWhere('p.name LIKE :search OR p.clientName LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        // Status filter
        if ($status === 'active') {
            $qb->andWhere('p.archivedAt IS NULL')
                ->andWhere('p.status = :statusValue')
                ->setParameter('statusValue', 'active');
        } elseif ($status === 'archived') {
            $qb->andWhere('p.archivedAt IS NOT NULL');
        } elseif ($status === 'completed') {
            $qb->andWhere('p.status = :statusValue')
                ->setParameter('statusValue', 'completed');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get projects with their audit stats
     */
    public function findByUserWithStats(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.audits', 'a')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->addSelect('a')
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find one project by id and user
     */
    public function findOneByIdAndUser(int $id, User $user): ?Project
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('p.user = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
