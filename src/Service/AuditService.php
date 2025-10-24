<?php

namespace App\Service;

use App\Entity\Audit;
use App\Entity\AuditResult;
use App\Entity\User;
use App\Enum\AuditStatus;
use App\Enum\IssueSeverity;
use App\Enum\IssueSource;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AuditService
{
    public function __construct(
        private PlaywrightService $playwrightService,
        private GeminiService $geminiService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Run a complete accessibility audit
     */
    public function runCompleteAudit(string $url, User $user): Audit
    {
        // Create audit entity
        $audit = new Audit();
        $audit->setUrl($url);
        $audit->setUser($user);
        $audit->setStatus(AuditStatus::RUNNING);

        $this->entityManager->persist($audit);
        $this->entityManager->flush();

        try {
            // Step 1: Run Playwright audit (includes Axe-core + HTML_CodeSniffer)
            $this->logger->info('Starting Playwright audit with Axe-core and HTML_CodeSniffer', ['url' => $url]);
            $playwrightResults = $this->playwrightService->runAudit($url);
            $this->logger->info('Playwright audit completed', ['url' => $url]);

            // Step 2: Store all raw results
            $this->logger->info('Storing raw audit results', ['url' => $url]);
            $this->storeRawResults($audit, $playwrightResults);

            // Step 4: Analyze with Gemini and enrich results
            $this->logger->info('Starting Gemini analysis with recommendations', ['url' => $url]);
            $geminiAnalysis = $this->geminiService->analyzeResults($playwrightResults, [], $url);

            // Step 5: Update results with Gemini recommendations and summary
            $this->enrichResultsWithGemini($audit, $geminiAnalysis);

            // Update audit status
            $audit->setStatus(AuditStatus::COMPLETED);
            $audit->setUpdatedAt(new \DateTimeImmutable());

            $this->entityManager->flush();

            $this->logger->info('Audit completed successfully', [
                'audit_id' => $audit->getId(),
                'url' => $url
            ]);

            return $audit;

        } catch (\Exception $e) {
            $this->logger->error('Audit failed', [
                'audit_id' => $audit->getId(),
                'url' => $url,
                'error' => $e->getMessage()
            ]);

            $audit->setStatus(AuditStatus::FAILED);
            $audit->setErrorMessage($e->getMessage());
            $audit->setUpdatedAt(new \DateTimeImmutable());

            $this->entityManager->flush();

            throw $e;
        }
    }

    /**
     * Store all raw results from Playwright (including Axe-core and HTML_CodeSniffer)
     */
    private function storeRawResults(Audit $audit, array $playwrightResults): void
    {
        $criticalCount = 0;
        $majorCount = 0;
        $minorCount = 0;
        $playwrightCount = 0;

        $this->logger->debug('Storing raw results', [
            'playwright_keys' => array_keys($playwrightResults),
            'has_playwright_tests' => isset($playwrightResults['tests'])
        ]);

        // Store ALL Playwright issues
        if (isset($playwrightResults['tests']) && is_array($playwrightResults['tests'])) {
            foreach ($playwrightResults['tests'] as $test) {
                if (isset($test['issues']) && is_array($test['issues'])) {
                    foreach ($test['issues'] as $issue) {
                        $result = new AuditResult();
                        $result->setAudit($audit);
                        $result->setErrorType($test['name'] ?? 'Unknown Test');
                        $result->setSeverity($issue['severity'] ?? 'minor');
                        $result->setDescription($issue['message'] ?? '');
                        $result->setSelector($issue['selector'] ?? null);
                        $result->setContext($issue['context'] ?? null);

                        // Determine source based on test name
                        $testName = $test['name'] ?? '';
                        $result->setSource(IssueSource::detectFromTestName($testName));

                        // Handle wcagCriteria from Playwright/Axe-core (array to string)
                        if (isset($issue['wcagCriteria'])) {
                            $wcagValue = is_array($issue['wcagCriteria'])
                                ? implode(', ', $issue['wcagCriteria'])
                                : $issue['wcagCriteria'];
                            $result->setWcagCriteria($wcagValue);
                        }

                        // Count by severity
                        match($result->getSeverity()) {
                            IssueSeverity::CRITICAL => $criticalCount++,
                            IssueSeverity::MAJOR => $majorCount++,
                            IssueSeverity::MINOR => $minorCount++,
                            default => null
                        };

                        $audit->addAuditResult($result);
                        $this->entityManager->persist($result);
                        $playwrightCount++;
                    }
                }
            }
        }

        // Update counts
        $audit->setCriticalCount($criticalCount);
        $audit->setMajorCount($majorCount);
        $audit->setMinorCount($minorCount);
        $audit->setTotalIssues($criticalCount + $majorCount + $minorCount);

        $this->logger->info('Raw results stored', [
            'playwright_issues' => $playwrightCount,
            'total_issues' => $playwrightCount
        ]);

        $this->entityManager->flush();
    }

    /**
     * Enrich stored results with Gemini recommendations
     */
    private function enrichResultsWithGemini(Audit $audit, array $analysis): void
    {
        // Update summary
        if (isset($analysis['summary'])) {
            $audit->setSummary($analysis['summary']);
        }

        if (isset($analysis['conformityRate'])) {
            $audit->setConformityRate((string) $analysis['conformityRate']);
        }

        if (isset($analysis['statistics'])) {
            $stats = $analysis['statistics'];
            $audit->setConformCriteria($stats['conformCriteria'] ?? 0);
            $audit->setNonConformCriteria($stats['nonConformCriteria'] ?? 0);
            $audit->setNotApplicableCriteria($stats['notApplicableCriteria'] ?? 0);

            // Store detailed list of non-conformant criteria
            if (isset($stats['nonConformDetails']) && is_array($stats['nonConformDetails'])) {
                $audit->setNonConformDetails($stats['nonConformDetails']);
            }
        }

        // Enrich ALL stored results with Gemini recommendations
        if (isset($analysis['results']) && is_array($analysis['results'])) {
            $storedResults = $audit->getAuditResults()->toArray();
            $enrichedCount = 0;

            $this->logger->info('Enriching results', [
                'gemini_recommendations' => count($analysis['results']),
                'stored_results' => count($storedResults)
            ]);

            foreach ($analysis['results'] as $geminiResult) {
                $geminiType = $geminiResult['errorType'] ?? '';
                $geminiSource = $geminiResult['source'] ?? '';
                $matchCount = 0;

                // Apply this recommendation to ALL matching errors
                foreach ($storedResults as $storedResult) {
                    // Match by errorType and source
                    $storedType = $storedResult->getErrorType();
                    $storedSource = $storedResult->getSource();

                    // Vérifier si le type correspond (exact ou contient)
                    $matchesType = ($storedType === $geminiType)
                                   || stripos($storedType, $geminiType) !== false
                                   || stripos($geminiType, $storedType) !== false;
                    $matchesSource = ($storedSource === $geminiSource);

                    if ($matchesType && $matchesSource) {
                        // Appliquer les recommandations Gemini à TOUTES les occurrences de ce type
                        if (isset($geminiResult['recommendation'])) {
                            $storedResult->setRecommendation($geminiResult['recommendation']);
                        }
                        if (isset($geminiResult['codeFix'])) {
                            $storedResult->setCodeFix($geminiResult['codeFix']);
                        }
                        if (isset($geminiResult['impactUser'])) {
                            $storedResult->setImpactUser($geminiResult['impactUser']);
                        }
                        if (isset($geminiResult['wcagCriteria'])) {
                            $wcagValue = is_array($geminiResult['wcagCriteria'])
                                ? implode(', ', $geminiResult['wcagCriteria'])
                                : $geminiResult['wcagCriteria'];
                            $storedResult->setWcagCriteria($wcagValue);
                        }
                        if (isset($geminiResult['rgaaCriteria'])) {
                            $rgaaValue = is_array($geminiResult['rgaaCriteria'])
                                ? implode(', ', $geminiResult['rgaaCriteria'])
                                : $geminiResult['rgaaCriteria'];
                            $storedResult->setRgaaCriteria($rgaaValue);
                        }

                        $this->entityManager->persist($storedResult);
                        $matchCount++;
                        $enrichedCount++;
                        // Ne pas break - on continue pour enrichir TOUTES les occurrences
                    }
                }

                if ($matchCount > 0) {
                    $this->logger->info('Applied recommendation', [
                        'type' => $geminiType,
                        'source' => $geminiSource,
                        'enriched_count' => $matchCount
                    ]);
                } else {
                    $this->logger->warning('No match found for recommendation', [
                        'type' => $geminiType,
                        'source' => $geminiSource
                    ]);
                }
            }

            $this->logger->info('Enrichment completed', [
                'total_enriched' => $enrichedCount,
                'total_stored' => count($storedResults)
            ]);
        }

        $this->entityManager->flush();
    }

    /**
     * Compare two audits
     */
    public function compareAudits(Audit $audit1, Audit $audit2): array
    {
        return [
            'conformity_difference' => (float)$audit2->getConformityRate() - (float)$audit1->getConformityRate(),
            'critical_difference' => $audit2->getCriticalCount() - $audit1->getCriticalCount(),
            'major_difference' => $audit2->getMajorCount() - $audit1->getMajorCount(),
            'minor_difference' => $audit2->getMinorCount() - $audit1->getMinorCount(),
            'total_difference' => $audit2->getTotalIssues() - $audit1->getTotalIssues(),
            'audit1' => $audit1,
            'audit2' => $audit2,
        ];
    }
}
