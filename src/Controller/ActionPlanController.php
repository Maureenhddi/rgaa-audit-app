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

        if (!$user) {
            throw $this->createAccessDeniedException('Vous devez être connecté pour accéder à cette page.');
        }

        // DEBUG: Log user info
        error_log('ACTION PLAN LIST - User: ' . $user->getName() . ' (ID: ' . $user->getId() . ')');
        error_log('ACTION PLAN LIST - User class: ' . get_class($user));

        // Get all action plans for user's projects with eager loading
        $actionPlans = $actionPlanRepository->createQueryBuilder('ap')
            ->addSelect('c', 'p', 'items')
            ->join('ap.campaign', 'c')
            ->join('c.project', 'p')
            ->leftJoin('ap.items', 'items')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('ap.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // DEBUG: Log results
        error_log('ACTION PLAN LIST - Results count: ' . count($actionPlans));
        foreach ($actionPlans as $plan) {
            error_log('ACTION PLAN LIST - Plan: ' . $plan->getName() . ' (ID: ' . $plan->getId() . ')');
        }

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

        // Use the new PPA template (strategic view)
        return $this->render('action_plan/show_ppa.html.twig', [
            'action_plan' => $actionPlan,
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

    #[Route('/{id}/reorder', name: 'app_action_plan_reorder', methods: ['POST'])]
    public function reorder(
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
        if (!$this->isCsrfTokenValid('reorder_action_plan_' . $actionPlan->getId(), $request->request->get('_token'))) {
            return $this->json(['success' => false, 'error' => 'Token CSRF invalide'], 400);
        }

        // Get new items data from request
        $items = json_decode($request->getContent(), true)['items'] ?? [];

        if (empty($items) || !is_array($items)) {
            return $this->json(['success' => false, 'error' => 'Données invalides'], 400);
        }

        // Update display_order, year, and quarter for each item
        foreach ($items as $itemData) {
            $itemId = $itemData['id'] ?? null;
            $year = $itemData['year'] ?? null;
            $quarter = $itemData['quarter'] ?? null;
            $displayOrder = $itemData['displayOrder'] ?? null;

            if (!$itemId || !$year || !$quarter || $displayOrder === null) {
                continue;
            }

            $item = $entityManager->getRepository(\App\Entity\ActionPlanItem::class)->find($itemId);
            if ($item && $item->getActionPlan()->getId() === $actionPlan->getId()) {
                $item->setDisplayOrder($displayOrder);
                $item->setYear($year);
                $item->setQuarter($quarter);
            }
        }

        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/export-word', name: 'app_action_plan_export_word', methods: ['GET'])]
    public function exportWord(ActionPlan $actionPlan): Response
    {
        // Check access
        $user = $this->getUser();
        if ($actionPlan->getCampaign()->getProject()->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        // Calculate statistics
        $totalActions = $actionPlan->getItems()->count();
        $completedActions = $actionPlan->getItems()->filter(fn($i) => $i->getStatus()->value === 'completed')->count();
        $criticalActions = $actionPlan->getItems()->filter(fn($i) => $i->getSeverity()->value === 'critical')->count();
        $majorActions = $actionPlan->getItems()->filter(fn($i) => $i->getSeverity()->value === 'major')->count();
        $minorActions = $actionPlan->getItems()->filter(fn($i) => $i->getSeverity()->value === 'minor')->count();
        $quickWinActions = $actionPlan->getItems()->filter(fn($i) => $i->isQuickWin())->count();

        // Generate RTF content
        $rtfContent = $this->generateRtfDocument($actionPlan, [
            'totalActions' => $totalActions,
            'completedActions' => $completedActions,
            'criticalActions' => $criticalActions,
            'majorActions' => $majorActions,
            'minorActions' => $minorActions,
            'quickWinActions' => $quickWinActions,
        ]);

        // Create response
        $response = new Response($rtfContent);
        $response->headers->set('Content-Type', 'application/rtf');
        $response->headers->set('Content-Disposition',
            'attachment; filename="schema-pluriannuel-' . $actionPlan->getId() . '.rtf"');

        return $response;
    }

    private function generateRtfDocument(ActionPlan $actionPlan, array $stats): string
    {
        $projectName = $actionPlan->getCampaign()->getProject()->getName();
        $startYear = $actionPlan->getStartDate()->format('Y');
        $endYear = $actionPlan->getEndDate()->format('Y');
        $currentRate = $actionPlan->getCurrentConformityRate();
        $targetRate = $actionPlan->getTargetConformityRate();
        $auditedBy = 'notre equipe';
        $pageCount = $actionPlan->getCampaign()->getPageAudits()->count();

        // RTF header with UTF-8 encoding support
        $rtf = '{\\rtf1\\ansi\\ansicpg1252\\deff0\\nouicompat\\deflang1036{\\fonttbl{\\f0\\fnil\\fcharset0 Calibri;}}' . "\n";
        $rtf .= '{\\*\\generator RGAA Audit App;}\\viewkind4\\uc1' . "\n";

        // Title
        $rtf .= '\\pard\\sa200\\sl276\\slmult1\\qc\\b\\fs36 Schema pluriannuel de mise en accessibilite\\b0\\fs22\\par' . "\n";

        // Introduction
        $rtf .= '\\pard\\sa200\\sl276\\slmult1\\b\\fs28 Introduction\\b0\\fs22\\par' . "\n";
        $rtf .= 'Ce schema pluriannuel s\'applique a \\b ' . $this->escapeRtf($projectName) . '\\b0  et couvre la periode \\b ' . $startYear . '-' . $endYear . '\\b0 .\\par' . "\n";
        $rtf .= '\\par' . "\n";

        // Politique d'accessibilite
        $rtf .= '\\b\\fs28 Politique d\'accessibilite\\b0\\fs22\\par' . "\n";
        $rtf .= 'L\'accessibilite numerique est au coeur de notre demarche d\'inclusion. Nous nous engageons a garantir l\'egalite d\'acces a nos services numeriques pour tous les utilisateurs, quelles que soient leurs capacites.\\par' . "\n";
        $rtf .= '\\par' . "\n";

        // Etat de conformite
        $rtf .= '\\b\\fs28 Etat de conformite actuel\\b0\\fs22\\par' . "\n";
        $rtf .= 'L\'audit de conformite realise par \\b ' . $this->escapeRtf($auditedBy) . '\\b0  revele que \\b ' . $currentRate . '%\\b0  des criteres du RGAA version 4.1 sont respectes.\\par' . "\n";
        $rtf .= '\\par' . "\n";

        // Resultats detailles
        $rtf .= '\\b\\fs24 Resultats detailles de l\'audit :\\b0\\fs22\\par' . "\n";
        $rtf .= '\\bullet  Taux de conformite actuel : \\b ' . $currentRate . '%\\b0\\par' . "\n";
        $rtf .= '\\bullet  Objectif de conformite vise : \\b ' . $targetRate . '%\\b0\\par' . "\n";
        $rtf .= '\\bullet  Nombre total de pages auditees : \\b ' . $pageCount . '\\b0\\par' . "\n";
        $rtf .= '\\bullet  Problemes critiques identifies : \\b ' . $actionPlan->getCriticalIssues() . '\\b0\\par' . "\n";
        $rtf .= '\\bullet  Problemes majeurs identifies : \\b ' . $actionPlan->getMajorIssues() . '\\b0\\par' . "\n";
        $rtf .= '\\bullet  Problemes mineurs identifies : \\b ' . $actionPlan->getMinorIssues() . '\\b0\\par' . "\n";
        $rtf .= '\\par' . "\n";

        // Plan d'action
        $rtf .= '\\b\\fs28 Plan d\'action ' . $startYear . '-' . $endYear . '\\b0\\fs22\\par' . "\n";
        $rtf .= 'Pour atteindre notre objectif de conformite de \\b ' . $targetRate . '%\\b0 , nous avons etabli un plan d\'action comportant \\b ' . $stats['totalActions'] . ' actions correctives\\b0  reparties sur \\b ' . $actionPlan->getDurationYears() . ' an(s)\\b0 .\\par' . "\n";
        $rtf .= '\\par' . "\n";

        // Actions prioritaires
        $rtf .= '\\b\\fs24 Actions prioritaires (Quick Wins)\\b0\\fs22\\par' . "\n";
        $rtf .= 'Nous avons identifie \\b ' . $stats['quickWinActions'] . ' actions prioritaires\\b0  a fort impact et faible effort, qui seront traitees en premier pour ameliorer rapidement l\'accessibilite du site.\\par' . "\n";
        $rtf .= '\\par' . "\n";

        // Repartition par gravite
        $rtf .= '\\b\\fs24 Repartition par gravite\\b0\\fs22\\par' . "\n";
        $rtf .= '\\bullet  Actions critiques : \\b ' . $stats['criticalActions'] . '\\b0\\par' . "\n";
        $rtf .= '\\bullet  Actions majeures : \\b ' . $stats['majorActions'] . '\\b0\\par' . "\n";
        $rtf .= '\\bullet  Actions mineures : \\b ' . $stats['minorActions'] . '\\b0\\par' . "\n";
        $rtf .= '\\par' . "\n";

        // Calendrier detaille
        $rtf .= '\\b\\fs28 Calendrier detaille des actions\\b0\\fs22\\par' . "\n";

        // Group items by year
        $itemsByYear = [];
        foreach ($actionPlan->getItems() as $item) {
            $year = $item->getYear();
            if (!isset($itemsByYear[$year])) {
                $itemsByYear[$year] = [];
            }
            $itemsByYear[$year][] = $item;
        }
        ksort($itemsByYear);

        foreach ($itemsByYear as $year => $items) {
            $rtf .= '\\par\\b\\fs26 Annee ' . $year . '\\b0\\fs22\\par' . "\n";

            foreach ($items as $index => $item) {
                $rtf .= '\\par\\b ' . ($index + 1) . '. ' . $this->escapeRtf($item->getTitle()) . '\\b0\\par' . "\n";
                $rtf .= '\\bullet  Periode : Q' . $item->getQuarter() . ' ' . $year . '\\par' . "\n";
                $rtf .= '\\bullet  Severite : ' . $this->escapeRtf($item->getSeverity()->getLabel()) . '\\par' . "\n";
                $rtf .= '\\bullet  Effort estime : ' . $item->getEstimatedEffort() . ' heures\\par' . "\n";
                $rtf .= '\\bullet  Impact : ' . $item->getImpactScore() . '/100\\par' . "\n";
                if ($item->getDescription()) {
                    $rtf .= '\\bullet  Description : ' . $this->escapeRtf(substr($item->getDescription(), 0, 200)) . '\\par' . "\n";
                }
            }
        }

        // Contact
        $rtf .= '\\par\\par\\b\\fs28 Contact\\b0\\fs22\\par' . "\n";
        $rtf .= 'Pour toute question concernant ce schema pluriannuel, veuillez contacter notre equipe accessibilite.\\par' . "\n";

        // Close RTF
        $rtf .= '}';

        return $rtf;
    }

    private function escapeRtf(string $text): string
    {
        // Remove special RTF characters
        $text = str_replace(['\\', '{', '}'], ['\\\\', '\\{', '\\}'], $text);
        // Remove accents for RTF compatibility
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return $text;
    }
}
