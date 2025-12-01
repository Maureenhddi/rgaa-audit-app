<?php

namespace App\Controller;

use App\Entity\AnnualActionPlan;
use App\Enum\ActionStatus;
use App\Repository\AnnualActionPlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/annual-action-plan')]
class AnnualActionPlanController extends AbstractController
{
    #[Route('/{id}', name: 'app_annual_action_plan_show', methods: ['GET'])]
    public function show(AnnualActionPlan $annualPlan): Response
    {
        // Check access (user must own the campaign's project)
        $user = $this->getUser();
        if ($annualPlan->getPluriAnnualPlan()->getCampaign()->getProject()->getUser() !== $user) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à ce plan d\'action annuel.');
        }

        // Group items by quarter
        $itemsByQuarter = [
            'Q1' => [],
            'Q2' => [],
            'Q3' => [],
            'Q4' => []
        ];

        foreach ($annualPlan->getItems() as $item) {
            $quarter = 'Q' . $item->getQuarter();
            $itemsByQuarter[$quarter][] = $item;
        }

        return $this->render('annual_action_plan/show.html.twig', [
            'annual_plan' => $annualPlan,
            'ppa' => $annualPlan->getPluriAnnualPlan(),
            'items_by_quarter' => $itemsByQuarter,
        ]);
    }

    #[Route('/item/{id}/update-status', name: 'app_annual_plan_item_update_status', methods: ['POST'])]
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
        $annualPlan = $item->getAnnualPlan();
        if (!$annualPlan || $annualPlan->getPluriAnnualPlan()->getCampaign()->getProject()->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        // Validate CSRF token
        if (!$this->isCsrfTokenValid('update_item_status_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_annual_action_plan_show', ['id' => $annualPlan->getId()]);
        }

        $newStatus = $request->request->get('status');
        if (in_array($newStatus, ActionStatus::values())) {
            $status = ActionStatus::from($newStatus);
            $item->setStatus($status);
            $entityManager->flush();

            $this->addFlash('success', "Action marquée comme : {$status->getLabel()}");
        }

        return $this->redirectToRoute('app_annual_action_plan_show', ['id' => $annualPlan->getId()]);
    }

    #[Route('/{id}/export-word', name: 'app_annual_action_plan_export_word', methods: ['GET'])]
    public function exportWord(AnnualActionPlan $annualPlan): Response
    {
        // Check access
        $user = $this->getUser();
        if ($annualPlan->getPluriAnnualPlan()->getCampaign()->getProject()->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        // Calculate statistics
        $totalActions = $annualPlan->getItems()->count();
        $completedActions = $annualPlan->getItems()->filter(fn($i) => $i->getStatus()->value === 'completed')->count();
        $criticalActions = $annualPlan->getCriticalCount();
        $majorActions = $annualPlan->getMajorCount();
        $minorActions = $annualPlan->getMinorCount();
        $quickWinActions = $annualPlan->getQuickWinsCount();

        // Generate RTF content
        $rtfContent = $this->generateRtfDocument($annualPlan, [
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
            'attachment; filename="plan-action-' . $annualPlan->getYear() . '.rtf"');

        return $response;
    }

    private function generateRtfDocument(AnnualActionPlan $annualPlan, array $stats): string
    {
        $projectName = $annualPlan->getPluriAnnualPlan()->getCampaign()->getProject()->getName();
        $year = $annualPlan->getYear();
        $ppa = $annualPlan->getPluriAnnualPlan();

        // RTF header with UTF-8 encoding support
        $rtf = '{\\rtf1\\ansi\\ansicpg1252\\deff0\\nouicompat\\deflang1036{\\fonttbl{\\f0\\fnil\\fcharset0 Calibri;}}' . "\n";
        $rtf .= '{\\*\\generator RGAA Audit App;}\\viewkind4\\uc1' . "\n";

        // Title
        $rtf .= '\\pard\\sa200\\sl276\\slmult1\\qc\\b\\fs36 Plan d\'action annuel ' . $year . '\\b0\\fs22\\par' . "\n";
        $rtf .= '\\pard\\sa200\\sl276\\slmult1\\qc ' . $this->escapeRtf($projectName) . '\\par' . "\n";

        // Introduction
        $rtf .= '\\pard\\sa200\\sl276\\slmult1\\b\\fs28 Introduction\\b0\\fs22\\par' . "\n";
        $rtf .= 'Ce plan d\'action annuel operationnel pour l\'annee \\b ' . $year . '\\b0  detaille les corrections techniques specifiques a realiser dans le cadre du Plan Pluriannuel d\'Accessibilite ' . $ppa->getStartDate()->format('Y') . '-' . $ppa->getEndDate()->format('Y') . '.\\par' . "\n";
        $rtf .= '\\par' . "\n";

        // Statistics
        $rtf .= '\\b\\fs28 Vue d\'ensemble\\b0\\fs22\\par' . "\n";
        $rtf .= '\\bullet  Total d\'actions planifiees : \\b ' . $stats['totalActions'] . '\\b0\\par' . "\n";
        $rtf .= '\\bullet  Actions critiques : \\b ' . $stats['criticalActions'] . '\\b0\\par' . "\n";
        $rtf .= '\\bullet  Actions majeures : \\b ' . $stats['majorActions'] . '\\b0\\par' . "\n";
        $rtf .= '\\bullet  Actions mineures : \\b ' . $stats['minorActions'] . '\\b0\\par' . "\n";
        $rtf .= '\\bullet  Quick wins : \\b ' . $stats['quickWinActions'] . '\\b0\\par' . "\n";
        $rtf .= '\\bullet  Effort total estime : \\b ' . $annualPlan->getTotalEstimatedEffort() . ' heures\\b0\\par' . "\n";
        $rtf .= '\\par' . "\n";

        // Actions by quarter
        $rtf .= '\\b\\fs28 Actions detaillees par trimestre\\b0\\fs22\\par' . "\n";

        for ($q = 1; $q <= 4; $q++) {
            $quarterItems = $annualPlan->getItems()->filter(fn($i) => $i->getQuarter() === $q);

            if ($quarterItems->count() > 0) {
                $rtf .= '\\par\\b\\fs26 Q' . $q . ' ' . $year . '\\b0\\fs22\\par' . "\n";
                $rtf .= $quarterItems->count() . ' action(s) planifiee(s)\\par' . "\n";

                foreach ($quarterItems as $index => $item) {
                    $rtf .= '\\par\\b ' . ($index + 1) . '. ' . $this->escapeRtf($item->getTitle()) . '\\b0\\par' . "\n";
                    $rtf .= '\\bullet  Periode : Q' . $item->getQuarter() . ' ' . $year . '\\par' . "\n";
                    $rtf .= '\\bullet  Severite : ' . $this->escapeRtf($item->getSeverity()->getLabel()) . '\\par' . "\n";
                    $rtf .= '\\bullet  Categorie : ' . $this->escapeRtf($item->getCategory()->getLabel()) . '\\par' . "\n";
                    $rtf .= '\\bullet  Effort estime : ' . $item->getEstimatedEffort() . ' heures\\par' . "\n";
                    $rtf .= '\\bullet  Impact : ' . $item->getImpactScore() . '/100\\par' . "\n";

                    if ($item->getRgaaCriteria() && count($item->getRgaaCriteria()) > 0) {
                        $rtf .= '\\bullet  Criteres RGAA : ' . $this->escapeRtf(implode(', ', $item->getRgaaCriteria())) . '\\par' . "\n";
                    }

                    if ($item->getDescription()) {
                        $rtf .= '\\bullet  Description : ' . $this->escapeRtf(substr($item->getDescription(), 0, 200)) . '\\par' . "\n";
                    }

                    if ($item->getTechnicalDetails()) {
                        $rtf .= '\\bullet  Details techniques : ' . $this->escapeRtf(substr($item->getTechnicalDetails(), 0, 200)) . '\\par' . "\n";
                    }

                    if ($item->getAffectedPages() && count($item->getAffectedPages()) > 0) {
                        $rtf .= '\\bullet  Pages affectees : ' . count($item->getAffectedPages()) . ' page(s)\\par' . "\n";
                    }
                }
            }
        }

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
