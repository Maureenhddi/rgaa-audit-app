<?php

namespace App\Service;

use App\Entity\Audit;
use App\Repository\AuditResultRepository;

class CsvExportService
{
    public function __construct(
        private AuditResultRepository $resultRepository,
        private RgaaThemeService $rgaaThemeService,
        private IssuePriorityService $priorityService
    ) {
    }

    /**
     * Generate CSV export for an audit
     */
    public function generateCsv(Audit $audit): string
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

        // Generate CSV
        $output = fopen('php://temp', 'r+');

        // BOM for UTF-8
        fwrite($output, "\xEF\xBB\xBF");

        // CSV Header
        fputcsv($output, [
            'Thème',
            'Numéro Thème',
            'Critère RGAA',
            'Description Critère',
            'Type d\'erreur',
            'Sévérité',
            'Score Priorité',
            'Niveau Priorité',
            'Nombre d\'occurrences',
            'Source',
            'Description',
            'Impact Utilisateur',
            'Recommandation',
            'Critère WCAG',
            'Exemple de correction',
            'Sélecteur',
            'Contexte'
        ], ';');

        // CSV Rows
        foreach ($groupedByTheme as $theme) {
            foreach ($theme['criteria'] as $criterionKey => $criterionData) {
                foreach (['critical', 'major', 'minor'] as $severity) {
                    foreach ($criterionData['results'][$severity] as $group) {
                        $priorityLevel = $this->getPriorityLevel($group['priorityScore']);

                        // One row per error type (with aggregated occurrences)
                        $firstOccurrence = $group['occurrences'][0] ?? null;

                        fputcsv($output, [
                            $theme['theme_name'],
                            $theme['theme_number'],
                            $criterionData['criterion'] ?? 'N/A',
                            $criterionData['criterion_description'] ?? '',
                            $group['errorType'] ?? '',
                            strtoupper($severity),
                            $group['priorityScore'],
                            $priorityLevel,
                            count($group['occurrences']),
                            $this->formatSource($group['source']),
                            $this->cleanCsvText($group['description'] ?? ''),
                            $this->cleanCsvText($group['impactUser'] ?? ''),
                            $this->cleanCsvText($group['recommendation'] ?? ''),
                            $group['wcagCriteria'] ?? '',
                            $this->cleanCsvText($group['codeFix'] ?? ''),
                            $firstOccurrence ? $this->cleanCsvText($firstOccurrence->getSelector() ?? '') : '',
                            $firstOccurrence ? $this->cleanCsvText($firstOccurrence->getContext() ?? '') : ''
                        ], ';');
                    }
                }
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Get priority level text
     */
    private function getPriorityLevel(int $score): string
    {
        if ($score >= 80) {
            return 'Priorité 1 - Très urgent';
        } elseif ($score >= 60) {
            return 'Priorité 2 - Urgent';
        } elseif ($score >= 40) {
            return 'Priorité 3 - Moyen';
        } else {
            return 'Priorité 4 - Faible';
        }
    }

    /**
     * Format source name
     */
    private function formatSource(string $source): string
    {
        return match($source) {
            'playwright' => 'Playwright',
            'axe-core' => 'Axe-core',
            'html_codesniffer' => 'HTML_CodeSniffer',
            'a11ylint' => 'A11yLint',
            'gemini-vision', 'gemini-image-analysis' => 'Gemini AI Vision',
            'ia_context' => 'Gemini AI Context',
            default => ucfirst($source)
        };
    }

    /**
     * Clean text for CSV (remove line breaks, excessive spaces)
     */
    private function cleanCsvText(?string $text): string
    {
        if (!$text) {
            return '';
        }

        // Remove HTML tags
        $text = strip_tags($text);

        // Replace line breaks with spaces
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);

        // Remove excessive spaces
        $text = preg_replace('/\s+/', ' ', $text);

        // Trim
        $text = trim($text);

        return $text;
    }

    /**
     * Get filename for CSV export
     */
    public function getFilename(Audit $audit): string
    {
        $date = $audit->getCreatedAt()->format('Y-m-d');
        $urlSlug = preg_replace('/[^a-z0-9]+/i', '-', parse_url($audit->getUrl(), PHP_URL_HOST) ?? 'audit');
        return "audit-rgaa-{$urlSlug}-{$date}.csv";
    }
}
