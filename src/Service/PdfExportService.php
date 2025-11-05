<?php

namespace App\Service;

use App\Entity\Audit;
use App\Repository\AuditResultRepository;
use App\Repository\ManualCheckRepository;
use Knp\Snappy\Pdf;
use Twig\Environment;

class PdfExportService
{
    public function __construct(
        private Environment $twig,
        private AuditResultRepository $resultRepository,
        private RgaaThemeService $rgaaThemeService,
        private IssuePriorityService $priorityService,
        private RgaaOfficialService $rgaaReferenceService,
        private RgaaReferenceService $rgaaOldReferenceService,
        private ManualCheckRepository $manualCheckRepository,
        private ?Pdf $pdf = null
    ) {
    }

    /**
     * Generate PDF report for an audit
     */
    public function generateReport(Audit $audit): string
    {
        // Get all results
        $results = $this->resultRepository->findGroupedBySeverity($audit);

        // Group by RGAA theme (same logic as AuditController)
        $groupedByTheme = [];
        $processedIds = [];

        foreach ($results as $result) {
            $resultId = $result->getId();
            if (in_array($resultId, $processedIds)) {
                continue;
            }
            $processedIds[] = $resultId;

            $severity = $result->getSeverity();
            $themeNum = (int) $this->rgaaThemeService->getThemeFromResult($result);
            $theme = $this->rgaaThemeService->getTheme($themeNum);
            $criterion = $this->rgaaThemeService->getCriteriaFromResult($result);
            $criterionKey = $criterion ?? 'non-categorise';

            if (!isset($groupedByTheme[$themeNum])) {
                $groupedByTheme[$themeNum] = [
                    'theme_number' => $themeNum,
                    'theme_name' => $theme['name'],
                    'theme_icon' => $theme['icon'],
                    'theme_color' => $theme['color'],
                    'criteria' => [],
                    'total_count' => 0
                ];
            }

            if (!isset($groupedByTheme[$themeNum]['criteria'][$criterionKey])) {
                $groupedByTheme[$themeNum]['criteria'][$criterionKey] = [
                    'criterion' => $criterion,
                    'criterion_description' => $criterion ? $this->rgaaThemeService->getCriterionDescription($criterion) : '',
                    'results' => [
                        'critical' => [],
                        'major' => [],
                        'minor' => []
                    ],
                    'total_count' => 0
                ];
            }

            $errorType = $result->getErrorType();
            $found = false;
            foreach ($groupedByTheme[$themeNum]['criteria'][$criterionKey]['results'][$severity] as &$group) {
                if ($group['errorType'] === $errorType && $group['source'] === $result->getSource()) {
                    $group['occurrences'][] = $result;
                    $found = true;
                    break;
                }
            }

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
                    'priorityScore' => 0
                ];
            }

