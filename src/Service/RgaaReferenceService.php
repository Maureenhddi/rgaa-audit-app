<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class RgaaReferenceService
{
    private array $criteria = [];

    public function __construct(
        private ParameterBagInterface $params
    ) {
        $this->loadCriteria();
    }

    /**
     * Load RGAA criteria from JSON file
     */
    private function loadCriteria(): void
    {
        $jsonPath = $this->params->get('kernel.project_dir') . '/config/rgaa_criteria.json';

        if (!file_exists($jsonPath)) {
            throw new \RuntimeException('RGAA criteria file not found: ' . $jsonPath);
        }

        $data = json_decode(file_get_contents($jsonPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to parse RGAA criteria JSON: ' . json_last_error_msg());
        }

        $this->criteria = $data['criteria'] ?? [];
    }

    /**
     * Get all RGAA criteria
     */
    public function getAllCriteria(): array
    {
        return $this->criteria;
    }

    /**
     * Get criteria by number
     */
    public function getCriteriaByNumber(string $number): ?array
    {
        foreach ($this->criteria as $criteria) {
            if ($criteria['number'] === $number) {
                return $criteria;
            }
        }
        return null;
    }

    /**
     * Get criteria grouped by topic
     */
    public function getCriteriaByTopic(): array
    {
        $grouped = [];
        foreach ($this->criteria as $criteria) {
            $topic = $criteria['topic'];
            if (!isset($grouped[$topic])) {
                $grouped[$topic] = [];
            }
            $grouped[$topic][] = $criteria;
        }
        return $grouped;
    }

    /**
     * Get all auto-testable criteria numbers
     */
    public function getAutoTestableCriteria(): array
    {
        return array_filter($this->criteria, fn($c) => $c['autoTestable'] === true);
    }

    /**
     * Get all manual-only criteria numbers
     */
    public function getManualOnlyCriteria(): array
    {
        return array_filter($this->criteria, fn($c) => $c['autoTestable'] === false);
    }

    /**
     * Check if a criteria number is auto-testable
     */
    public function isAutoTestable(string $number): bool
    {
        $criteria = $this->getCriteriaByNumber($number);
        return $criteria ? ($criteria['autoTestable'] ?? false) : false;
    }

    /**
     * Get criteria status for an audit
     * Returns array with:
     * - tested: criteria numbers that were tested
     * - conform: criteria that are conform
     * - nonConform: criteria that are non-conform
     * - notTested: criteria that were not tested
     * - notApplicable: criteria marked as not applicable
     */
    public function getCriteriaStatus(array $auditData): array
    {
        $allCriteria = array_column($this->criteria, 'number');

        // Extract tested criteria from audit data
        $testedCriteria = [];
        $conformCriteria = [];
        $nonConformCriteria = [];

        // Get non-conform criteria from audit statistics
        if (isset($auditData['statistics']['nonConformDetails'])) {
            foreach ($auditData['statistics']['nonConformDetails'] as $detail) {
                $criteriaNumber = $detail['criteriaNumber'] ?? null;
                if ($criteriaNumber) {
                    $nonConformCriteria[] = $criteriaNumber;
                    $testedCriteria[] = $criteriaNumber;
                }
            }
        }

        // All auto-testable criteria that are not non-conform are considered conform
        $autoTestable = array_column($this->getAutoTestableCriteria(), 'number');
        foreach ($autoTestable as $number) {
            if (!in_array($number, $nonConformCriteria)) {
                $conformCriteria[] = $number;
                if (!in_array($number, $testedCriteria)) {
                    $testedCriteria[] = $number;
                }
            }
        }

        // Criteria that were not tested
        $notTested = array_diff($allCriteria, $testedCriteria);

        return [
            'all' => $allCriteria,
            'tested' => array_unique($testedCriteria),
            'conform' => array_unique($conformCriteria),
            'nonConform' => array_unique($nonConformCriteria),
            'notTested' => array_values($notTested),
            'notApplicable' => [] // To be implemented if needed
        ];
    }

    /**
     * Get total count
     */
    public function getTotalCount(): int
    {
        return count($this->criteria);
    }
}
