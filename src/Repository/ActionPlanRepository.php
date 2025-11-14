<?php

namespace App\Repository;

use App\Entity\ActionPlan;
use App\Entity\AuditCampaign;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActionPlan>
 */
class ActionPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionPlan::class);
    }

    /**
     * Find action plans by campaign
     */
    public function findByCampaign(AuditCampaign $campaign): array
    {
        return $this->createQueryBuilder('ap')
            ->where('ap.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->orderBy('ap.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find the latest action plan for a campaign
     */
    public function findLatestByCampaign(AuditCampaign $campaign): ?ActionPlan
    {
        return $this->createQueryBuilder('ap')
            ->where('ap.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->orderBy('ap.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
