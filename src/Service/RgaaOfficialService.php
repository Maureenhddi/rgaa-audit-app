<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Service to manage the official RGAA 4.1 reference with full hierarchy:
 * Topics > Criteria > Tests
 */
class RgaaOfficialService
{
    private array $data = [];
    private array $topics = [];

    public function __construct(
        private ParameterBagInterface $params
    ) {
        $this->loadOfficialData();
    }

    /**
     * Load RGAA official data with tests from JSON file
     */
    private function loadOfficialData(): void
    {
        $jsonPath = $this->params->get('kernel.project_dir') . '/config/rgaa_criteria_with_tests.json';

        if (!file_exists($jsonPath)) {
            throw new \RuntimeException('RGAA official criteria file not found: ' . $jsonPath);
        }

        $data = json_decode(file_get_contents($jsonPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to parse RGAA criteria JSON: ' . json_last_error_msg());
        }

        $this->data = $data;
        $this->topics = $data['topics'] ?? [];
    }

    /**
     * Get all topics with their criteria and tests
     */
    public function getAllTopics(): array
    {
        return $this->topics;
    }

    /**
     * Get a specific criterion by topic number and criterion number
     * Example: getCriterion(1, 1) returns criterion 1.1
     */
    public function getCriterion(int $topicNumber, int $criterionNumber): ?array
    {
        foreach ($this->topics as $topic) {
            if ($topic['number'] === $topicNumber) {
                foreach ($topic['criteria'] as $criterion) {
                    if ($criterion['criterium']['number'] === $criterionNumber) {
                        return $criterion['criterium'];
                    }
                }
            }
        }
        return null;
    }

    /**
     * Get a criterion by its full number (e.g., "1.1")
     */
    public function getCriterionByNumber(string $number): ?array
    {
        [$topicNum, $criterionNum] = explode('.', $number);
        return $this->getCriterion((int)$topicNum, (int)$criterionNum);
    }

    /**
     * Get all criteria flattened (for backward compatibility)
     */
    public function getAllCriteriaFlat(): array
    {
        $criteria = [];
        foreach ($this->topics as $topic) {
            foreach ($topic['criteria'] as $criterion) {
                $criterium = $criterion['criterium'];
                $criteria[] = [
                    'number' => $topic['number'] . '.' . $criterium['number'],
                    'title' => $criterium['title'],
                    'topic' => $topic['topic'],
                    'tests' => $criterium['tests'] ?? [],
                    'references' => $criterium['references'] ?? [],
                ];
            }
        }
        return $criteria;
    }

    /**
     * Get topics with criteria structured for display
     */
    public function getTopicsForDisplay(): array
    {
        $structured = [];

        foreach ($this->topics as $topic) {
            $topicData = [
                'name' => $topic['topic'],
                'number' => $topic['number'],
                'criteria' => []
            ];

            foreach ($topic['criteria'] as $criterion) {
                $criterium = $criterion['criterium'];
                $criterionNumber = $topic['number'] . '.' . $criterium['number'];

                // Format tests for display
                $tests = [];
                if (isset($criterium['tests'])) {
                    foreach ($criterium['tests'] as $testNumber => $testContent) {
                        // Determine if it's an array or string
                        if (is_array($testContent)) {
                            // Array format with conditions
                            $tests[] = [
                                'number' => $criterionNumber . '.' . $testNumber,
                                'type' => 'array',
                                'description' => $this->cleanMarkdownLinks($testContent[0] ?? ''),
                                'conditions' => array_map(
                                    fn($c) => $this->cleanMarkdownLinks($c),
                                    array_slice($testContent, 1)
                                )
                            ];
                        } else {
                            // Simple string format
                            $tests[] = [
                                'number' => $criterionNumber . '.' . $testNumber,
                                'type' => 'string',
                                'content' => $this->cleanMarkdownLinks($testContent)
                            ];
                        }
                    }
                }

                $topicData['criteria'][] = [
                    'number' => $criterionNumber,
                    'title' => $this->cleanMarkdownLinks($criterium['title']),
                    'tests' => $tests,
                    'references' => $criterium['references'] ?? [],
                    'technicalNote' => $criterium['technicalNote'] ?? null,
                    'particularCases' => $criterium['particularCases'] ?? null,
                ];
            }

            $structured[] = $topicData;
        }

        return $structured;
    }

    /**
     * Clean markdown links from text [text](#link) -> text
     */
    private function cleanMarkdownLinks(string $text): string
    {
        // Remove markdown links [text](#link) and keep only the text
        return preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);
    }

    /**
     * Format test content for display (handle both string and array)
     */
    public function formatTestContent($testContent): array
    {
        if (is_string($testContent)) {
            return [
                'type' => 'simple',
                'description' => $this->cleanMarkdownLinks($testContent)
            ];
        }

        if (is_array($testContent)) {
            return [
                'type' => 'conditions',
                'description' => $this->cleanMarkdownLinks($testContent[0] ?? ''),
                'conditions' => array_map(
                    fn($c) => $this->cleanMarkdownLinks($c),
                    array_slice($testContent, 1)
                )
            ];
        }

        return [
            'type' => 'unknown',
            'description' => ''
        ];
    }

    /**
     * Get total counts
     */
    public function getCounts(): array
    {
        $topicsCount = count($this->topics);
        $criteriaCount = 0;
        $testsCount = 0;

        foreach ($this->topics as $topic) {
            $criteriaCount += count($topic['criteria']);
            foreach ($topic['criteria'] as $criterion) {
                $testsCount += count($criterion['criterium']['tests'] ?? []);
            }
        }

        return [
            'topics' => $topicsCount,
            'criteria' => $criteriaCount,
            'tests' => $testsCount
        ];
    }

    /**
     * Get WCAG version
     */
    public function getWcagVersion(): ?string
    {
        return $this->data['wcag']['version'] ?? null;
    }
}