            $groupedByTheme[$themeNum]['criteria'][$criterionKey]['total_count']++;
            $groupedByTheme[$themeNum]['total_count']++;
        }

        // Sort themes by number (1, 2, 3, etc.)
        ksort($groupedByTheme, SORT_NUMERIC);

        // Calculate priority scores
        foreach ($groupedByTheme as &$theme) {
            foreach ($theme['criteria'] as &$criterionData) {
                foreach (['critical', 'major', 'minor'] as $severity) {
                    foreach ($criterionData['results'][$severity] as &$group) {
                        $group['priorityScore'] = $this->priorityService->calculatePriorityScore(
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

        // Get top priority issues and statistics
        $topPriorityIssues = $this->priorityService->getTopPriorityIssues($groupedByTheme, 5);
        $priorityStatistics = $this->priorityService->getPriorityStatistics($groupedByTheme);

        // Count Gemini Vision issues
        $geminiVisionCount = 0;
        foreach ($results as $result) {
            if ($result->getSource() === 'gemini-vision') {
                $geminiVisionCount++;
            }
        }

        // Calculate statistics (same as AuditController)
        $totalThemes = count($groupedByTheme);
        $totalCriteria = 0;
        foreach ($groupedByTheme as $theme) {
            $totalCriteria += count($theme['criteria']);
        }

        // Extract non-conform criteria details (same as AuditController)
        $nonConformDetails = [];
        foreach ($groupedByTheme as $theme) {
            foreach ($theme['criteria'] as $criterionKey => $criterionData) {
                // Skip non-categorized and invalid criteria
                if ($criterionKey === 'non-categorise' || $criterionData['total_count'] <= 0) {
                    continue;
                }

                // Skip invalid WCAG criteria (conformance levels)
                if (preg_match('/^WCAG:(wcag\d+[a]{1,3})$/i', $criterionKey)) {
                    continue;
                }

                // Get criterion info from RGAA reference
                $isWcagOnly = str_starts_with($criterionKey, 'WCAG:');
                $criterionInfo = $isWcagOnly ? null : $this->rgaaReferenceService->getCriterionByNumber($criterionKey);

                // Collect unique error types by severity
                $errorSummary = [];
                $errorsBySeverity = [
                    'critical' => [],
                    'major' => [],
                    'minor' => []
                ];

                foreach (['critical', 'major', 'minor'] as $sev) {
                    if (isset($criterionData['results'][$sev])) {
                        foreach ($criterionData['results'][$sev] as $group) {
                            $key = $group['errorType'] ?? 'unknown';
                            if (!isset($errorSummary[$key])) {
                                $errorSummary[$key] = [
                                    'count' => 0,
                                    'recommendation' => $group['recommendation'] ?? '',
                                    'impactUser' => $group['impactUser'] ?? '',
                                    'severity' => $sev
                                ];
                            }
                            $errorSummary[$key]['count'] += count($group['occurrences'] ?? []);

                            // Add to severity-grouped array
                            $errorsBySeverity[$sev][] = [
                                'errorType' => $key,
                                'count' => count($group['occurrences'] ?? []),
                                'recommendation' => $group['recommendation'] ?? '',
                                'impactUser' => $group['impactUser'] ?? ''
                            ];
                        }
                    }
                }

                // Build display title and reason
                $displayTitle = '';
                $reason = '';

                if ($criterionInfo) {
                    $displayTitle = $criterionInfo['title'];
                    // Use title as reason since there's no description field
                    $reason = $criterionInfo['title'];
                } else {
                    if ($isWcagOnly) {
                        $wcagNumber = str_replace('WCAG:', '', $criterionKey);
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
                    'reason' => $reason,
                    'errorsBySeverity' => $errorsBySeverity
                ];
            }
        }

        // Sort non-conform details by criteria number (e.g., 1.1, 1.2, 2.1, etc.)
        usort($nonConformDetails, function($a, $b) {
            // Split criteria numbers like "1.1" into [1, 1]
            $aParts = array_map('intval', explode('.', $a['criteriaNumber']));
            $bParts = array_map('intval', explode('.', $b['criteriaNumber']));

            // Compare major version first (1.x vs 2.x)
            if ($aParts[0] !== $bParts[0]) {
                return $aParts[0] - $bParts[0];
            }

            // Then compare minor version (x.1 vs x.2)
            return ($aParts[1] ?? 0) - ($bParts[1] ?? 0);
        });

        // Get criteria status for the summary table
        $auditStatistics = [
            'statistics' => [
                'nonConformDetails' => array_map(fn($detail) => [
                    'criteriaNumber' => $detail['criteriaNumber'],
                    'errorCount' => $detail['errorCount']
                ], $nonConformDetails)
            ]
        ];
        $criteriaStatus = $this->rgaaOldReferenceService->getCriteriaStatus($auditStatistics);
        $criteriaByTopic = $this->rgaaOldReferenceService->getCriteriaByTopic();

        // Get manual checks
        $manualChecks = $this->manualCheckRepository->findByAudit($audit);
        $manualChecksMap = [];
        foreach ($manualChecks as $check) {
            $manualChecksMap[$check->getCriteriaNumber()] = [
                'status' => $check->getStatus(),
                'notes' => $check->getNotes()
            ];
        }

        // Build criteria status by topic for the summary table
        $criteriaStatusByTopic = [];

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
        }

        // Render HTML template
        $html = $this->twig->render('audit/pdf_report.html.twig', [
            'audit' => $audit,
            'grouped_by_theme' => $groupedByTheme,
            'top_priority_issues' => $topPriorityIssues,
            'priority_statistics' => $priorityStatistics,
            'gemini_vision_count' => $geminiVisionCount,
            'total_themes' => $totalThemes,
            'total_criteria' => $totalCriteria,
            'non_conform_details' => $nonConformDetails,
            'criteria_status_by_topic' => $criteriaStatusByTopic,
            'generated_at' => new \DateTimeImmutable(),
        ]);

        // If Snappy is configured, generate PDF
        if ($this->pdf !== null) {
            return $this->pdf->getOutputFromHtml($html, [
                'encoding' => 'UTF-8',
                'page-size' => 'A4',
                'margin-top' => 8,
                'margin-right' => 8,
                'margin-bottom' => 8,
                'margin-left' => 8,
            ]);
        }

        // Fallback: return HTML
        return $html;
    }

    /**
     * Get filename for PDF export
     */
    public function getFilename(Audit $audit): string
    {
        $date = $audit->getCreatedAt()->format('Y-m-d');
        $urlSlug = preg_replace('/[^a-z0-9]+/i', '-', parse_url($audit->getUrl(), PHP_URL_HOST) ?? 'audit');
        return "audit-rgaa-{$urlSlug}-{$date}.pdf";
    }
}
