<?php

namespace App\Controller;

use App\Entity\ActionPlan;
use App\Enum\ActionStatus;
use App\Repository\ActionPlanRepository;
use App\Repository\AuditCampaignRepository;
use App\Service\ActionPlanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/action-plan')]
class ActionPlanController extends AbstractController
{
    #[Route('/', name: 'app_action_plan_list', methods: ['GET'])]
    public function list(ActionPlanRepository $actionPlanRepository): Response
    {
        $user = $this->getUser();

        // Get all action plans for user's projects
        $actionPlans = $actionPlanRepository->createQueryBuilder('ap')
            ->join('ap.campaign', 'c')
            ->join('c.project', 'p')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('ap.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('action_plan/list.html.twig', [
            'action_plans' => $actionPlans,
        ]);
    }

    #[Route('/generate/{campaignId}', name: 'app_action_plan_generate', methods: ['POST'])]
    public function generate(
        int $campaignId,
        Request $request,
        AuditCampaignRepository $campaignRepository,
        ActionPlanService $actionPlanService,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();

        // Validate CSRF token
        if (!$this->isCsrfTokenValid('generate_action_plan_' . $campaignId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_campaign_show', ['id' => $campaignId]);
        }

        // Get campaign and verify access
        $campaign = $campaignRepository->findOneByIdAndUser($campaignId, $user);

        if (!$campaign) {
            $this->addFlash('error', 'Campagne non trouvée ou accès refusé.');
            return $this->redirectToRoute('app_project_list');
        }

        // Check if campaign has at least one completed audit
        if (!$campaign->hasCompletedAudits()) {
            $this->addFlash('error', 'La campagne doit contenir au moins un audit complété pour générer un plan d\'action.');
            return $this->redirectToRoute('app_campaign_show', ['id' => $campaignId]);
        }

        // Get duration from form (default: 2 years)
        $durationYears = (int) $request->request->get('duration_years', 2);
        if ($durationYears < 1 || $durationYears > 5) {
            $durationYears = 2;
        }

        try {
            // Force recalculation of campaign statistics before generating plan
            $campaign->recalculateStatistics();
            $entityManager->flush();

            // Generate action plan
            $actionPlan = $actionPlanService->generateActionPlan($campaign, $durationYears);

            $this->addFlash('success', 'Le plan d\'action pluriannuel a été généré avec succès !');
            return $this->redirectToRoute('app_action_plan_show', ['id' => $actionPlan->getId()]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la génération du plan d\'action : ' . $e->getMessage());
            return $this->redirectToRoute('app_campaign_show', ['id' => $campaignId]);
        }
    }

    #[Route('/{id}', name: 'app_action_plan_show', methods: ['GET'])]
    public function show(ActionPlan $actionPlan): Response
    {
        // Check access (user must own the campaign's project)
        $user = $this->getUser();
        if ($actionPlan->getCampaign()->getProject()->getUser() !== $user) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à ce plan d\'action.');
        }

        // Group items by year and quarter
        $itemsByYear = [];
        $currentYear = (int) date('Y');

        foreach ($actionPlan->getItems() as $item) {
            $year = $item->getYear();
            $quarter = $item->getQuarter();

            if (!isset($itemsByYear[$year])) {
                $itemsByYear[$year] = [
                    'Q1' => [],
                    'Q2' => [],
                    'Q3' => [],
                    'Q4' => []
                ];
            }

            $itemsByYear[$year]['Q' . $quarter][] = $item;
        }

        ksort($itemsByYear);

        return $this->render('action_plan/show.html.twig', [
            'action_plan' => $actionPlan,
            'items_by_year' => $itemsByYear,
            'current_year' => $currentYear,
            'quick_wins' => $actionPlan->getQuickWins(),
        ]);
    }

    #[Route('/{id}/update-status', name: 'app_action_plan_update_status', methods: ['POST'])]
    public function updateStatus(
        ActionPlan $actionPlan,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        // Check access
        $user = $this->getUser();
        if ($actionPlan->getCampaign()->getProject()->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        // Validate CSRF token
        if (!$this->isCsrfTokenValid('update_action_plan_status_' . $actionPlan->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_action_plan_show', ['id' => $actionPlan->getId()]);
        }

        $status = $request->request->get('status');
        if (in_array($status, ['draft', 'active', 'completed', 'archived'])) {
            $actionPlan->setStatus($status);
            $entityManager->flush();

            $this->addFlash('success', 'Le statut du plan d\'action a été mis à jour.');
        }

        return $this->redirectToRoute('app_action_plan_show', ['id' => $actionPlan->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_action_plan_delete', methods: ['POST'])]
    public function delete(
        ActionPlan $actionPlan,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        // Check access
        $user = $this->getUser();
        if ($actionPlan->getCampaign()->getProject()->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $campaignId = $actionPlan->getCampaign()->getId();

        // Validate CSRF token
        if ($this->isCsrfTokenValid('delete_action_plan_' . $actionPlan->getId(), $request->request->get('_token'))) {
            $entityManager->remove($actionPlan);
            $entityManager->flush();

            $this->addFlash('success', 'Le plan d\'action a été supprimé.');
        }

        return $this->redirectToRoute('app_campaign_show', ['id' => $campaignId]);
    }

    #[Route('/item/{id}/update-status', name: 'app_action_plan_item_update_status', methods: ['POST'])]
    public function updateItemStatus(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        // Get the action plan item
        $item = $entityManager->getRepository(\App\Entity\ActionPlanItem::class)->find($id);

        if (!$item) {
            throw $this->createNotFoundException('Action non trouvée');
        }

        // Check access
        $user = $this->getUser();
        if ($item->getActionPlan()->getCampaign()->getProject()->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        // Validate CSRF token
        if (!$this->isCsrfTokenValid('update_item_status_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_action_plan_show', ['id' => $item->getActionPlan()->getId()]);
        }

        $newStatus = $request->request->get('status');
        if (in_array($newStatus, ActionStatus::values())) {
            $status = ActionStatus::from($newStatus);
            $item->setStatus($status);
            $entityManager->flush();

            $this->addFlash('success', "Action marquée comme : {$status->getLabel()}");
        }

        return $this->redirectToRoute('app_action_plan_show', ['id' => $item->getActionPlan()->getId()]);
    }
}
