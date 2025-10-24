<?php

namespace App\Service;

class RgaaThemeService
{
    private array $rgaaCriteria;
    private array $themes;

    public function __construct(
        private string $projectDir
    ) {
        $this->loadRgaaCriteria();
        $this->buildThemes();
    }

    /**
     * Load RGAA criteria from JSON file
     */
    private function loadRgaaCriteria(): void
    {
        $jsonPath = $this->projectDir . '/config/rgaa_criteria.json';
        $jsonContent = file_get_contents($jsonPath);
        $data = json_decode($jsonContent, true);
        $this->rgaaCriteria = $data['criteria'] ?? [];
    }

    /**
     * Build themes from criteria topics
     */
    private function buildThemes(): void
    {
        // Theme metadata (colors and icons)
        $themeMetadata = [
            'Images' => ['icon' => 'bi-image', 'color' => '#e74c3c'],
            'Cadres' => ['icon' => 'bi-window', 'color' => '#3498db'],
            'Couleurs' => ['icon' => 'bi-palette', 'color' => '#9b59b6'],
            'Multimédia' => ['icon' => 'bi-play-circle', 'color' => '#e67e22'],
            'Tableaux' => ['icon' => 'bi-table', 'color' => '#1abc9c'],
            'Liens' => ['icon' => 'bi-link-45deg', 'color' => '#3498db'],
            'Scripts' => ['icon' => 'bi-code-slash', 'color' => '#34495e'],
            'Éléments obligatoires' => ['icon' => 'bi-exclamation-triangle', 'color' => '#e74c3c'],
            'Structuration de l\'information' => ['icon' => 'bi-diagram-3', 'color' => '#16a085'],
            'Présentation de l\'information' => ['icon' => 'bi-layout-text-window', 'color' => '#27ae60'],
            'Formulaires' => ['icon' => 'bi-ui-checks', 'color' => '#2980b9'],
            'Navigation' => ['icon' => 'bi-compass', 'color' => '#8e44ad'],
            'Consultation' => ['icon' => 'bi-eye', 'color' => '#c0392b'],
        ];

        // Build themes dynamically from criteria
        $this->themes = [];
        foreach ($this->rgaaCriteria as $criterion) {
            $topic = $criterion['topic'];
            $number = (int) explode('.', $criterion['number'])[0];

            if (!isset($this->themes[$number]) && isset($themeMetadata[$topic])) {
                $this->themes[$number] = [
                    'name' => $topic,
                    'icon' => $themeMetadata[$topic]['icon'],
                    'color' => $themeMetadata[$topic]['color'],
                ];
            }
        }

        // Add "uncategorized" theme
        $this->themes[0] = [
            'name' => 'Problèmes techniques détectés automatiquement',
            'icon' => 'bi-tools',
            'color' => '#95a5a6'
        ];
    }

    /**
     * Get theme number from RGAA criteria
     * Example: "1.1" => 1, "11.2" => 11
     */
    public function getThemeNumberFromRgaaCriteria(?string $rgaaCriteria): int
    {
        if (!$rgaaCriteria) {
            return 0;
        }

        // Extract first number before the dot
        if (preg_match('/^(\d+)\./', $rgaaCriteria, $matches)) {
            $themeNum = (int) $matches[1];
            return ($themeNum >= 1 && $themeNum <= 13) ? $themeNum : 0;
        }

        return 0;
    }

    /**
     * Get theme information
     */
    public function getTheme(int $themeNumber): array
    {
        return $this->themes[$themeNumber] ?? $this->themes[0];
    }

    /**
     * Get all themes
     */
    public function getAllThemes(): array
    {
        return $this->themes;
    }

    /**
     * Map WCAG criteria to RGAA theme (approximate mapping)
     */
    public function getThemeFromWcagCriteria(?string $wcagCriteria): int
    {
        if (!$wcagCriteria) {
            return 0;
        }

        // Mapping WCAG 2.1 to RGAA themes (approximate)
        $wcagToRgaaMap = [
            // WCAG 1.1 (Text Alternatives) -> RGAA 1 (Images)
            '1.1' => 1,

            // WCAG 1.3 (Adaptable) -> RGAA 9 (Structuration)
            '1.3' => 9,

            // WCAG 1.4 (Distinguishable) -> RGAA 3 (Couleurs) & 10 (Présentation)
            '1.4.1' => 3,  // Use of Color
            '1.4.3' => 3,  // Contrast
            '1.4.6' => 3,  // Enhanced Contrast
            '1.4.11' => 3, // Non-text Contrast
            '1.4' => 10,   // Other 1.4 criteria

            // WCAG 2.1 (Keyboard Accessible) -> RGAA 12 (Navigation)
            '2.1' => 12,

            // WCAG 2.4 (Navigable) -> RGAA 12 (Navigation)
            '2.4' => 12,

            // WCAG 2.5 (Input Modalities) -> RGAA 12 (Navigation)
            '2.5' => 12,

            // WCAG 3.2 (Predictable) -> RGAA 7 (Scripts)
            '3.2' => 7,

            // WCAG 3.3 (Input Assistance) -> RGAA 11 (Formulaires)
            '3.3' => 11,

            // WCAG 4.1 (Compatible) -> RGAA 8 (Éléments obligatoires)
            '4.1' => 8,
        ];

        // Try to match the most specific criteria first
        foreach ($wcagToRgaaMap as $wcagPattern => $rgaaTheme) {
            if (stripos($wcagCriteria, $wcagPattern) !== false) {
                return $rgaaTheme;
            }
        }

        return 0;
    }

    /**
     * Get theme from result (tries RGAA first, then WCAG)
     */
    public function getThemeFromResult($result): int
    {
        $rgaaCriteria = is_object($result) ? $result->getRgaaCriteria() : ($result['rgaaCriteria'] ?? null);
        $wcagCriteria = is_object($result) ? $result->getWcagCriteria() : ($result['wcagCriteria'] ?? null);

        // Try RGAA first
        $themeNum = $this->getThemeNumberFromRgaaCriteria($rgaaCriteria);

        // Fallback to WCAG mapping
        if ($themeNum === 0) {
            $themeNum = $this->getThemeFromWcagCriteria($wcagCriteria);
        }

        return $themeNum;
    }

    /**
     * Get RGAA criteria from result (or infer from WCAG)
     */
    public function getCriteriaFromResult($result): ?string
    {
        $rgaaCriteria = is_object($result) ? $result->getRgaaCriteria() : ($result['rgaaCriteria'] ?? null);
        $wcagCriteria = is_object($result) ? $result->getWcagCriteria() : ($result['wcagCriteria'] ?? null);

        // Return RGAA criteria if available
        if ($rgaaCriteria) {
            return $rgaaCriteria;
        }

        // Try to infer from WCAG
        if ($wcagCriteria) {
            // Extract first WCAG criterion if multiple
            $wcagParts = explode(',', $wcagCriteria);
            $firstWcag = trim($wcagParts[0]);

            // Return WCAG as fallback with "WCAG:" prefix
            return "WCAG:" . $firstWcag;
        }

        return null;
    }

    /**
     * Get criterion description from JSON data
     */
    public function getCriterionDescription(string $criterion): string
    {
        // Search in loaded RGAA criteria
        foreach ($this->rgaaCriteria as $rgaaCriterion) {
            if ($rgaaCriterion['number'] === $criterion) {
                return $rgaaCriterion['title'] ?? '';
            }
        }

        // If it's a WCAG criterion without RGAA mapping or truly uncategorized, return empty
        return '';
    }
}
