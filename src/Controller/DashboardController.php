<?php

namespace App\Controller;

use App\Repository\AuditRepository;
use App\Repository\AuditCampaignRepository;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(
        AuditRepository $auditRepository,
        ProjectRepository $projectRepository,
        AuditCampaignRepository $campaignRepository
    ): Response {
        $user = $this->getUser();

        // Get recent audits
        $recentAudits = $auditRepository->findByUserOrderedByDate($user, 10);

        // Get statistics
        $statistics = $auditRepository->getUserStatistics($user);

        // Calculate average conformity rate
        $averageConformity = null;
        if ($statistics['totalAudits'] > 0) {
            $completedAudits = $auditRepository->findBy(
                ['user' => $user, 'status' => 'completed'],
                ['createdAt' => 'DESC']
            );
            $totalRate = 0;
            $count = 0;
            foreach ($completedAudits as $audit) {
                if ($audit->getConformityRate() !== null) {
                    $totalRate += (float)$audit->getConformityRate();
                    $count++;
                }
            }
            if ($count > 0) {
                $averageConformity = round($totalRate / $count, 2);
            }
        }

        // Get conformity evolution
        $conformityEvolution = $auditRepository->getConformityEvolution($user);

        // Get most audited URLs
        $mostAuditedUrls = $auditRepository->getMostAuditedUrls($user, 5);

        // Get active projects
        $activeProjects = $projectRepository->findActiveByUser($user);

        // Get active campaigns (through projects)
        $userProjects = $projectRepository->findBy(['user' => $user]);
        $activeCampaigns = [];
        if (!empty($userProjects)) {
            $activeCampaigns = $campaignRepository->findBy(
                ['project' => $userProjects],
                ['createdAt' => 'DESC'],
                5
            );
        }

        return $this->render('dashboard/index.html.twig', [
            'recent_audits' => $recentAudits,
            'statistics' => $statistics,
            'average_conformity' => $averageConformity,
            'conformity_evolution' => $conformityEvolution,
            'most_audited_urls' => $mostAuditedUrls,
            'active_projects' => $activeProjects,
            'active_campaigns' => $activeCampaigns,
        ]);
    }
}
