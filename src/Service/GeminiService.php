<?php

namespace App\Service;

use App\Entity\VisualErrorCriteria;
use App\Enum\IssueSource;
use App\Repository\VisualErrorCriteriaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class GeminiService
{
    private ?array $criteriaCache = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $geminiApiKey,
        private string $geminiApiUrl,
        private LoggerInterface $logger,
        private VisualErrorCriteriaRepository $visualErrorCriteriaRepository,
        private EntityManagerInterface $entityManager,
        private AiAnalysisCacheService $aiCache
    ) {
    }

    /**
     * Analyze audit results with Gemini AI for enrichment
     * (recommendations, impact user, code fixes, etc.)
     *
     * @param array $playwrightResults Playwright test results
     * @param array $pa11yResults Pa11y test results
     * @param string $url URL being audited
     */
    public function analyzeResults(array $playwrightResults, array $pa11yResults, string $url): array
    {
        $prompt = $this->buildAnalysisPrompt($playwrightResults, $pa11yResults, $url);

        // Log detailed request info BEFORE making the call
        $promptKB = round(strlen($prompt) / 1024, 2);

        $this->logger->info("📦 GEMINI PAYLOAD: URL={$url}, Prompt={$promptKB}KB");

        try {
            // Add API key to URL
            $urlWithKey = $this->geminiApiUrl . '?key=' . $this->geminiApiKey;

            // Build parts array for the request
            $parts = [['text' => $prompt]];

            $this->logger->info('Sending request to Gemini API...');

            $startTime = microtime(true);

            // Retry logic for 503/429 errors (quota/overload)
            $maxRetries = 5; // Increased from 3 to 5 retries
            $attempt = 0;
            $data = null;
            $lastException = null;

            while ($attempt < $maxRetries) {
                try {
                    if ($attempt > 0) {
                        $waitSeconds = min(pow(2, $attempt), 30); // Exponential backoff: 2s, 4s, 8s, 16s, 30s (max 30s)
                        $this->logger->warning("⏳ Gemini API retry attempt {$attempt}/{$maxRetries} after {$waitSeconds}s wait");
                        sleep($waitSeconds);
                    }

                    $this->logger->info("📤 Sending Gemini API request (attempt " . ($attempt + 1) . "/{$maxRetries})...");

                    $response = $this->httpClient->request('POST', $urlWithKey, [
                        'headers' => [
                            'Content-Type' => 'application/json',
                        ],
                        'timeout' => 300, // 5 minutes timeout (Gemini peut être très lent avec vision)
                        'json' => [
                            'contents' => [
                                [
                                    'parts' => $parts
                                ]
                            ],
                            'generationConfig' => [
                                'temperature' => 0.1, // Réduit pour plus de cohérence entre audits
                                'topK' => 40,
                                'topP' => 0.95,
                                'maxOutputTokens' => 65536, // Augmenté pour Gemini 2.5 (thoughts + output)
                                'responseMimeType' => 'application/json',
                            ]
                        ],
                    ]);

                    $this->logger->info('Gemini API request sent, waiting for response...');

                    $data = $response->toArray();
                    $this->logger->info("✅ Gemini API responded successfully on attempt " . ($attempt + 1));
                    break; // Success - exit retry loop

                } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface $e) {
                    $lastException = $e;
                    $statusCode = $e->getResponse()->getStatusCode();

                    // Retry on 503 (Service Unavailable) or 429 (Too Many Requests)
                    if (in_array($statusCode, [503, 429])) {
                        $attempt++;
                        $this->logger->warning("⚠️ GEMINI ERROR {$statusCode}: Attempt {$attempt}/{$maxRetries} - " . $e->getMessage());

                        if ($attempt >= $maxRetries) {
                            $this->logger->error("❌ GEMINI FAILED PERMANENTLY after {$maxRetries} retries - HTTP {$statusCode}");
                            throw $e; // Give up
                        }
                        // Continue to next retry iteration
                    } else {
                        // Other errors - don't retry
                        $this->logger->error("❌ GEMINI ERROR {$statusCode} (not retryable) - " . $e->getMessage());
                        throw $e;
                    }
                } catch (\Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface $e) {
                    // Server errors (5xx) - retry with backoff
                    $lastException = $e;
                    $statusCode = $e->getResponse()->getStatusCode();
                    $attempt++;
                    $this->logger->warning("⚠️ GEMINI SERVER ERROR {$statusCode}: Attempt {$attempt}/{$maxRetries} - " . $e->getMessage());

                    if ($attempt >= $maxRetries) {
                        $this->logger->error("❌ GEMINI FAILED PERMANENTLY after {$maxRetries} retries - HTTP {$statusCode}");
                        throw $e; // Give up
                    }
                    // Continue to next retry iteration
                } catch (\Exception $e) {
                    $this->logger->error("❌ GEMINI UNEXPECTED ERROR: " . get_class($e) . " - " . $e->getMessage());
                    throw $e; // Other exceptions - don't retry
                }
            }

            if ($data === null && $lastException !== null) {
                throw $lastException;
            }

            $duration = round(microtime(true) - $startTime, 2);

            $this->logger->info('Gemini API response received', [
                'duration_seconds' => $duration,
                'response_size_bytes' => strlen(json_encode($data)),
                'response_size_kb' => round(strlen(json_encode($data)) / 1024, 2)
            ]);

            $this->logger->info('Gemini analysis response', [
                'has_candidates' => isset($data['candidates']),
                'candidates_count' => isset($data['candidates']) ? count($data['candidates']) : 0,
                'response_keys' => array_keys($data)
            ]);

            // Log full response in debug mode only
            $this->logger->debug('Gemini full response', [
                'response' => $data
            ]);

            if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                $this->logger->error('Invalid Gemini response format', [
                    'response' => json_encode($data)
                ]);
                throw new \RuntimeException('Invalid response format from Gemini API');
            }

            $analysisText = $data['candidates'][0]['content']['parts'][0]['text'];

            // Log raw response in debug mode
            $this->logger->debug('Gemini raw analysis text', [
                'text' => $analysisText
            ]);

            $parsed = $this->parseGeminiResponse($analysisText);

            // Log parsed response in debug mode
            $this->logger->debug('Gemini parsed response', [
                'parsed' => $parsed
            ]);

            $totalResults = isset($parsed['results']) ? count($parsed['results']) : 0;
            $this->logger->info("✅ GEMINI SUCCESS: {$totalResults} enriched results, response={$duration}s, URL={$url}");

            // Store enriched results in cache for future audits
            if (isset($parsed['results']) && is_array($parsed['results'])) {
                foreach ($parsed['results'] as $result) {
                    $fingerprint = $this->aiCache->generateFingerprint(
                        $result['errorType'] ?? 'unknown',
                        $result
                    );
                    $this->aiCache->set($fingerprint, $result);
                }
            }

            // Log cache statistics
            $this->aiCache->logStats();

            return $parsed;

        } catch (\Exception $e) {
            $duration = isset($startTime) ? round(microtime(true) - $startTime, 2) : 0;

            $this->logger->error('Gemini analysis failed', [
                'url' => $url,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'duration_before_failure' => $duration,
                'prompt_length_kb' => round(strlen($prompt) / 1024, 2),
                'timeout_setting' => 300
            ]);

            throw new \RuntimeException('Gemini analysis failed: ' . $e->getMessage());
        }
    }

    /**
     * Build analysis prompt for Gemini (technical errors enrichment only)
     *
     * @param array $playwrightResults Playwright test results
     * @param array $pa11yResults Pa11y test results
     * @param string $url URL being audited
     */
    private function buildAnalysisPrompt(array $playwrightResults, array $pa11yResults, string $url): string
    {
        // Extraire toutes les erreurs uniques par type
        $issuesByType = [];
        $cachedIssues = []; // Track issues found in cache

        // Extraire Playwright issues groupées par type
        if (isset($playwrightResults['tests']) && is_array($playwrightResults['tests'])) {
            foreach ($playwrightResults['tests'] as $test) {
                if (isset($test['issues']) && is_array($test['issues'])) {
                    $testName = $test['name'] ?? 'Unknown';

                    // Detect actual source from test name (playwright, axe-core, or a11ylint)
                    $source = IssueSource::detectFromTestName($testName);

                    $issueData = [
                        'errorType' => $testName,
                        'source' => $source,
                        'severity' => $test['issues'][0]['severity'] ?? 'minor',
                        'selector' => $test['issues'][0]['selector'] ?? '',
                        'message' => $test['issues'][0]['message'] ?? '',
                        'html' => $test['issues'][0]['html'] ?? '',
                    ];

                    // Check cache first
                    $fingerprint = $this->aiCache->generateFingerprint($testName, $issueData);

                    if ($this->aiCache->has($fingerprint)) {
                        // Found in cache! No need to ask Gemini
                        $cachedIssues[$testName] = $this->aiCache->get($fingerprint);
                        continue; // Skip adding to prompt
                    }

                    // Not in cache, add to prompt for Gemini analysis
                    if (!isset($issuesByType[$testName])) {
                        $issuesByType[$testName] = [
                            'errorType' => $testName,
                            'source' => $source,
                            'severity' => $test['issues'][0]['severity'] ?? 'minor',
                            'count' => 0,
                            'examples' => []
                        ];
                    }
                    $issuesByType[$testName]['count'] += count($test['issues']);
                    // Prendre 2 exemples maximum par type avec HTML complet
                    foreach (array_slice($test['issues'], 0, 2) as $issue) {
                        $issuesByType[$testName]['examples'][] = [
                            'selector' => $issue['selector'] ?? '',
                            'message' => $issue['message'] ?? '',
                            'html' => $issue['html'] ?? $issue['context'] ?? '',
                            'attributes' => $issue['attributes'] ?? []
                        ];
                    }
                }
            }
        }

        // Extraire Pa11y issues groupées par code
        if (isset($pa11yResults['issues']) && is_array($pa11yResults['issues'])) {
            foreach ($pa11yResults['issues'] as $issue) {
                $code = $issue['code'] ?? 'Unknown';
                if (!isset($issuesByType[$code])) {
                    $issuesByType[$code] = [
                        'errorType' => $code,
                        'source' => 'pa11y',
                        'severity' => $this->mapPa11ySeverityForPrompt($issue['type'] ?? 'notice'),
                        'count' => 0,
                        'message' => $issue['message'] ?? '',
                        'examples' => []
                    ];
                }
                $issuesByType[$code]['count']++;
                if (count($issuesByType[$code]['examples']) < 2) {
                    $issuesByType[$code]['examples'][] = [
                        'selector' => $issue['selector'] ?? '',
                        'context' => substr($issue['context'] ?? '', 0, 100)
                    ];
                }
            }
        }

        // Technical errors enrichment only (no vision analysis)
        $prompt = "Tu es un expert en accessibilité web RGAA 4.1 / WCAG 2.1 AA.\n\n";
        $prompt .= "🎯 OBJECTIF : Analyser les erreurs d'accessibilité détectées et fournir des recommandations ACTIONNABLES.\n\n";

        $prompt .= "📄 PAGE AUDITÉE : {$url}\n\n";

        $prompt .= "🔍 ERREURS DÉTECTÉES :\n";
        $prompt .= json_encode(array_values($issuesByType), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        $prompt .= "📋 STRUCTURE DE RÉPONSE ATTENDUE :\n";
        $prompt .= "Pour CHAQUE type d'erreur, génère un objet JSON avec :\n\n";
        $prompt .= "{\n";
        $prompt .= "  \"errorType\": \"string\",\n";
        $prompt .= "  \"source\": \"playwright|axe-core|a11ylint|pa11y\",\n";
        $prompt .= "  \"severity\": \"critical|major|minor\",\n";
        $prompt .= "  \"description\": \"Description claire du problème (2-3 phrases)\",\n";
        $prompt .= "  \"impactUser\": \"Impact concret pour les utilisateurs en situation de handicap\",\n";
        $prompt .= "  \"recommendation\": \"Recommandation CONCRÈTE et ACTIONNABLE (voir règles ci-dessous)\",\n";
        $prompt .= "  \"codeFix\": {\n";
        $prompt .= "    \"before\": \"<code HTML actuel problématique>\",\n";
        $prompt .= "    \"after\": \"<code HTML corrigé avec commentaires>\",\n";
        $prompt .= "    \"explanation\": \"Explication de la correction appliquée\"\n";
        $prompt .= "  },\n";
        $prompt .= "  \"effort\": \"facile|moyen|complexe\",\n";
        $prompt .= "  \"impact\": \"bloquant|genant|mineur\",\n";
        $prompt .= "  \"quickWin\": true|false (true si effort=facile ET impact=bloquant ou genant),\n";
        $prompt .= "  \"manualTest\": \"Comment tester manuellement cette correction\",\n";
        $prompt .= "  \"wcagCriteria\": \"ex: 1.4.3, 4.1.2\",\n";
        $prompt .= "  \"rgaaCriteria\": \"ex: 3.2, 11.9\",\n";
        $prompt .= "  \"waiAriaPattern\": \"Pattern WAI-ARIA applicable si pertinent (ex: Dialog, Menu)\"\n";
        $prompt .= "}\n\n";

        // CRITICAL: Recommendations quality guidelines
        $prompt .= "⚠️ RÈGLES IMPÉRATIVES pour 'recommendation' :\n";
        $prompt .= "1. JAMAIS de recommandations génériques comme :\n";
        $prompt .= "   ❌ 'Vérifier le code HTML/CSS/JS'\n";
        $prompt .= "   ❌ 'Appliquer les corrections RGAA/WCAG'\n";
        $prompt .= "   ❌ 'Corriger l'accessibilité'\n";
        $prompt .= "   ❌ 'Mettre à jour le code'\n\n";
        $prompt .= "2. TOUJOURS donner des recommandations CONCRÈTES et ACTIONNABLES :\n";
        $prompt .= "   ✅ Inclure le sélecteur CSS ou l'élément HTML exact à modifier\n";
        $prompt .= "   ✅ Donner l'action précise à effectuer\n";
        $prompt .= "   ✅ Mentionner les attributs ARIA spécifiques si nécessaire\n";
        $prompt .= "   ✅ Utiliser le HTML fourni dans 'examples' pour être précis\n\n";
        $prompt .= "Exemples de BONNES recommandations :\n";
        $prompt .= "- 'Ajouter un attribut alt=\"Description de l'image\" sur chaque balise <img class=\"product-thumbnail\">'\n";
        $prompt .= "- 'Remplacer <div class=\"button\"> par <button type=\"button\" aria-label=\"Fermer\">'\n";
        $prompt .= "- 'Augmenter le contraste de #999 vers #555 pour atteindre un ratio de 4.5:1 sur .text-muted'\n";
        $prompt .= "- 'Ajouter aria-label=\"Menu principal\" sur la balise <nav class=\"navbar\">'\n";
        $prompt .= "- 'Remplacer le texte du lien \"Cliquez ici\" par \"Télécharger le rapport annuel PDF (2.3 Mo)\"'\n\n";

        // CodeFix guidelines
        $prompt .= "⚠️ RÈGLES IMPÉRATIVES pour 'codeFix' :\n";
        $prompt .= "1. 'before' : Code HTML RÉEL extrait de 'examples' (pas d'exemple générique)\n";
        $prompt .= "2. 'after' : Code HTML corrigé COMPLET et FONCTIONNEL\n";
        $prompt .= "3. 'after' : Ajouter des commentaires /* */ pour expliquer les changements\n";
        $prompt .= "4. Conserver les classes CSS et IDs existants\n";
        $prompt .= "5. Ne corriger QUE le problème d'accessibilité, pas le style\n\n";

        // Effort/Impact guidelines
        $prompt .= "⚠️ RÈGLES pour 'effort' et 'impact' :\n";
        $prompt .= "**effort** :\n";
        $prompt .= "- 'facile' : < 1h (ajouter un attribut, changer un texte, ajuster une couleur)\n";
        $prompt .= "- 'moyen' : 1-4h (refactoring HTML, ajout de ARIA, restructuration)\n";
        $prompt .= "- 'complexe' : > 4h (refonte composant, JavaScript complexe, architecture)\n\n";
        $prompt .= "**impact** :\n";
        $prompt .= "- 'bloquant' : Empêche l'accès à une fonctionnalité essentielle\n";
        $prompt .= "- 'genant' : Rend difficile l'utilisation mais pas impossible\n";
        $prompt .= "- 'mineur' : Inconfort ou non-conformité sans impact majeur\n\n";
        $prompt .= "**quickWin** : true si (effort='facile' ET impact IN ['bloquant','genant'])\n\n";

        // Manual test guidelines
        $prompt .= "⚠️ RÈGLES pour 'manualTest' :\n";
        $prompt .= "Donner des instructions CONCRÈTES pour tester :\n";
        $prompt .= "- Outil à utiliser (NVDA, JAWS, VoiceOver, inspecteur navigateur)\n";
        $prompt .= "- Actions clavier à effectuer (Tab, Entrée, Espace, Échap)\n";
        $prompt .= "- Résultat attendu\n";
        $prompt .= "Exemples :\n";
        $prompt .= "- 'Avec NVDA : Tab jusqu'au bouton, vérifier que \"Fermer\" est annoncé'\n";
        $prompt .= "- 'Inspecter l'élément : vérifier que le contraste affiché est ≥ 4.5:1'\n";
        $prompt .= "- 'Naviguer au clavier uniquement : Tab doit afficher un outline visible'\n\n";

        // CRITICAL: Summary format guidelines
        $prompt .= "⚠️ RÈGLES IMPÉRATIVES pour 'summary' :\n";
        $prompt .= "Le summary doit être un texte NARRATIF et LISIBLE (pas du JSON!), structuré ainsi :\n\n";
        $prompt .= "🔍 Résumé de l'audit d'accessibilité\n\n";
        $prompt .= "**Problèmes critiques détectés :**\n";
        $prompt .= "• [Description courte du problème 1] (X occurrences)\n";
        $prompt .= "• [Description courte du problème 2] (X occurrences)\n\n";
        $prompt .= "**Problèmes majeurs :**\n";
        $prompt .= "• [Description courte] (X occurrences)\n\n";
        $prompt .= "**Problèmes mineurs :**\n";
        $prompt .= "• [Description courte] (X occurrences)\n\n";
        $prompt .= "**🎯 Quick Wins (effort facile + impact élevé) :**\n";
        $prompt .= "1. [Premier quick win]\n";
        $prompt .= "2. [Deuxième quick win]\n\n";
        $prompt .= "**📋 Priorités d'action :**\n";
        $prompt .= "1. Corriger en premier : [problème le plus bloquant]\n";
        $prompt .= "2. Ensuite : [deuxième priorité]\n";
        $prompt .= "3. Amélioration : [troisième priorité]\n\n";

        $prompt .= "📤 FORMAT DE RÉPONSE FINAL :\n";
        $prompt .= "JSON avec structure : {\"results\": [...], \"summary\": \"texte markdown\"}\n";

        return $prompt;
    }

    /**
     * Map Pa11y severity for prompt
     */
    private function mapPa11ySeverityForPrompt(string $type): string
    {
        return match($type) {
            'error' => 'critical',
            'warning' => 'major',
            'notice' => 'minor',
            default => 'minor'
        };
    }

    /**
     * Parse Gemini response
     */
    private function parseGeminiResponse(string $response): array
    {
        // Extract JSON from response (remove markdown code blocks if present)
        $response = trim($response);

        // Log raw response for debugging
        $this->logger->info('Raw Gemini response', [
            'length' => strlen($response),
            'first_200_chars' => substr($response, 0, 200),
            'last_100_chars' => substr($response, -100)
        ]);

        // Remove markdown code blocks plus agressivement
        $response = preg_replace('/^```(?:json)?\s*/m', '', $response);
        $response = preg_replace('/\s*```$/m', '', $response);

        // Nettoyer les caractères invisibles
        $response = preg_replace('/[\x00-\x1F\x7F]/u', '', $response);
        $response = trim($response);

        // Try to extract JSON if there's text before/after
        if (preg_match('/\{[\s\S]*\}/s', $response, $matches)) {
            $response = $matches[0];
            $this->logger->info('Extracted JSON from response', [
                'extracted_length' => strlen($response)
            ]);
        }

        $this->logger->info('Cleaned response', [
            'cleaned_response' => $response
        ]);

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('JSON parsing failed', [
                'error' => json_last_error_msg(),
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 200),
                'full_response' => $response
            ]);
            throw new \RuntimeException('Failed to parse Gemini response as JSON: ' . json_last_error_msg());
        }

        // Validate response structure
        if (!isset($data['results']) || !is_array($data['results'])) {
            $this->logger->error('Invalid response structure', [
                'keys' => isset($data) ? array_keys($data) : 'null',
                'data' => $data
            ]);
            throw new \RuntimeException('Invalid Gemini response structure: missing results array');
        }

        return $data;
    }

    /**
     * Generate recommendations for a specific issue
     */
    public function generateRecommendation(string $issueDescription, string $context): string
    {
        $prompt = "En tant qu'expert RGAA, fournis une recommandation détaillée pour corriger ce problème d'accessibilité:\n\n";
        $prompt .= "Problème: {$issueDescription}\n";
        $prompt .= "Contexte: {$context}\n\n";
        $prompt .= "Fournis:\n";
        $prompt .= "1. Une explication claire du problème\n";
        $prompt .= "2. L'impact sur les utilisateurs\n";
        $prompt .= "3. Les étapes précises pour corriger\n";
        $prompt .= "4. Un exemple de code corrigé\n";

        try {
            $response = $this->httpClient->request('POST', $this->geminiApiUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->geminiApiKey,
                ],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                ],
            ]);

            $data = $response->toArray();
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        } catch (\Exception $e) {
            $this->logger->error('Gemini recommendation generation failed', [
                'error' => $e->getMessage()
            ]);

            return "Impossible de générer une recommandation pour le moment.";
        }
    }

    /**
     * Add WCAG/RGAA criteria mapping to Gemini Vision results
     *
     * Auto-learning system:
     * 1. Check database for existing mapping
     * 2. If not found, use Gemini's suggestion and save it
     * 3. Increment detection count for statistics
     */
    private function addCriteriaMapping(array $result): array
    {
        // Load all mappings once per request (cache)
        if ($this->criteriaCache === null) {
            $this->criteriaCache = $this->visualErrorCriteriaRepository->getAllMappingsAsArray();
        }

        $errorType = $result['errorType'] ?? 'unknown';

        // Check if we have a mapping in database
        if (isset($this->criteriaCache[$errorType])) {
            // Use database mapping
            $result['wcagCriteria'] = $this->criteriaCache[$errorType]['wcag'];
            $result['rgaaCriteria'] = $this->criteriaCache[$errorType]['rgaa'];

            // Increment detection count
            $criteria = $this->visualErrorCriteriaRepository->findByErrorType($errorType);
            if ($criteria) {
                $criteria->incrementDetectionCount();
                $this->entityManager->persist($criteria);
                // Flush will be done by AuditService at the end
            }

            $this->logger->info('Applied database criteria mapping', [
                'errorType' => $errorType,
                'wcag' => $result['wcagCriteria'],
                'rgaa' => $result['rgaaCriteria'],
                'detection_count' => $criteria?->getDetectionCount() ?? 0
            ]);
        } else {
            // Unknown error type - check if Gemini provided criteria
            $geminiWcag = $result['wcagCriteria'] ?? null;
            $geminiRgaa = $result['rgaaCriteria'] ?? null;

            if ($geminiWcag && $geminiRgaa) {
                // Convert arrays to strings if needed
                $wcagString = is_array($geminiWcag) ? implode(', ', $geminiWcag) : $geminiWcag;
                $rgaaString = is_array($geminiRgaa) ? implode(', ', $geminiRgaa) : $geminiRgaa;

                // Gemini provided criteria - AUTO-LEARN IT!
                $criteria = new VisualErrorCriteria();
                $criteria->setErrorType($errorType);
                $criteria->setWcagCriteria($wcagString);
                $criteria->setRgaaCriteria($rgaaString);
                $criteria->setDescription($result['description'] ?? null);
                $criteria->setDetectionCount(1);
                $criteria->setAutoLearned(true);

                $this->entityManager->persist($criteria);
                // Flush will be done by AuditService at the end

                // Update cache
                $this->criteriaCache[$errorType] = [
                    'wcag' => $wcagString,
                    'rgaa' => $rgaaString
                ];

                $this->logger->info('✨ AUTO-LEARNED new error type from Gemini suggestion', [
                    'errorType' => $errorType,
                    'wcag' => $wcagString,
                    'rgaa' => $rgaaString,
                    'description' => $result['description'] ?? 'N/A',
                    'message' => 'This mapping will be used for all future occurrences'
                ]);
            } else {
                // Gemini didn't provide criteria
                $this->logger->error('New error type without criteria - cannot auto-learn', [
                    'errorType' => $errorType,
                    'description' => $result['description'] ?? 'N/A',
                    'message' => 'Gemini should provide wcagCriteria and rgaaCriteria for all errors'
                ]);
            }
        }

        return $result;
    }
}
