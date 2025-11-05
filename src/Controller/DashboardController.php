<?php

namespace App\Controller;

use App\Repository\AuditRepository;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(AuditRepository $auditRepository, ProjectRepository $projectRepository): Response
    {
        $user = $this->getUser();

        // Get recent audits
        $recentAudits = $auditRepository->findByUserOrderedByDate($user, 10);

        // Get statistics
        $statistics = $auditRepository->getUserStatistics($user);

        // Get conformity evolution
        $conformityEvolution = $auditRepository->getConformityEvolution($user);

        // Get most audited URLs
        $mostAuditedUrls = $auditRepository->getMostAuditedUrls($user, 5);

        // Get active projects
        $activeProjects = $projectRepository->findActiveByUser($user);

        return $this->render('dashboard/index.html.twig', [
            'recent_audits' => $recentAudits,
            'statistics' => $statistics,
            'conformity_evolution' => $conformityEvolution,
            'most_audited_urls' => $mostAuditedUrls,
            'active_projects' => $activeProjects,
        ]);
    }
}
