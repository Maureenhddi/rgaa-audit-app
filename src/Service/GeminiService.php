<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class GeminiService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $geminiApiKey,
        private string $geminiApiUrl,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Analyze audit results with Gemini AI
     */
    public function analyzeResults(array $playwrightResults, array $pa11yResults, string $url): array
    {
        $prompt = $this->buildAnalysisPrompt($playwrightResults, $pa11yResults, $url);

        try {
            // Add API key to URL
            $urlWithKey = $this->geminiApiUrl . '?key=' . $this->geminiApiKey;

            $response = $this->httpClient->request('POST', $urlWithKey, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 120, // 2 minutes timeout
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.3,
                        'topK' => 40,
                        'topP' => 0.95,
                        'maxOutputTokens' => 65536, // Augmenté pour Gemini 2.5 (thoughts + output)
                        'responseMimeType' => 'application/json',
                    ]
                ],
            ]);

            $data = $response->toArray();

            // Log complete response for debugging
            $responseDebugFile = '/tmp/gemini_full_response_' . time() . '.json';
            file_put_contents($responseDebugFile, json_encode($data, JSON_PRETTY_PRINT));

            $this->logger->debug('Gemini API response structure', [
                'has_candidates' => isset($data['candidates']),
                'candidates_count' => isset($data['candidates']) ? count($data['candidates']) : 0,
                'response_keys' => array_keys($data),
                'debug_file' => $responseDebugFile
            ]);

            if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                $this->logger->error('Invalid Gemini response format', [
                    'response' => json_encode($data),
                    'debug_file' => $responseDebugFile
                ]);
                throw new \RuntimeException('Invalid response format from Gemini API. Voir: ' . $responseDebugFile);
            }

            $analysisText = $data['candidates'][0]['content']['parts'][0]['text'];

            // Save raw response for debugging
            $debugFile = '/tmp/gemini_response_' . time() . '.txt';
            file_put_contents($debugFile, $analysisText);

            $this->logger->info('Gemini analysis completed', [
                'url' => $url,
                'response_length' => strlen($analysisText),
                'debug_file' => $debugFile
            ]);

            return $this->parseGeminiResponse($analysisText);

        } catch (\Exception $e) {
            $this->logger->error('Gemini analysis failed', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Gemini analysis failed: ' . $e->getMessage());
        }
    }

    /**
     * Build analysis prompt for Gemini
     */
    private function buildAnalysisPrompt(array $playwrightResults, array $pa11yResults, string $url): string
    {
        // Extraire toutes les erreurs uniques par type
        $issuesByType = [];

        // Extraire Playwright issues groupées par type
        if (isset($playwrightResults['tests']) && is_array($playwrightResults['tests'])) {
            foreach ($playwrightResults['tests'] as $test) {
                if (isset($test['issues']) && is_array($test['issues'])) {
                    $testName = $test['name'] ?? 'Unknown';
                    if (!isset($issuesByType[$testName])) {
                        $issuesByType[$testName] = [
                            'errorType' => $testName,
                            'source' => 'playwright',
                            'severity' => $test['issues'][0]['severity'] ?? 'minor',
                            'count' => 0,
                            'examples' => []
                        ];
                    }
                    $issuesByType[$testName]['count'] += count($test['issues']);
                    // Prendre 2 exemples maximum par type
                    $issuesByType[$testName]['examples'] = array_merge(
                        $issuesByType[$testName]['examples'],
                        array_slice($test['issues'], 0, 2)
                    );
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

        $prompt = "Expert RGAA: analyse ces {$url} erreurs d'accessibilité.\n\n";
        $prompt .= "Erreurs groupées par type:\n";
        $prompt .= json_encode(array_values($issuesByType), JSON_PRETTY_PRINT) . "\n\n";

        $prompt .= "Pour CHAQUE type d'erreur, génère:\n";
        $prompt .= "- errorType: nom du type\n";
        $prompt .= "- severity: critique/majeur/mineur\n";
        $prompt .= "- description: explication concise (max 80 mots)\n";
        $prompt .= "- impactUser: impact utilisateurs (max 60 mots)\n";
        $prompt .= "- recommendation: comment corriger (max 80 mots)\n";
        $prompt .= "- codeFix: exemple de code corrigé\n";
        $prompt .= "- wcagCriteria: critères WCAG (ex: \"2.4.7\")\n";
        $prompt .= "- rgaaCriteria: critères RGAA (ex: \"7.3\")\n";
        $prompt .= "- source: playwright ou pa11y\n\n";

        $prompt .= "IMPORTANT: Pour les statistiques RGAA, base-toi UNIQUEMENT sur les erreurs fournies.\n";
        $prompt .= "- Si AUCUNE erreur n'est fournie, retourne: conformCriteria=106, nonConformCriteria=0, nonConformDetails=[]\n";
        $prompt .= "- Si des erreurs existent, identifie les critères RGAA concernés\n\n";

        $prompt .= "Pour chaque critère RGAA NON CONFORME, fournis dans nonConformDetails:\n";
        $prompt .= "- criteriaNumber: numéro du critère (ex: \"1.1\", \"3.2\")\n";
        $prompt .= "- criteriaTitle: titre court du critère (ex: \"Images avec alternative textuelle\")\n";
        $prompt .= "- reason: raison de non-conformité basée sur les erreurs détectées (max 100 mots)\n";
        $prompt .= "- errorCount: nombre d'erreurs liées à ce critère\n\n";

        $prompt .= "Format JSON attendu:\n";
        $prompt .= '{"results":[{...}],"summary":"résumé 2-3 phrases","conformityRate":85.5,"statistics":{"conformCriteria":90,"nonConformCriteria":12,"notApplicableCriteria":4,"nonConformDetails":[{"criteriaNumber":"1.1","criteriaTitle":"Images","reason":"15 images sans alternative","errorCount":15}]}}';

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
            // Save failed response for debugging
            $errorFile = '/tmp/gemini_error_' . time() . '.txt';
            file_put_contents($errorFile, "=== CLEANED RESPONSE ===\n" . $response . "\n\n=== JSON ERROR ===\n" . json_last_error_msg());

            $this->logger->error('JSON parsing failed', [
                'error' => json_last_error_msg(),
                'error_file' => $errorFile,
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 200)
            ]);
            throw new \RuntimeException('Failed to parse Gemini response as JSON: ' . json_last_error_msg() . ' (voir ' . $errorFile . ')');
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
}
