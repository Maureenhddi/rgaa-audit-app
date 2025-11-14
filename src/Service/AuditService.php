<?php

namespace App\Service;

use App\Entity\Audit;
use App\Entity\AuditResult;
use App\Entity\User;
use App\Enum\AuditStatus;
use App\Enum\ContextualAnalysisType;
use App\Enum\IssueSeverity;
use App\Enum\IssueSource;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AuditService
{
    public function __construct(
        private PlaywrightService $playwrightService,
        private GeminiService $geminiService,
        private GeminiImageAnalysisService $geminiImageAnalysisService,
        private GeminiContextualAnalysisService $geminiContextualAnalysisService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private string $projectDir
    ) {
    }

    /**
     * Run a complete accessibility audit
     *
     * @param string $url URL to audit
     * @param User $user User running the audit
     * @param array $imageAnalysisTypes Array of ImageAnalysisType constants to perform
     * @param array $contextualAnalysisTypes Array of ContextualAnalysisType constants to perform
     */
    public function runCompleteAudit(string $url, User $user, array $imageAnalysisTypes = [], array $contextualAnalysisTypes = []): Audit
    {
        // Create audit entity
        $audit = new Audit();
        $audit->setUrl($url);
        $audit->setUser($user);
        $audit->setStatus(AuditStatus::RUNNING);

        $this->entityManager->persist($audit);
        $this->entityManager->flush();

        try {
            // Step 1: Run Playwright audit (includes Axe-core + A11yLint RGAA)
            $this->logger->info('Starting Playwright audit with Axe-core and A11yLint RGAA', ['url' => $url]);
            $playwrightResults = $this->playwrightService->runAudit($url);
            $this->logger->info('Playwright audit completed', ['url' => $url]);

            // Step 2: Store all raw results
            $this->logger->info('Storing raw audit results', ['url' => $url]);
            $this->storeRawResults($audit, $playwrightResults);

            // Step 3: Analyze with Gemini for enrichment (recommendations, impact, etc.)
            // IMPORTANT: If Gemini fails, the audit MUST fail (per user requirement)
            $this->logger->info('Starting Gemini analysis with recommendations', ['url' => $url]);

            $geminiAnalysis = $this->geminiService->analyzeResults($playwrightResults, [], $url);

            // Step 4: Update results with Gemini recommendations and summary
            $this->enrichResultsWithGemini($audit, $geminiAnalysis);

            $this->logger->info('Gemini analysis completed successfully');

            // Step 5: Deep image analysis (if enabled)
            // Note: Form labels (RGAA 11.1) are now tested automatically by Playwright
            if (!empty($imageAnalysisTypes) && isset($playwrightResults['individualImages']) && !empty($playwrightResults['individualImages'])) {
                $this->logger->info('Starting deep image analysis', [
                    'url' => $url,
                    'image_count' => count($playwrightResults['individualImages']),
                    'analysis_types' => $imageAnalysisTypes
                ]);

                try {
                    $imageAnalysisResults = $this->geminiImageAnalysisService->analyzeImages(
                        $playwrightResults['individualImages'],
                        $imageAnalysisTypes
                    );

                    // Store image analysis results
                    $this->storeImageAnalysisResults($audit, $imageAnalysisResults);

                    $this->logger->info('Deep image analysis completed', [
                        'results_count' => count($imageAnalysisResults)
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error('Deep image analysis failed', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Step 6: Contextual analysis (hybrid Playwright + AI)
            if (!empty($contextualAnalysisTypes)) {
                if (!isset($playwrightResults['contextualElements'])) {
                    $this->logger->warning('⚠️ Contextual elements not found in Playwright results', [
                        'available_keys' => array_keys($playwrightResults)
                    ]);
                } elseif (empty($playwrightResults['contextualElements'])) {
                    $this->logger->info('No contextual elements to analyze (empty)');
                } else {
                    $this->logger->info('Starting hybrid contextual analysis', [
                        'url' => $url,
                        'analysis_types' => $contextualAnalysisTypes,
                        'elements' => [
                            'contrast' => count($playwrightResults['contextualElements']['lowContrastElements'] ?? []),
                            'headings' => count($playwrightResults['contextualElements']['headingsWithContext'] ?? []),
                            'links' => count($playwrightResults['contextualElements']['linksWithSurroundings'] ?? []),
                            'tables' => count($playwrightResults['contextualElements']['complexTables'] ?? [])
                        ]
                    ]);

                    try {
                        $contextualResults = $this->geminiContextualAnalysisService->analyzeContext(
                            $playwrightResults['contextualElements'],
                            $contextualAnalysisTypes
                        );

                        // Store contextual analysis results
                        $this->storeContextualAnalysisResults($audit, $contextualResults);

                        $this->logger->info('Hybrid contextual analysis completed', [
                            'results_count' => array_sum(array_map('count', $contextualResults))
                        ]);
                    } catch (\Exception $e) {
                        $this->logger->error('Contextual analysis failed', [
                            'error' => $e->getMessage(),
                            'trace' => substr($e->getTraceAsString(), 0, 500)
                        ]);
                        // Don't re-throw - let audit continue without contextual analysis
                    }
                }
            }

            // Step 7: Calculate conformity rate based on RGAA 4.1 official formula
            $this->logger->info('Calculating conformity rate');
            $this->calculateConformityRate($audit);

            // Update audit status
            $audit->setStatus(AuditStatus::COMPLETED);
            $audit->setUpdatedAt(new \DateTimeImmutable());

            // If audit is part of a campaign, recalculate campaign statistics
            if ($audit->getCampaign()) {
                $this->logger->info('Recalculating campaign statistics');
                $audit->getCampaign()->recalculateStatistics();
            }

            $this->entityManager->flush();

            $this->logger->info('Audit completed successfully', [
                'audit_id' => $audit->getId(),
                'url' => $url,
                'image_analysis_types' => $imageAnalysisTypes,
                'image_analysis_enabled' => !empty($imageAnalysisTypes)
            ]);

            return $audit;

        } catch (\Exception $e) {
            $this->logger->error('Audit failed', [
                'audit_id' => $audit->getId(),
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Only update audit status if EntityManager is still open
            if ($this->entityManager->isOpen()) {
                $audit->setStatus(AuditStatus::FAILED);
                $audit->setErrorMessage($e->getMessage());
                $audit->setUpdatedAt(new \DateTimeImmutable());

                try {
                    $this->entityManager->flush();
                } catch (\Exception $flushException) {
                    $this->logger->error('Failed to flush audit status update', [
                        'error' => $flushException->getMessage()
                    ]);
                }
            } else {
                $this->logger->warning('EntityManager is closed, cannot update audit status to FAILED');
            }

            throw $e;
        }
    }

    /**
     * Store all raw results from Playwright (including Axe-core and A11yLint RGAA)
     */
    private function storeRawResults(Audit $audit, array $playwrightResults): void
    {
        $criticalCount = 0;
        $majorCount = 0;
        $minorCount = 0;
        $playwrightCount = 0;

        $this->logger->info('Storing raw results - DEBUG', [
            'playwright_keys' => array_keys($playwrightResults),
            'has_playwright_tests' => isset($playwrightResults['tests']),
            'tests_count' => isset($playwrightResults['tests']) ? count($playwrightResults['tests']) : 0
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

        $this->logger->info('Raw results stored - COUNTS', [
            'playwright_issues' => $playwrightCount,
            'total_issues' => $playwrightCount,
            'critical' => $criticalCount,
            'major' => $majorCount,
            'minor' => $minorCount
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
            $summary = is_array($analysis['summary'])
                ? json_encode($analysis['summary'])
                : $analysis['summary'];
            $audit->setSummary($summary);
        }

        // NOTE: conformityRate and statistics are now calculated in PHP (calculateConformityRate method)
        // instead of being generated by Gemini, to ensure accuracy and compliance with RGAA 4.1 formula

        // Enrich ALL stored results with Gemini recommendations
        if (isset($analysis['results']) && is_array($analysis['results'])) {
            $storedResults = $audit->getAuditResults()->toArray();
            $enrichedCount = 0;

            $this->logger->info('Enriching results - DEBUG', [
                'gemini_recommendations' => count($analysis['results']),
                'stored_results' => count($storedResults),
                'all_gemini_results' => $analysis['results'] // Log ALL results to see what Gemini sends
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
                            // Improve recommendation if it's too generic
                            $recommendation = $this->improveRecommendation(
                                $geminiResult['recommendation'],
                                $geminiType,
                                $storedResult
                            );
                            $storedResult->setRecommendation($recommendation);
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

                // If no match found, this is a NEW visual error detected by Gemini Vision
                if ($matchCount === 0) {
                    // Log what Gemini returned for debugging
                    $this->logger->info('Gemini returned unmatched result', [
                        'errorType' => $geminiType,
                        'source' => $geminiSource,
                        'has_description' => isset($geminiResult['description']),
                        'full_result' => $geminiResult
                    ]);

                    // If source is 'gemini-vision', create a new AuditResult automatically
                    // No need for whitelist - Gemini can detect any visual accessibility issue
                    if ($geminiSource === 'gemini-vision') {
                        $newResult = new AuditResult();
                        $newResult->setAudit($audit);
                        $newResult->setErrorType($geminiType);
                        $newResult->setSeverity($geminiResult['severity'] ?? 'major');
                        $newResult->setDescription($geminiResult['description'] ?? '');
                        $newResult->setRecommendation($geminiResult['recommendation'] ?? null);
                        $newResult->setCodeFix($geminiResult['codeFix'] ?? null);
                        $newResult->setImpactUser($geminiResult['impactUser'] ?? null);
                        $newResult->setSource('gemini-vision');

                        if (isset($geminiResult['wcagCriteria'])) {
                            $wcagValue = is_array($geminiResult['wcagCriteria'])
                                ? implode(', ', $geminiResult['wcagCriteria'])
                                : $geminiResult['wcagCriteria'];
                            $newResult->setWcagCriteria($wcagValue);
                        }

                        if (isset($geminiResult['rgaaCriteria'])) {
                            $rgaaValue = is_array($geminiResult['rgaaCriteria'])
                                ? implode(', ', $geminiResult['rgaaCriteria'])
                                : $geminiResult['rgaaCriteria'];
                            $newResult->setRgaaCriteria($rgaaValue);
                        }

                        $audit->addAuditResult($newResult);
                        $this->entityManager->persist($newResult);

                        // Update counts
                        match($newResult->getSeverity()) {
                            IssueSeverity::CRITICAL => $audit->setCriticalCount($audit->getCriticalCount() + 1),
                            IssueSeverity::MAJOR => $audit->setMajorCount($audit->getMajorCount() + 1),
                            IssueSeverity::MINOR => $audit->setMinorCount($audit->getMinorCount() + 1),
                            default => null
                        };
                        $audit->setTotalIssues($audit->getTotalIssues() + 1);

                        $this->logger->info('Created new visual error from Gemini Vision', [
                            'type' => $geminiType,
                            'severity' => $newResult->getSeverity()
                        ]);

                        $enrichedCount++;
                    } else {
                        $this->logger->warning('No match found for recommendation', [
                            'type' => $geminiType,
                            'source' => $geminiSource
                        ]);
                    }
                } else {
                    $this->logger->info('Applied recommendation', [
                        'type' => $geminiType,
                        'source' => $geminiSource,
                        'enriched_count' => $matchCount
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

    /**
     * Store image analysis results as AuditResults
     */
    private function storeImageAnalysisResults(Audit $audit, array $imageAnalysisResults): void
    {
        $issuesFound = 0;

        // Results are now grouped by analysis type
        foreach ($imageAnalysisResults as $analysisType => $results) {
            foreach ($results as $result) {
                // Only create AuditResult if there's an issue
                if (isset($result['hasIssue']) && $result['hasIssue'] === true) {
                    $auditResult = new AuditResult();
                    $auditResult->setAudit($audit);

                    // Error type based on analysis type and issue
                    $errorType = $this->getErrorTypeFromAnalysis($analysisType, $result);
                    $auditResult->setErrorType($errorType);

                    // Build description with image context
                    $imgSrc = basename($result['src']);
                    $description = "Image '{$imgSrc}' : ";
                    $description .= $result['issue'] ?? 'Problème détecté';

                    $auditResult->setDescription($description);

                    // Add selector for the image (build from src)
                    $imgFilename = basename($result['src'] ?? '');
                    if ($imgFilename) {
                        $auditResult->setSelector("img[src*=\"{$imgFilename}\"]");
                    }

                    // Add context with image details
                    $contextInfo = sprintf(
                        "Image %dx%d - Alt: \"%s\"",
                        $result['width'] ?? 0,
                        $result['height'] ?? 0,
                        $result['alt'] ?? '(vide)'
                    );
                    $auditResult->setContext($contextInfo);

                    // Add suggestion as recommendation
                    if (!empty($result['suggestion'])) {
                        $auditResult->setRecommendation($result['suggestion']);
                    } else {
                        $auditResult->setRecommendation($this->getDefaultRecommendation($analysisType));
                    }

                    // Set severity based on confidence
                    $confidence = $result['confidence'] ?? 0.5;
                    if ($confidence >= 0.8) {
                        $auditResult->setSeverity('critical');
                    } elseif ($confidence >= 0.5) {
                        $auditResult->setSeverity('major');
                    } else {
                        $auditResult->setSeverity('minor');
                    }

                    // Set RGAA/WCAG criteria based on analysis type
                    $auditResult->setRgaaCriteria($this->getRgaaCriteriaForAnalysis($analysisType));
                    $auditResult->setWcagCriteria($this->getWcagCriteriaForAnalysis($analysisType));

                    // Mark source as deep image analysis
                    $auditResult->setSource('gemini-image-analysis');

                    // Impact user
                    $auditResult->setImpactUser($this->getImpactUserForAnalysis($analysisType));

                    // Code fix suggestion
                    $currentAlt = $result['alt'] ?? '';
                    $suggestedAlt = $result['suggestion'] ?? '';
                    if ($currentAlt || $suggestedAlt) {
                        $auditResult->setCodeFix(
                            "Actuel : alt=\"{$currentAlt}\"\nSuggestion : alt=\"{$suggestedAlt}\""
                        );
                    }

                    $this->entityManager->persist($auditResult);
                    $issuesFound++;
                }
            }
        }

        if ($issuesFound > 0) {
            $this->entityManager->flush();
            $this->logger->info("Stored {$issuesFound} image issues from deep analysis across " . count($imageAnalysisResults) . " analysis types");
        }
    }

    /**
     * Store contextual analysis results (hybrid Playwright + AI)
     */
    private function storeContextualAnalysisResults(Audit $audit, array $contextualResults): void
    {
        $issuesFound = 0;

        // Results are grouped by analysis type
        foreach ($contextualResults as $analysisType => $results) {
            foreach ($results as $result) {
                // Only create AuditResult if there's an issue
                if (isset($result['hasIssue']) && $result['hasIssue'] === true) {
                    $auditResult = new AuditResult();
                    $auditResult->setAudit($audit);

                    // Error type based on analysis type
                    $errorType = $this->getErrorTypeFromContextualAnalysis($analysisType);
                    $auditResult->setErrorType($errorType);

                    // Build description with element context
                    $description = $this->getContextualDescription($analysisType, $result);
                    $auditResult->setDescription($description);

                    // Add suggestion as recommendation
                    if (!empty($result['suggestion'])) {
                        $auditResult->setRecommendation($result['suggestion']);
                    }

                    // Add issue detail
                    if (!empty($result['issue'])) {
                        $auditResult->setContext($result['issue']);
                    }

                    // Set severity based on confidence
                    $confidence = $result['confidence'] ?? 0.5;
                    if ($confidence >= 0.8) {
                        $auditResult->setSeverity('critical');
                    } elseif ($confidence >= 0.5) {
                        $auditResult->setSeverity('major');
                    } else {
                        $auditResult->setSeverity('minor');
                    }

                    // Set RGAA/WCAG criteria based on analysis type
                    $auditResult->setRgaaCriteria($this->getRgaaCriteriaForContextual($analysisType));
                    $auditResult->setWcagCriteria($this->getWcagCriteriaForContextual($analysisType));

                    // Mark source as hybrid analysis (IA + code)
                    $auditResult->setSource('ia_context');

                    // Impact user
                    $auditResult->setImpactUser($this->getImpactUserForContextual($analysisType));

                    // Selector if available
                    if (isset($result['element']['selector'])) {
                        $auditResult->setSelector($result['element']['selector']);
                    }

                    $this->entityManager->persist($auditResult);
                    $issuesFound++;
                }
            }
        }

        if ($issuesFound > 0) {
            $this->entityManager->flush();
            $this->logger->info("Stored {$issuesFound} contextual issues from hybrid analysis across " . count($contextualResults) . " analysis types");
        }
    }

    /**
     * Get error type from contextual analysis type
     */
    private function getErrorTypeFromContextualAnalysis(string $analysisType): string
    {
        return match($analysisType) {
            'contrast-context' => 'Contraste insuffisant (contexte complexe)',
            'heading-relevance' => 'Titre non pertinent',
            'link-context' => 'Lien ambigu hors contexte',
            'table-headers' => 'En-têtes de tableau non descriptifs',
            default => 'Problème contextuel détecté'
        };
    }

    /**
     * Get description for contextual result
     */
    private function getContextualDescription(string $analysisType, array $result): string
    {
        $element = $result['element'] ?? [];

        return match($analysisType) {
            'contrast-context' => sprintf(
                "Contraste visuel insuffisant : \"%s\" (ratio détecté: %s)",
                substr($element['text'] ?? 'Élément', 0, 80),
                $element['contrast'] ?? 'N/A'
            ),
            'heading-relevance' => sprintf(
                "Titre <%s> non pertinent : \"%s\"",
                $element['level'] ?? 'h?',
                substr($element['text'] ?? 'Titre', 0, 80)
            ),
            'link-context' => sprintf(
                "Lien ambigu : \"%s\" → %s",
                substr($element['text'] ?? 'Lien', 0, 50),
                substr($element['href'] ?? '', 0, 50)
            ),
            'table-headers' => sprintf(
                "En-têtes de tableau non descriptifs : %s",
                implode(', ', array_slice($element['headers'] ?? [], 0, 5))
            ),
            default => $result['issue'] ?? 'Problème contextuel détecté'
        };
    }

    /**
     * Get RGAA criteria for contextual analysis
     */
    private function getRgaaCriteriaForContextual(string $analysisType): string
    {
        return match($analysisType) {
            'contrast-context' => '3.2',
            'heading-relevance' => '6.1, 9.1',
            'link-context' => '6.2',
            'table-headers' => '5.7',
            default => ''
        };
    }

    /**
     * Get WCAG criteria for contextual analysis
     */
    private function getWcagCriteriaForContextual(string $analysisType): string
    {
        return match($analysisType) {
            'contrast-context' => '1.4.3',
            'heading-relevance' => '2.4.6, 1.3.1',
            'link-context' => '2.4.4',
            'table-headers' => '1.3.1',
            default => ''
        };
    }

    /**
     * Get impact user for contextual analysis
     */
    private function getImpactUserForContextual(string $analysisType): string
    {
        return match($analysisType) {
            'contrast-context' => 'Déficients visuels, malvoyants',
            'heading-relevance' => 'Lecteurs d\'écran, navigation clavier',
            'link-context' => 'Lecteurs d\'écran, navigation clavier',
            'table-headers' => 'Lecteurs d\'écran',
            default => 'Tous utilisateurs'
        };
    }

    /**
     * Get error type from analysis type and result
     */
    private function getErrorTypeFromAnalysis(string $analysisType, array $result): string
    {
        return match($analysisType) {
            'alt-relevance' => $this->getAltRelevanceErrorType($result),
            'decorative-detection' => 'image-decorative-incorrect',
            'text-in-image' => 'text-in-image',
            'text-contrast' => 'text-contrast-insufficient',
            'color-only-info' => 'color-only-information',
            default => 'image-accessibility-issue'
        };
    }

    /**
     * Get specific error type for alt relevance issues
     */
    private function getAltRelevanceErrorType(array $result): string
    {
        $alt = $result['alt'] ?? '';

        if (empty($alt)) {
            return 'image-alt-missing';
        }

        if (in_array(strtolower($alt), ['image', 'photo', 'img', 'picture'])) {
            return 'image-alt-generic';
        }

        return 'image-alt-not-relevant';
    }

    /**
     * Get default recommendation for analysis type
     */
    private function getDefaultRecommendation(string $analysisType): string
    {
        return match($analysisType) {
            'alt-relevance' => "Améliorer le texte alternatif pour décrire précisément le contenu de l'image.",
            'decorative-detection' => "Si l'image est décorative, ajouter alt=\"\" ou role=\"presentation\". Si elle est informative, ajouter un alt descriptif.",
            'text-in-image' => "Remplacer le texte dans l'image par du vrai texte HTML pour améliorer l'accessibilité.",
            'text-contrast' => "Augmenter le contraste entre le texte et l'arrière-plan (minimum 4.5:1 pour texte normal, 3:1 pour texte large).",
            'color-only-info' => "Ajouter un autre indicateur visuel (icône, forme, texte) en plus de la couleur pour transmettre l'information.",
            default => "Corriger le problème d'accessibilité détecté."
        };
    }

    /**
     * Get RGAA criteria for analysis type
     */
    private function getRgaaCriteriaForAnalysis(string $analysisType): string
    {
        return match($analysisType) {
            'alt-relevance' => '1.3',
            'decorative-detection' => '1.2',
            'text-in-image' => '8.9',
            'text-contrast' => '3.2',
            'color-only-info' => '3.3',
            default => 'N/A'
        };
    }

    /**
     * Get WCAG criteria for analysis type
     */
    private function getWcagCriteriaForAnalysis(string $analysisType): string
    {
        return match($analysisType) {
            'alt-relevance' => '1.1.1',
            'decorative-detection' => '1.1.1',
            'text-in-image' => '1.4.5',
            'text-contrast' => '1.4.3',
            'color-only-info' => '1.4.1',
            default => 'N/A'
        };
    }

    /**
     * Get user impact message for analysis type
     */
    private function getImpactUserForAnalysis(string $analysisType): string
    {
        return match($analysisType) {
            'alt-relevance' => "Les utilisateurs de lecteurs d'écran ne peuvent pas comprendre le contenu de cette image.",
            'decorative-detection' => "Les utilisateurs de lecteurs d'écran entendent du contenu non pertinent ou manquent des informations importantes.",
            'text-in-image' => "Le texte dans l'image ne peut pas être agrandi, lu par un lecteur d'écran, ou traduit automatiquement.",
            'text-contrast' => "Les utilisateurs malvoyants ou daltoniens ont des difficultés à lire le texte dans l'image.",
            'color-only-info' => "Les utilisateurs daltoniens ou utilisant un lecteur d'écran ne peuvent pas percevoir l'information.",
            default => "Impact sur l'accessibilité pour les utilisateurs en situation de handicap."
        };
    }

    /**
     * Improve recommendation if it's too generic
     *
     * @param string $geminiRecommendation Original recommendation from Gemini
     * @param string $errorType Type of error
     * @param AuditResult $result The audit result for context
     * @return string Improved recommendation
     */
    private function improveRecommendation(string $geminiRecommendation, string $errorType, AuditResult $result): string
    {
        // List of generic phrases that indicate a bad recommendation
        $genericPhrases = [
            'Vérifier le code',
            'vérifier le code',
            'Appliquer les corrections',
            'appliquer les corrections',
            'Corriger l\'accessibilité',
            'corriger l\'accessibilité',
            'Mettre à jour le code',
            'mettre à jour le code',
            'respecter les normes',
            'conformément aux',
            'selon les règles',
        ];

        // Check if recommendation is too generic
        $isGeneric = false;
        foreach ($genericPhrases as $phrase) {
            if (stripos($geminiRecommendation, $phrase) !== false) {
                $isGeneric = true;
                break;
            }
        }

        // If recommendation is good, return it as-is
        if (!$isGeneric) {
            return $geminiRecommendation;
        }

        // Generate a better recommendation based on error type
        $this->logger->warning('Generic recommendation detected, generating fallback', [
            'errorType' => $errorType,
            'original' => $geminiRecommendation
        ]);

        return $this->getSpecificRecommendation($errorType, $result);
    }

    /**
     * Get specific recommendation based on error type
     *
     * @param string $errorType Type of error
     * @param AuditResult $result The audit result for additional context
     * @return string Specific, actionable recommendation
     */
    private function getSpecificRecommendation(string $errorType, AuditResult $result): string
    {
        // Normalize error type for matching
        $normalizedType = strtolower($errorType);

        // Image-related errors
        if (stripos($normalizedType, 'alt') !== false || stripos($normalizedType, 'image') !== false) {
            return 'Ajouter un attribut alt="" descriptif sur chaque balise <img>. Si l\'image est purement décorative, utiliser alt="" ou role="presentation".';
        }

        // Contrast errors
        if (stripos($normalizedType, 'contrast') !== false || stripos($normalizedType, 'contraste') !== false) {
            return 'Augmenter le contraste entre le texte et son arrière-plan pour atteindre un ratio d\'au moins 4.5:1 pour le texte normal, ou 3:1 pour le texte de grande taille (18pt+ ou 14pt+ gras).';
        }

        // Link errors
        if (stripos($normalizedType, 'link') !== false || stripos($normalizedType, 'lien') !== false) {
            return 'Remplacer les textes de liens vagues comme "Cliquez ici" ou "En savoir plus" par des textes explicites décrivant la destination du lien (ex: "Télécharger le rapport PDF" ou "Voir notre politique de confidentialité").';
        }

        // Button errors
        if (stripos($normalizedType, 'button') !== false || stripos($normalizedType, 'bouton') !== false) {
            return 'Utiliser une balise <button> sémantique au lieu de <div> ou <span> stylisés en boutons. Ajouter un attribut type="button" ou type="submit" selon le cas.';
        }

        // Heading/title errors
        if (stripos($normalizedType, 'heading') !== false || stripos($normalizedType, 'titre') !== false) {
            return 'Respecter la hiérarchie des titres : <h1> pour le titre principal, puis <h2>, <h3>, etc. Ne pas sauter de niveaux (par exemple, ne pas passer de <h1> à <h3> directement).';
        }

        // Label errors
        if (stripos($normalizedType, 'label') !== false || stripos($normalizedType, 'étiquette') !== false) {
            return 'Associer chaque champ de formulaire à un <label> explicite en utilisant l\'attribut for="" ou en englobant le champ dans le <label>. Ne pas se fier uniquement aux attributs placeholder.';
        }

        // ARIA errors
        if (stripos($normalizedType, 'aria') !== false) {
            return 'Vérifier l\'utilisation correcte des attributs ARIA. Ajouter aria-label ou aria-labelledby pour les éléments interactifs sans texte visible, et utiliser les rôles ARIA appropriés (role="navigation", role="main", etc.).';
        }

        // Keyboard navigation errors
        if (stripos($normalizedType, 'keyboard') !== false || stripos($normalizedType, 'clavier') !== false || stripos($normalizedType, 'focus') !== false) {
            return 'S\'assurer que tous les éléments interactifs sont accessibles au clavier (tabindex="0" si nécessaire) et qu\'ils affichent un indicateur de focus visible (:focus et :focus-visible en CSS).';
        }

        // Color errors
        if (stripos($normalizedType, 'color') !== false || stripos($normalizedType, 'couleur') !== false) {
            return 'Ne pas transmettre d\'information uniquement par la couleur. Ajouter des icônes, du texte, ou des motifs pour compléter l\'information véhiculée par les couleurs.';
        }

        // Language errors
        if (stripos($normalizedType, 'lang') !== false || stripos($normalizedType, 'langue') !== false) {
            return 'Définir la langue du document avec l\'attribut lang sur la balise <html> (ex: <html lang="fr">). Pour les passages dans une autre langue, utiliser lang="" sur l\'élément concerné.';
        }

        // Generic fallback (better than nothing)
        return 'Corriger l\'élément ' . ($result->getSelector() ?: 'identifié') . ' pour respecter le critère RGAA correspondant. Consulter la documentation RGAA 4.1 pour les exigences détaillées.';
    }

    // Note: storeFormAnalysisResults() removed - Form labels (RGAA 11.1) are now
    // tested automatically by Playwright with 5 checks: missing label, hidden label,
    // label too far, generic label, and placeholder-only labeling

    /**
     * Calculate conformity rate according to official RGAA 4.1 formula:
     * Rate = (Conform Criteria) / (Conform Criteria + Non-Conform Criteria) × 100
     *
     * Only auto-testable criteria are included in this calculation.
     * Manual criteria are not counted (they would need human verification).
     *
     * @param Audit $audit The audit entity
     * @return void Updates the audit's conformityRate, conformCriteria, nonConformCriteria
     */
    private function calculateConformityRate(Audit $audit): void
    {
        // Load RGAA criteria configuration
        $criteriaFile = $this->projectDir . '/config/rgaa_criteria.json';

        if (!file_exists($criteriaFile)) {
            $this->logger->error('RGAA criteria file not found', ['path' => $criteriaFile]);
            return;
        }

        $criteriaData = json_decode(file_get_contents($criteriaFile), true);
        if (!isset($criteriaData['criteria'])) {
            $this->logger->error('Invalid RGAA criteria file format');
            return;
        }

        $totalRgaaCriteria = count($criteriaData['criteria']); // 106

        // Get all audit results (errors found)
        $auditResults = $audit->getAuditResults();

        // Map error types to RGAA criteria numbers
        $errorTypeToRgaaCriteria = $this->buildErrorTypeToRgaaCriteriaMap();

        // LOGIC: The mapping defines which RGAA criteria our app tests automatically
        // Tested criteria = unique criteria from mapping (not from Gemini extras)
        // Non-conform = criteria from mapping that have detected errors
        // Conform = criteria from mapping with no errors

        // Step 1: Get the definitive list of tested criteria from mapping
        $allTestedCriteria = [];
        foreach ($errorTypeToRgaaCriteria as $errorType => $criteriaNumber) {
            $allTestedCriteria[$criteriaNumber] = true;
        }

        $nonConformCriteriaNumbers = [];

        // Step 2: Collect criteria with errors (non-conform)
        // ONLY count them if they're in our tested list
        foreach ($auditResults as $result) {
            $errorType = $result->getErrorType();
            $rgaaCriteria = $result->getRgaaCriteria();

            $criteriaNumber = null;

            // 1. Check if already set (by Gemini or previous mapping)
            if ($rgaaCriteria) {
                $parts = explode(',', $rgaaCriteria);
                $criteriaNumber = trim($parts[0]);
            }

            // 2. Check our mapping
            if (!$criteriaNumber && isset($errorTypeToRgaaCriteria[$errorType])) {
                $criteriaNumber = $errorTypeToRgaaCriteria[$errorType];
                // Update result with mapped criteria
                $result->setRgaaCriteria($criteriaNumber);
            }

            // 3. Try to extract from error type name (e.g., "RGAA 1.1" or "rgaa-1-1")
            if (!$criteriaNumber) {
                if (preg_match('/rgaa[_\s-]*(\d+)[._-](\d+)/i', $errorType, $matches)) {
                    $criteriaNumber = $matches[1] . '.' . $matches[2];
                    $result->setRgaaCriteria($criteriaNumber);
                }
            }

            // IMPORTANT: Only count as non-conform if it's in our tested criteria list
            // This excludes extra criteria that Gemini might have assigned
            if ($criteriaNumber && isset($allTestedCriteria[$criteriaNumber])) {
                $nonConformCriteriaNumbers[$criteriaNumber] = true;
            }
        }

        // Count totals based on ACTUAL tested criteria
        $totalTestedCriteria = count($allTestedCriteria);
        $nonConformCount = count($nonConformCriteriaNumbers);
        $conformCount = $totalTestedCriteria - $nonConformCount;

        // Calculate conformity rate using official RGAA formula
        // Rate = Conform / (Conform + Non-Conform) × 100
        if ($totalTestedCriteria > 0) {
            $conformityRate = ($conformCount / $totalTestedCriteria) * 100;

            $this->logger->info('Conformity rate calculated from ACTUAL tested criteria', [
                'total_tested_criteria' => $totalTestedCriteria,
                'conform_criteria' => $conformCount,
                'non_conform_criteria' => $nonConformCount,
                'conformity_rate' => round($conformityRate, 2) . '%',
                'total_rgaa_criteria' => $totalRgaaCriteria
            ]);

            // Update audit entity
            $audit->setConformityRate((string) round($conformityRate, 2));
            $audit->setConformCriteria($conformCount);
            $audit->setNonConformCriteria($nonConformCount);

            // Not applicable = total RGAA criteria - actually tested
            $audit->setNotApplicableCriteria($totalRgaaCriteria - $totalTestedCriteria);
        } else {
            $this->logger->warning('No tested criteria found for conformity rate calculation');
        }
    }

    /**
     * Build a mapping between error types and RGAA criteria numbers
     * Loads the mapping from an external JSON configuration file
     *
     * @return array Map of errorType => rgaaCriteriaNumber
     */
    private function buildErrorTypeToRgaaCriteriaMap(): array
    {
        $mappingFile = $this->projectDir . '/config/error_to_rgaa_mapping.json';

        if (!file_exists($mappingFile)) {
            $this->logger->error('RGAA error mapping file not found', ['path' => $mappingFile]);
            return [];
        }

        $mappingData = json_decode(file_get_contents($mappingFile), true);

        if (!isset($mappingData['mappings'])) {
            $this->logger->error('Invalid RGAA error mapping file format');
            return [];
        }

        // Filter out comment keys (starting with _)
        $mappings = array_filter($mappingData['mappings'], function($key) {
            return strpos($key, '_') !== 0;
        }, ARRAY_FILTER_USE_KEY);

        $this->logger->debug('Loaded RGAA error mapping', [
            'total_mappings' => count($mappings)
        ]);

        return $mappings;
    }
}
