<?php

namespace App\Controller;

use App\Repository\AuditRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(AuditRepository $auditRepository): Response
    {
        $user = $this->getUser();

        // Get recent audits
        $recentAudits = $auditRepository->findByUserOrderedByDate($user, 10);

        // Get statistics
        $statistics = $auditRepository->getUserStatistics($user);

        // Get conformity evolution
        $conformityEvolution = $auditRepository->getConformityEvolution($user);

        return $this->render('dashboard/index.html.twig', [
            'recent_audits' => $recentAudits,
            'statistics' => $statistics,
            'conformity_evolution' => $conformityEvolution,
        ]);
    }
}
