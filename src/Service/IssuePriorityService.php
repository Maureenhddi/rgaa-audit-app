<?php

namespace App\Service;

/**
 * Service to calculate priority scores for accessibility issues
 *
 * Priority scoring is based on:
 * - Severity (critical > major > minor): 40%
 * - Number of occurrences (more = higher priority): 30%
 * - User impact (blocking > high > medium > low): 20%
 * - WCAG level (A > AA > AAA): 10%
 */
class IssuePriorityService
{
    /**
     * Calculate priority score for an issue group (0-100)
     *
     * @param string $severity 'critical', 'major', or 'minor'
     * @param int $occurrenceCount Number of times this issue appears
     * @param string|null $impactUser Description of user impact
     * @param string|null $wcagCriteria WCAG criteria (e.g., "2.4.1 (A)")
     * @return int Priority score from 0 to 100
     */
    public function calculatePriorityScore(
        string $severity,
        int $occurrenceCount,
        ?string $impactUser = null,
        ?string $wcagCriteria = null
    ): int {
        $score = 0;

        // 1. Severity weight: 40 points max
        $score += $this->getSeverityScore($severity);

        // 2. Occurrence count weight: 30 points max
        $score += $this->getOccurrenceScore($occurrenceCount);

        // 3. User impact weight: 20 points max
        $score += $this->getImpactScore($impactUser);

        // 4. WCAG level weight: 10 points max
        $score += $this->getWcagLevelScore($wcagCriteria);

        return min(100, max(0, (int) $score));
    }

    /**
     * Get top N priority issues from grouped results
     *
     * @param array $groupedByTheme Grouped issues by theme
     * @param int $limit Number of top issues to return
     * @return array Top priority issues
     */
    public function getTopPriorityIssues(array $groupedByTheme, int $limit = 5): array
    {
        $allIssues = [];

        foreach ($groupedByTheme as $theme) {
            foreach ($theme['criteria'] as $criterionKey => $criterionData) {
                foreach (['critical', 'major', 'minor'] as $severity) {
                    foreach ($criterionData['results'][$severity] as $group) {
                        $allIssues[] = [
                            'theme' => $theme['theme_name'],
                            'theme_number' => $theme['theme_number'],
                            'theme_color' => $theme['theme_color'],
                            'criterion' => $criterionData['criterion'],
                            'criterion_description' => $criterionData['criterion_description'],
                            'errorType' => $group['errorType'],
                            'severity' => $severity,
                            'occurrenceCount' => count($group['occurrences']),
                            'priorityScore' => $group['priorityScore'] ?? 0,
                            'impactUser' => $group['impactUser'] ?? null,
                            'recommendation' => $group['recommendation'] ?? null,
                            'source' => $group['source'] ?? 'unknown',
                        ];
                    }
                }
            }
        }

        // Sort by priority score (descending)
        usort($allIssues, function ($a, $b) {
            return $b['priorityScore'] <=> $a['priorityScore'];
        });

        return array_slice($allIssues, 0, $limit);
    }

    /**
     * Get statistics about issue priorities
     */
    public function getPriorityStatistics(array $groupedByTheme): array
    {
        $priorities = [
            'priority1' => 0, // >= 80
            'priority2' => 0, // >= 60
            'priority3' => 0, // >= 40
            'priority4' => 0, // < 40
        ];

        foreach ($groupedByTheme as $theme) {
            foreach ($theme['criteria'] as $criterionData) {
                foreach (['critical', 'major', 'minor'] as $severity) {
                    foreach ($criterionData['results'][$severity] as $group) {
                        $score = $group['priorityScore'] ?? 0;

                        if ($score >= 80) {
                            $priorities['priority1']++;
                        } elseif ($score >= 60) {
                            $priorities['priority2']++;
                        } elseif ($score >= 40) {
                            $priorities['priority3']++;
                        } else {
                            $priorities['priority4']++;
                        }
                    }
                }
            }
        }

        return $priorities;
    }

    /**
     * Calculate severity score (0-40 points)
     */
    private function getSeverityScore(string $severity): float
    {
        return match (strtolower($severity)) {
            'critical' => 40,
            'major' => 25,
            'minor' => 10,
            default => 0,
        };
    }

    /**
     * Calculate occurrence score (0-30 points)
     * Uses logarithmic scale to avoid over-weighting high counts
     */
    private function getOccurrenceScore(int $count): float
    {
        if ($count <= 0) {
            return 0;
        }

        // Logarithmic scale: 1 occurrence = 10, 10 = 20, 100 = 30
        return min(30, 10 + (log10($count) * 10));
    }

    /**
     * Calculate impact score from description (0-20 points)
     */
    private function getImpactScore(?string $impactUser): float
    {
        if (!$impactUser) {
            return 10; // Default medium impact
        }

        $impact = strtolower($impactUser);

        // Blocking keywords
        if (
            str_contains($impact, 'impossible') ||
            str_contains($impact, 'bloque') ||
            str_contains($impact, 'bloqu') ||
            str_contains($impact, 'empêche') ||
            str_contains($impact, 'inaccessible') ||
            str_contains($impact, 'ne peut pas')
        ) {
            return 20;
        }

        // High impact keywords
        if (
            str_contains($impact, 'difficile') ||
            str_contains($impact, 'complique') ||
            str_contains($impact, 'gêne') ||
            str_contains($impact, 'perte')
        ) {
            return 15;
        }

        // Low impact keywords
        if (
            str_contains($impact, 'minime') ||
            str_contains($impact, 'léger') ||
            str_contains($impact, 'faible') ||
            str_contains($impact, 'peu')
        ) {
            return 5;
        }

        // Default medium impact
        return 10;
    }

    /**
     * Calculate WCAG level score (0-10 points)
     */
    private function getWcagLevelScore(?string $wcagCriteria): float
    {
        if (!$wcagCriteria) {
            return 5; // Default
        }

        $criteria = strtoupper($wcagCriteria);

        if (str_contains($criteria, '(A)') || str_contains($criteria, 'LEVEL A')) {
            return 10; // Level A is most important
        }

        if (str_contains($criteria, '(AA)') || str_contains($criteria, 'LEVEL AA')) {
            return 7; // Level AA
        }

        if (str_contains($criteria, '(AAA)') || str_contains($criteria, 'LEVEL AAA')) {
            return 4; // Level AAA is nice to have
        }

        return 5; // Unknown level
    }
}
