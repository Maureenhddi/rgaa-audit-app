<?php

namespace App\Controller;

use App\Entity\Audit;
use App\Repository\AuditRepository;
use App\Repository\AuditResultRepository;
use App\Repository\ProjectRepository;
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
    public function new(Request $request, AuditService $auditService, ValidatorInterface $validator, EntityManagerInterface $entityManager, ProjectRepository $projectRepository): Response
    {
        // Get active projects for the select dropdown (needed for both GET and POST)
        $projects = $projectRepository->findActiveByUser($this->getUser());

        if ($request->isMethod('POST')) {
            $url = $request->request->get('url');
            $projectId = $request->request->get('project_id');

            // Get selected image analysis types (array)
            $imageAnalysisTypes = $request->request->all('image_analysis_types') ?? [];

            // Contextual analysis types - ALWAYS ENABLED (automatic)
            // Hybrid Playwright + AI for contextual understanding
            $contextualAnalysisTypes = [
                'contrast-context',      // RGAA 3.2 - Contrast on complex backgrounds
                'heading-relevance',     // RGAA 6.1, 9.1 - Heading relevance
                'link-context',          // RGAA 6.2 - Link clarity
                'table-headers'          // RGAA 5.7 - Table headers descriptiveness
            ];

            // Validate URL
            $constraints = new Assert\Url(['message' => 'Veuillez entrer une URL valide']);
            $errors = $validator->validate($url, $constraints);

            if (count($errors) > 0) {
                $this->addFlash('error', 'URL invalide. Veuillez entrer une URL valide (ex: https://example.com)');
                return $this->redirectToRoute('app_audit_new');
            }

            try {
                $audit = $auditService->runCompleteAudit($url, $this->getUser(), $imageAnalysisTypes, $contextualAnalysisTypes);

                // Associate with project if selected
                if ($projectId) {
                    $project = $projectRepository->findOneByIdAndUser((int) $projectId, $this->getUser());
                    if ($project) {
                        $audit->setProject($project);
                        $entityManager->flush();
                    }
                }

                $this->addFlash('success', 'L\'audit a été complété avec succès !');
                return $this->redirectToRoute('app_audit_show', ['id' => $audit->getId()]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors du lancement de l\'audit : ' . $e->getMessage());
                return $this->redirectToRoute('app_audit_new');
            }
        }

        return $this->render('audit/new.html.twig', [
            'projects' => $projects,
        ]);
    }

    #[Route('/{id}', name: 'app_audit_show', methods: ['GET'])]
    public function show(
        Audit $audit,
        AuditResultRepository $resultRepository,
        \App\Service\RgaaThemeService $rgaaThemeService,
        \App\Service\RgaaReferenceService $rgaaReferenceService,
        \App\Repository\ManualCheckRepository $manualCheckRepository,
        \App\Service\IssuePriorityService $priorityService,
        LoggerInterface $logger,
        EntityManagerInterface $entityManager
    ): Response
    {
        $this->denyAccessUnlessGranted('view', $audit);

        // Get all results for this audit
        $results = $resultRepository->findGroupedBySeverity($audit);

        // Count by source
        $sourceCount = [
            'playwright' => 0,
            'axe_core' => 0,
            'a11ylint' => 0,
            'ia_images' => 0,
            'ia_context' => 0,
            'unknown' => 0
        ];

        foreach ($results as $result) {
            $source = $result->getSource();
            if ($source === 'axe-core') {
                $sourceCount['axe_core']++;
            } elseif ($source === 'a11ylint') {
                $sourceCount['a11ylint']++;
            } elseif ($source === 'playwright') {
                $sourceCount['playwright']++;
            } elseif ($source === 'gemini-image-analysis') {
                $sourceCount['ia_images']++;
            } elseif ($source === 'ia_context') {
                $sourceCount['ia_context']++;
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

            // Ensure severity has a valid value, default to 'minor' if null/invalid
            if (!in_array($severity, ['critical', 'major', 'minor'])) {
                $severity = 'minor';
            }

            // Ensure the severity array exists (defensive programming)
            if (!isset($groupedByTheme[$themeNum]['criteria'][$criterionKey]['results'][$severity])) {
                $groupedByTheme[$themeNum]['criteria'][$criterionKey]['results'][$severity] = [];
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
                    'occurrences' => [$result],
                    'priorityScore' => 0 // Will be calculated later
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

        // Calculate priority scores for all issues
        foreach ($groupedByTheme as &$theme) {
            foreach ($theme['criteria'] as &$criterionData) {
                foreach (['critical', 'major', 'minor'] as $severity) {
                    foreach ($criterionData['results'][$severity] as &$group) {
                        $group['priorityScore'] = $priorityService->calculatePriorityScore(
                            $severity,
                            count($group['occurrences']),
                            $group['impactUser'],
                            $group['wcagCriteria']
                        );
                    }
                }
            }
        }
        unset($theme, $criterionData, $group);

        // Calculate statistics
        $totalThemes = count($groupedByTheme);
        $totalCriteria = 0;
        foreach ($groupedByTheme as $theme) {
            $totalCriteria += count($theme['criteria']);
        }

        // Get all RGAA criteria info first
        $allCriteria = $rgaaReferenceService->getAllCriteria();
        $criteriaByTopic = $rgaaReferenceService->getCriteriaByTopic();

        // Get RGAA criteria status
        $auditStatistics = [
            'statistics' => [
                'nonConformDetails' => [] // Will be populated from audit results
            ]
        ];

        // Extract non-conform criteria from groupedByTheme with full details
        $nonConformDetails = [];
        foreach ($groupedByTheme as $theme) {
            foreach ($theme['criteria'] as $criterionKey => $criterionData) {
                // Skip non-categorized and invalid criteria
                if ($criterionKey === 'non-categorise' || $criterionData['total_count'] <= 0) {
                    continue;
                }

                // Skip invalid WCAG criteria (conformance levels like "wcag2a", "wcag2aa" instead of criterion numbers)
                if (preg_match('/^WCAG:(wcag\d+[a]{1,3})$/i', $criterionKey)) {
                    // This is a conformance level, not a criterion - skip it
                    continue;
                }

                // Add to statistics for status calculation
                $auditStatistics['statistics']['nonConformDetails'][] = [
                    'criteriaNumber' => $criterionKey,
                    'errorCount' => $criterionData['total_count']
                ];

                // Get criterion info from RGAA reference using the correct method
                // Handle WCAG-only criteria (prefixed with "WCAG:")
                $isWcagOnly = str_starts_with($criterionKey, 'WCAG:');
                $criterionInfo = $isWcagOnly ? null : $rgaaReferenceService->getCriteriaByNumber($criterionKey);

                // Collect unique error types and their recommendations
                $errorSummary = [];
                foreach (['critical', 'major', 'minor'] as $sev) {
                    if (isset($criterionData['results'][$sev])) {
                        foreach ($criterionData['results'][$sev] as $group) {
                            $key = $group['errorType'] ?? 'unknown';
                            if (!isset($errorSummary[$key])) {
                                $errorSummary[$key] = [
                                    'count' => 0,
                                    'recommendation' => $group['recommendation'] ?? '',
                                    'impactUser' => $group['impactUser'] ?? ''
                                ];
                            }
                            $errorSummary[$key]['count'] += count($group['occurrences'] ?? []);
                        }
                    }
                }

                // Build display title and reason
                $displayTitle = '';
                $reason = '';

                if ($criterionInfo) {
                    // RGAA criterion found - use official description
                    $displayTitle = $criterionInfo['title'];
                    $reason = $criterionInfo['description'];
                } else {
                    // Fallback for WCAG-only or unknown criteria
                    if ($isWcagOnly) {
                        $wcagNumber = str_replace('WCAG:', '', $criterionKey);

                        // Common WCAG criteria mapping
                        $wcagTitles = [
                            '1.1.1' => 'Contenu non textuel',
                            '1.3.1' => 'Information et relations',
                            '1.4.3' => 'Contraste (minimum)',
                            '2.1.1' => 'Clavier',
                            '2.4.4' => 'Fonction du lien (selon le contexte)',
                            '2.4.7' => 'Visibilité du focus',
                            '3.1.1' => 'Langue de la page',
                            '3.3.2' => 'Étiquettes ou instructions',
                            '4.1.1' => 'Analyse syntaxique',
                            '4.1.2' => 'Nom, rôle et valeur',
                        ];

                        $displayTitle = $wcagTitles[$wcagNumber] ?? "Contenu non conforme WCAG";
                        $reason = "Critère WCAG $wcagNumber : " . ($wcagTitles[$wcagNumber] ?? "critère d'accessibilité web") . ".";
                        $reason .= "\n\nCe critère est détecté automatiquement mais n'a pas de correspondance directe avec un critère RGAA spécifique.";
                    } else {
                        $displayTitle = "Critère $criterionKey";
                        $reason = "Critère détecté par les outils d'analyse automatique.";
                    }
                }

                // Add practical summary of issues found
                if (!empty($errorSummary)) {
                    $reason .= "\n\n📋 Problèmes à corriger :";
                    $count = 0;
                    foreach ($errorSummary as $errorType => $info) {
                        if ($count >= 3) {
                            $remaining = count($errorSummary) - 3;
                            $reason .= "\n... et $remaining autre(s) type(s) de problème.";
                            break;
                        }
                        $reason .= "\n\n• $errorType ({$info['count']} occurrence(s))";
                        if (!empty($info['impactUser'])) {
                            $reason .= "\n  Impact : " . $info['impactUser'];
                        }
                        $count++;
                    }
                }

                $nonConformDetails[] = [
                    'criteriaNumber' => $criterionKey,
                    'criteriaTitle' => $displayTitle,
                    'errorCount' => $criterionData['total_count'],
                    'reason' => $reason
                ];
            }
        }

        $criteriaStatus = $rgaaReferenceService->getCriteriaStatus($auditStatistics);

        // Get manual checks for this audit
        $manualChecks = $manualCheckRepository->findByAudit($audit);
        $manualChecksMap = [];
        foreach ($manualChecks as $check) {
            $manualChecksMap[$check->getCriteriaNumber()] = [
                'status' => $check->getStatus(),
                'notes' => $check->getNotes()
            ];
        }

        // Get top priority issues for executive summary
        $topPriorityIssues = $priorityService->getTopPriorityIssues($groupedByTheme, 5);
        $priorityStatistics = $priorityService->getPriorityStatistics($groupedByTheme);

        // Parse summary if it's JSON
        $summaryText = $audit->getSummary();
        if ($summaryText) {
            $summaryData = json_decode($summaryText, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($summaryData['overallStatement'])) {
                $summaryText = $summaryData['overallStatement'];
            }
        }

        // Build criteria status by topic for the summary table
        $criteriaStatusByTopic = [];
        $totalConform = 0;
        $totalNonConform = 0;
        $totalNotApplicable = 0;
        $totalNotTested = 0;

        foreach ($criteriaByTopic as $topicName => $topicCriteria) {
            $conformCount = 0;
            $nonConformCount = 0;
            $notApplicableCount = 0;
            $notTestedCount = 0;

            foreach ($topicCriteria as $criterion) {
                $criterionNumber = $criterion['number'];

                // Priority 1: Check if not applicable (from manual checks)
                if (isset($manualChecksMap[$criterionNumber]) && $manualChecksMap[$criterionNumber]['status'] === 'not_applicable') {
                    $notApplicableCount++;
                }
                // Priority 2: Check if non-conform (from automatic tests)
                elseif (in_array($criterionNumber, $criteriaStatus['nonConform'] ?? [])) {
                    $nonConformCount++;
                }
                // Priority 3: Check if conform (from automatic tests OR manual checks)
                elseif (in_array($criterionNumber, $criteriaStatus['conform'] ?? []) ||
                        (isset($manualChecksMap[$criterionNumber]) && $manualChecksMap[$criterionNumber]['status'] === 'conform')) {
                    $conformCount++;
                }
                // Priority 4: Not tested yet (no automatic test result and no manual check, or status is 'not_checked')
                else {
                    $notTestedCount++;
                }
            }

            $criteriaStatusByTopic[$topicName] = [
                'conform' => $conformCount,
                'nonConform' => $nonConformCount,
                'notApplicable' => $notApplicableCount,
                'notTested' => $notTestedCount,
                'total' => count($topicCriteria)
            ];

            $totalConform += $conformCount;
            $totalNonConform += $nonConformCount;
            $totalNotApplicable += $notApplicableCount;
            $totalNotTested += $notTestedCount;
        }

        // Update audit entity with totals
        $audit->setConformCriteria($totalConform);
        $audit->setNonConformCriteria($totalNonConform);
        $audit->setNotApplicableCriteria($totalNotApplicable);
        $audit->setNotTestedCriteria($totalNotTested);
        $entityManager->flush();

        return $this->render('audit/show.html.twig', [
            'audit' => $audit,
            'grouped_by_theme' => $groupedByTheme,
            'source_count' => $sourceCount,
            'total_themes' => $totalThemes,
            'total_criteria' => $totalCriteria,
            'all_rgaa_criteria' => $allCriteria,
            'criteria_by_topic' => $criteriaByTopic,
            'criteria_status' => $criteriaStatus,
            'criteria_status_by_topic' => $criteriaStatusByTopic,
            'manual_checks' => $manualChecksMap,
            'top_priority_issues' => $topPriorityIssues,
            'priority_statistics' => $priorityStatistics,
            'summary_text' => $summaryText,
            'non_conform_details' => $nonConformDetails,
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
    public function list(Request $request, AuditRepository $auditRepository, ProjectRepository $projectRepository): Response
    {
        $user = $this->getUser();

        // Get filters from query parameters
        $search = $request->query->get('search');
        $status = $request->query->get('status', 'all');
        $projectId = $request->query->get('project_id') === '0' ? 0 : ($request->query->get('project_id') ? (int) $request->query->get('project_id') : null);
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 10;

        // Get filtered audits with pagination
        $audits = $auditRepository->findByUserWithFilters($user, $search, $status, $projectId, $page, $limit);

        // Get total count for pagination
        $totalAudits = $auditRepository->countByUserWithFilters($user, $search, $status, $projectId);
        $totalPages = ceil($totalAudits / $limit);

        // Get all projects for the filter dropdown
        $projects = $projectRepository->findByUser($user);

        return $this->render('audit/list.html.twig', [
            'audits' => $audits,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_audits' => $totalAudits,
            'search' => $search,
            'status' => $status,
            'project_id' => $projectId,
            'projects' => $projects,
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

    #[Route('/recalculate-last', name: 'app_audit_recalculate_last', methods: ['POST'])]
    public function recalculateLast(
        Request $request,
        AuditRepository $auditRepository,
        EntityManagerInterface $entityManager,
        string $projectDir
    ): Response {
        // Validate CSRF token
        if (!$this->isCsrfTokenValid('recalculate_last', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_audit_list');
        }
        // Get the last completed audit
        $audit = $auditRepository->findOneBy(
            ['status' => 'completed'],
            ['createdAt' => 'DESC']
        );

        if (!$audit) {
            $this->addFlash('warning', 'Aucun audit terminé trouvé.');
            return $this->redirectToRoute('app_audit_list');
        }

        $stats = [
            'audit_id' => $audit->getId(),
            'url' => $audit->getUrl(),
            'updated_mappings' => 0,
            'improved_recommendations' => 0,
            'old_rate' => $audit->getConformityRate(),
            'new_rate' => null
        ];

        // Step 1: Update RGAA criteria mapping
        $mappingFile = $projectDir . '/config/error_to_rgaa_mapping.json';
        if (file_exists($mappingFile)) {
            $mappingData = json_decode(file_get_contents($mappingFile), true);
            if (isset($mappingData['mappings'])) {
                $errorToRgaaMap = array_filter($mappingData['mappings'], function($key) {
                    return strpos($key, '_') !== 0;
                }, ARRAY_FILTER_USE_KEY);

                foreach ($audit->getAuditResults() as $result) {
                    $errorType = $result->getErrorType();
                    $criteriaNumber = null;

                    if (isset($errorToRgaaMap[$errorType])) {
                        $criteriaNumber = $errorToRgaaMap[$errorType];
                    } elseif (preg_match('/rgaa[_\s-]*(\d+)[._-](\d+)/i', $errorType, $matches)) {
                        $criteriaNumber = $matches[1] . '.' . $matches[2];
                    }

                    if ($criteriaNumber && $criteriaNumber !== $result->getRgaaCriteria()) {
                        $result->setRgaaCriteria($criteriaNumber);
                        $stats['updated_mappings']++;
                    }
                }
            }
        }

        // Step 2: Improve generic recommendations
        $genericPhrases = [
            'Vérifier le code', 'vérifier le code',
            'Appliquer les corrections', 'appliquer les corrections',
            'Corriger l\'accessibilité', 'corriger l\'accessibilité',
            'Mettre à jour le code', 'mettre à jour le code',
            'respecter les normes', 'conformément aux', 'selon les règles',
        ];

        foreach ($audit->getAuditResults() as $result) {
            $recommendation = $result->getRecommendation();
            if (!$recommendation) continue;

            $isGeneric = false;
            foreach ($genericPhrases as $phrase) {
                if (stripos($recommendation, $phrase) !== false) {
                    $isGeneric = true;
                    break;
                }
            }

            if ($isGeneric) {
                $result->setRecommendation($this->getSpecificRecommendation($result));
                $stats['improved_recommendations']++;
            }
        }

        // Step 3: Recalculate conformity rate (NEW LOGIC - count ACTUAL tested criteria)
        $criteriaFile = $projectDir . '/config/rgaa_criteria.json';
        if (file_exists($criteriaFile)) {
            $criteriaData = json_decode(file_get_contents($criteriaFile), true);
            if (isset($criteriaData['criteria'])) {
                $totalRgaaCriteria = count($criteriaData['criteria']); // 106

                // Step 1: Get tested criteria from mapping (definitive list)
                $allTestedCriteria = [];
                if (isset($errorToRgaaMap)) {
                    foreach ($errorToRgaaMap as $errorType => $criteriaNumber) {
                        $allTestedCriteria[$criteriaNumber] = true;
                    }
                }

                $nonConformCriteriaNumbers = [];

                // Step 2: Collect criteria with errors (non-conform)
                // ONLY count if they're in our tested list
                foreach ($audit->getAuditResults() as $result) {
                    if ($criteriaNumber = $result->getRgaaCriteria()) {
                        // Only count if in mapping (exclude Gemini extras)
                        if (isset($allTestedCriteria[$criteriaNumber])) {
                            $nonConformCriteriaNumbers[$criteriaNumber] = true;
                        }
                    }
                }

                // Count: mapping defines tested criteria, errors define non-conform
                $totalTestedCriteria = count($allTestedCriteria); // = 29 from mapping
                $nonConformCount = count($nonConformCriteriaNumbers); // = errors in those 29
                $conformCount = $totalTestedCriteria - $nonConformCount; // = 29 - errors

                if ($totalTestedCriteria > 0) {
                    $conformityRate = ($conformCount / $totalTestedCriteria) * 100;
                    $stats['new_rate'] = round($conformityRate, 2);

                    $audit->setConformityRate((string) $stats['new_rate']);
                    $audit->setConformCriteria($conformCount);
                    $audit->setNonConformCriteria($nonConformCount);
                    $audit->setNotApplicableCriteria($totalRgaaCriteria - $totalTestedCriteria);
                }
            }
        }

        $entityManager->flush();

        $this->addFlash('success', sprintf(
            '✅ Audit #%d recalculé : %d mappings mis à jour, %d recommandations améliorées. Taux de conformité : %s%% → %s%%',
            $stats['audit_id'],
            $stats['updated_mappings'],
            $stats['improved_recommendations'],
            $stats['old_rate'] ?: 'null',
            $stats['new_rate']
        ));

        return $this->redirectToRoute('app_audit_show', ['id' => $audit->getId()]);
    }

    private function getSpecificRecommendation($result): string
    {
        $normalizedType = strtolower($result->getErrorType());

        if (stripos($normalizedType, 'alt') !== false || stripos($normalizedType, 'image') !== false) {
            return 'Ajouter un attribut alt="" descriptif sur chaque balise <img>. Si l\'image est purement décorative, utiliser alt="" ou role="presentation".';
        }
        if (stripos($normalizedType, 'contrast') !== false || stripos($normalizedType, 'contraste') !== false) {
            return 'Augmenter le contraste entre le texte et son arrière-plan pour atteindre un ratio d\'au moins 4.5:1 pour le texte normal, ou 3:1 pour le texte de grande taille (18pt+ ou 14pt+ gras).';
        }
        if (stripos($normalizedType, 'link') !== false || stripos($normalizedType, 'lien') !== false) {
            return 'Remplacer les textes de liens vagues comme "Cliquez ici" ou "En savoir plus" par des textes explicites décrivant la destination du lien.';
        }
        if (stripos($normalizedType, 'button') !== false || stripos($normalizedType, 'bouton') !== false) {
            return 'Ajouter un texte visible ou un attribut aria-label descriptif au bouton. Remplacer les <div> avec des gestionnaires de clic par de vraies balises <button>.';
        }
        if (stripos($normalizedType, 'heading') !== false || stripos($normalizedType, 'titre') !== false || preg_match('/h[1-6]/i', $normalizedType)) {
            return 'Respecter la hiérarchie des titres : un seul <h1> par page, puis <h2>, <h3>, etc. sans sauter de niveau.';
        }
        if (stripos($normalizedType, 'label') !== false || stripos($normalizedType, 'étiquette') !== false) {
            return 'Associer chaque champ de formulaire à un <label> explicite en utilisant l\'attribut for="" ou en englobant le champ dans le <label>.';
        }
        if (stripos($normalizedType, 'aria') !== false) {
            return 'Vérifier l\'utilisation correcte des attributs ARIA. Ajouter aria-label ou aria-labelledby pour les éléments interactifs sans texte visible.';
        }
        if (stripos($normalizedType, 'keyboard') !== false || stripos($normalizedType, 'clavier') !== false || stripos($normalizedType, 'focus') !== false) {
            return 'S\'assurer que tous les éléments interactifs sont accessibles au clavier et affichent un indicateur de focus visible.';
        }
        if (stripos($normalizedType, 'color') !== false || stripos($normalizedType, 'couleur') !== false) {
            return 'Ne pas transmettre d\'information uniquement par la couleur. Ajouter des icônes, du texte, ou des motifs.';
        }
        if (stripos($normalizedType, 'lang') !== false || stripos($normalizedType, 'langue') !== false) {
            return 'Définir la langue du document avec l\'attribut lang sur la balise <html> (ex: <html lang="fr">).';
        }

        return 'Corriger l\'élément ' . ($result->getSelector() ?: 'identifié') . ' pour respecter le critère RGAA correspondant.';
    }
}
