<?php

namespace App\Controller;

use App\Entity\Audit;
use App\Repository\AuditRepository;
use App\Repository\AuditResultRepository;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/audit')]
class AuditController extends AbstractController
{
    #[Route('/new', name: 'app_audit_new', methods: ['GET', 'POST'])]
    public function new(Request $request, AuditService $auditService, ValidatorInterface $validator, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $url = $request->request->get('url');

            // Validate URL
            $constraints = new Assert\Url(['message' => 'Veuillez entrer une URL valide']);
            $errors = $validator->validate($url, $constraints);

            if (count($errors) > 0) {
                $this->addFlash('error', 'URL invalide. Veuillez entrer une URL valide (ex: https://example.com)');
                return $this->redirectToRoute('app_audit_new');
            }

            try {
                $audit = $auditService->runCompleteAudit($url, $this->getUser());
                $this->addFlash('success', 'L\'audit a été complété avec succès !');
                return $this->redirectToRoute('app_audit_show', ['id' => $audit->getId()]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors du lancement de l\'audit : ' . $e->getMessage());
                return $this->redirectToRoute('app_audit_new');
            }
        }

        return $this->render('audit/new.html.twig');
    }

    #[Route('/{id}', name: 'app_audit_show', methods: ['GET'])]
    public function show(
        Audit $audit,
        AuditResultRepository $resultRepository,
        \App\Service\RgaaThemeService $rgaaThemeService,
        \App\Service\RgaaReferenceService $rgaaReferenceService,
        \App\Repository\ManualCheckRepository $manualCheckRepository,
        LoggerInterface $logger
    ): Response
    {
        $this->denyAccessUnlessGranted('view', $audit);

        // Get all results for this audit
        $results = $resultRepository->findGroupedBySeverity($audit);

        // Count by source
        $sourceCount = [
            'playwright' => 0,
            'axe_core' => 0,
            'html_codesniffer' => 0,
            'unknown' => 0
        ];

        foreach ($results as $result) {
            $source = $result->getSource();
            if ($source === 'axe-core') {
                $sourceCount['axe_core']++;
            } elseif ($source === 'html_codesniffer') {
                $sourceCount['html_codesniffer']++;
            } elseif ($source === 'playwright') {
                $sourceCount['playwright']++;
            } else {
                $sourceCount['unknown']++;
            }
        }

        // SIMPLIFIED GROUPING: Group by RGAA theme > criteria > severity
        $groupedByTheme = [];
        $processedIds = []; // Track which errors we've already processed

        foreach ($results as $result) {
            // Skip if already processed (prevent duplicates)
            $resultId = $result->getId();
            if (in_array($resultId, $processedIds)) {
                continue;
            }
            $processedIds[] = $resultId;

            $severity = $result->getSeverity();
            $themeNum = (int) $rgaaThemeService->getThemeFromResult($result);
            $theme = $rgaaThemeService->getTheme($themeNum);
            $criterion = $rgaaThemeService->getCriteriaFromResult($result);
            $criterionKey = $criterion ?? 'non-categorise';

            // Initialize theme if not exists
            if (!isset($groupedByTheme[$themeNum])) {
                // IMPORTANT: Ensure theme_number matches the key
                $groupedByTheme[$themeNum] = [
                    'theme_number' => $themeNum,  // This must equal $themeNum, not from $theme
                    'theme_name' => $theme['name'],
                    'theme_icon' => $theme['icon'],
                    'theme_color' => $theme['color'],
                    'criteria' => [],
                    'total_count' => 0
                ];
            }

            // Initialize criterion if not exists
            if (!isset($groupedByTheme[$themeNum]['criteria'][$criterionKey])) {
                $groupedByTheme[$themeNum]['criteria'][$criterionKey] = [
                    'criterion' => $criterion,
                    'criterion_description' => $criterion ? $rgaaThemeService->getCriterionDescription($criterion) : '',
                    'results' => [
                        'critical' => [],
                        'major' => [],
                        'minor' => []
                    ],
                    'total_count' => 0
                ];
            }

            $errorType = $result->getErrorType();

            // Find if this errorType already exists in the criterion
            $found = false;
            foreach ($groupedByTheme[$themeNum]['criteria'][$criterionKey]['results'][$severity] as &$group) {
                if ($group['errorType'] === $errorType && $group['source'] === $result->getSource()) {
                    $group['occurrences'][] = $result;
                    $found = true;
                    break;
                }
            }

            // If not found, create a new group
            if (!$found) {
                $groupedByTheme[$themeNum]['criteria'][$criterionKey]['results'][$severity][] = [
                    'errorType' => $errorType,
                    'source' => $result->getSource(),
                    'recommendation' => $result->getRecommendation(),
                    'codeFix' => $result->getCodeFix(),
                    'impactUser' => $result->getImpactUser(),
                    'wcagCriteria' => $result->getWcagCriteria(),
                    'rgaaCriteria' => $result->getRgaaCriteria(),
                    'description' => $result->getDescription(),
                    'occurrences' => [$result]
                ];
            }

            $groupedByTheme[$themeNum]['criteria'][$criterionKey]['total_count']++;
            $groupedByTheme[$themeNum]['total_count']++;
        }

        // Sort themes by number (0 at the end)
        ksort($groupedByTheme);
        if (isset($groupedByTheme[0])) {
            $uncategorized = $groupedByTheme[0];
            unset($groupedByTheme[0]);
            $groupedByTheme[0] = $uncategorized;
        }

        // Sort criteria within each theme
        foreach ($groupedByTheme as &$theme) {
            ksort($theme['criteria']);
        }
        unset($theme);

        // Calculate statistics
        $totalThemes = count($groupedByTheme);
        $totalCriteria = 0;
        foreach ($groupedByTheme as $theme) {
            $totalCriteria += count($theme['criteria']);
        }

        // Get RGAA criteria status
        $auditStatistics = [
            'statistics' => [
                'nonConformDetails' => [] // Will be populated from audit results
            ]
        ];

        // Extract non-conform criteria from groupedByTheme
        foreach ($groupedByTheme as $theme) {
            foreach ($theme['criteria'] as $criterionKey => $criterionData) {
                if ($criterionKey !== 'non-categorise' && $criterionData['total_count'] > 0) {
                    $auditStatistics['statistics']['nonConformDetails'][] = [
                        'criteriaNumber' => $criterionKey,
                        'errorCount' => $criterionData['total_count']
                    ];
                }
            }
        }

        $criteriaStatus = $rgaaReferenceService->getCriteriaStatus($auditStatistics);
        $allCriteria = $rgaaReferenceService->getAllCriteria();
        $criteriaByTopic = $rgaaReferenceService->getCriteriaByTopic();

        // Get manual checks for this audit
        $manualChecks = $manualCheckRepository->findByAudit($audit);
        $manualChecksMap = [];
        foreach ($manualChecks as $check) {
            $manualChecksMap[$check->getCriteriaNumber()] = [
                'status' => $check->getStatus(),
                'notes' => $check->getNotes()
            ];
        }

        return $this->render('audit/show.html.twig', [
            'audit' => $audit,
            'grouped_by_theme' => $groupedByTheme,
            'source_count' => $sourceCount,
            'total_themes' => $totalThemes,
            'total_criteria' => $totalCriteria,
            'all_rgaa_criteria' => $allCriteria,
            'criteria_by_topic' => $criteriaByTopic,
            'criteria_status' => $criteriaStatus,
            'manual_checks' => $manualChecksMap,
        ]);
    }

    #[Route('/{id}/status', name: 'app_audit_status', methods: ['GET'])]
    public function status(Audit $audit): JsonResponse
    {
        $this->denyAccessUnlessGranted('view', $audit);

        return new JsonResponse([
            'status' => $audit->getStatus(),
            'url' => $audit->getUrl(),
            'conformity_rate' => $audit->getConformityRate(),
            'total_issues' => $audit->getTotalIssues(),
        ]);
    }

    #[Route('/', name: 'app_audit_list', methods: ['GET'])]
    public function list(AuditRepository $auditRepository): Response
    {
        $audits = $auditRepository->findByUserOrderedByDate($this->getUser());

        return $this->render('audit/list.html.twig', [
            'audits' => $audits,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_audit_delete', methods: ['POST'])]
    public function delete(Request $request, Audit $audit, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('delete', $audit);

        if ($this->isCsrfTokenValid('delete'.$audit->getId(), $request->request->get('_token'))) {
            $entityManager->remove($audit);
            $entityManager->flush();

            $this->addFlash('success', 'L\'audit a été supprimé avec succès.');
        }

        return $this->redirectToRoute('app_audit_list');
    }

    #[Route('/compare/{id1}/{id2}', name: 'app_audit_compare', methods: ['GET'])]
    public function compare(
        Audit $id1,
        Audit $id2,
        AuditService $auditService
    ): Response {
        $this->denyAccessUnlessGranted('view', $id1);
        $this->denyAccessUnlessGranted('view', $id2);

        $comparison = $auditService->compareAudits($id1, $id2);

        return $this->render('audit/compare.html.twig', [
            'comparison' => $comparison,
        ]);
    }
}
