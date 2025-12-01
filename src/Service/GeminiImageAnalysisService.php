<?php

namespace App\Service;

use App\Enum\ImageAnalysisType;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiImageAnalysisService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $geminiApiKey,
        private string $geminiApiUrl,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Analyze individual images with multiple analysis types
     *
     * @param array $individualImages Array of images with screenshot and alt text
     * @param array $analysisTypes Array of ImageAnalysisType constants to perform
     * @return array Analysis results grouped by type
     */
    public function analyzeImages(array $individualImages, array $analysisTypes): array
    {
        if (empty($individualImages)) {
            $this->logger->info('No individual images to analyze');
            return [];
        }

        if (empty($analysisTypes)) {
            $this->logger->info('No analysis types selected');
            return [];
        }

        $totalImages = count($individualImages);
        $this->logger->info("🚀 OPTIMIZED: Starting deep image analysis for {$totalImages} images with " . count($analysisTypes) . " analysis types in ONE API call per batch");

        // Validate analysis types
        $validTypes = [];
        foreach ($analysisTypes as $analysisType) {
            if (!ImageAnalysisType::isValid($analysisType)) {
                $this->logger->warning("Invalid analysis type: {$analysisType}");
                continue;
            }
            $validTypes[] = $analysisType;
        }

        if (empty($validTypes)) {
            $this->logger->warning("No valid analysis types provided");
            return [];
        }

        // Log what we're analyzing
        $typeLabels = array_map(fn($type) => ImageAnalysisType::getLabel($type), $validTypes);
        $this->logger->info("Analyzing: " . implode(", ", $typeLabels));

        // OPTIMIZED: Process all types in one go
        return $this->analyzeByTypesBatch($individualImages, $validTypes);
    }

    /**
     * Analyze images for multiple analysis types (OPTIMIZED - one API call per batch)
     */
    private function analyzeByType(array $individualImages, string $analysisType): array
    {
        // This method is kept for backward compatibility but redirects to the optimized method
        // It will be called once with all types from analyzeImages()
        return [];
    }

    /**
     * Analyze images for multiple analysis types in one batch (OPTIMIZED)
     */
    private function analyzeByTypesBatch(array $individualImages, array $analysisTypes): array
    {
        $resultsByType = [];
        foreach ($analysisTypes as $type) {
            $resultsByType[$type] = [];
        }

        // Process in batches of 3 to avoid timeout (images can be large)
        $batchSize = 3;
        $batches = array_chunk($individualImages, $batchSize);
        $totalBatches = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            $batchNumber = $batchIndex + 1;

            $this->logger->info("Processing batch {$batchNumber}/{$totalBatches} with " . count($analysisTypes) . " analysis types");

            try {
                // Call API once for all analysis types
                $batchResults = $this->analyzeBatchMultipleTypes($batch, $analysisTypes);

                // Merge results by type
                foreach ($analysisTypes as $type) {
                    if (isset($batchResults[$type])) {
                        $resultsByType[$type] = array_merge($resultsByType[$type], $batchResults[$type]);
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error("Failed to analyze batch {$batchNumber}: {$e->getMessage()}");

                // Add error entries for failed batch
                foreach ($analysisTypes as $type) {
                    foreach ($batch as $img) {
                        $resultsByType[$type][] = [
                            'index' => $img['index'],
                            'src' => $img['src'] ?? 'unknown',
                            'alt' => $img['alt'] ?? '',
                            'analysisType' => $type,
                            'hasIssue' => null,
                            'issue' => 'Analysis failed: ' . $e->getMessage(),
                            'suggestion' => null,
                            'confidence' => 0
                        ];
                    }
                }
            }
        }

        return $resultsByType;
    }

    /**
     * Legacy method for backward compatibility
     *
     * @deprecated Use analyzeImages() instead
     */
    public function analyzeImageAltRelevance(array $individualImages): array
    {
        $results = $this->analyzeImages($individualImages, [ImageAnalysisType::ALT_RELEVANCE]);
        return $results[ImageAnalysisType::ALT_RELEVANCE] ?? [];
    }

    /**
     * Analyze a batch of images for MULTIPLE analysis types (OPTIMIZED)
     */
    private function analyzeBatchMultipleTypes(array $batch, array $analysisTypes): array
    {
        // Build combined prompt for all analysis types
        $prompt = "Tu es un expert en accessibilité web RGAA. Analyse ces images selon PLUSIEURS critères.\n\n";

        $prompt .= "=== CRITÈRES À ANALYSER ===\n\n";

        foreach ($analysisTypes as $type) {
            $prompt .= "📋 " . strtoupper(str_replace('-', ' ', $type)) . " :\n";
            $prompt .= $this->buildPromptForAnalysisType($type);
            $prompt .= "\n---\n\n";
        }

        $prompt .= "Pour CHAQUE image, analyse TOUS les critères ci-dessus et retourne un résultat par critère.\n\n";

        // Build parts array with images
        $parts = [['text' => $prompt]];

        foreach ($batch as $index => $img) {
            // Add image
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data' => $img['screenshot']
                ]
            ];

            // Add image info
            $altText = $img['alt'] ?? '(vide)';
            $srcInfo = isset($img['src']) ? $img['src'] : (isset($img['fields']) ? count($img['fields']) . ' champs' : 'unknown');
            $parts[] = [
                'text' => "Image/Form #{$img['index']}: alt=\"{$altText}\" | info: {$srcInfo}\n\n"
            ];
        }

        // Add response format instruction with strict length constraints
        $parts[] = [
            'text' => "\nRéponds avec un JSON contenant les résultats GROUPÉS PAR TYPE D'ANALYSE :\n" .
                     "{\n" .
                     "  \"" . $analysisTypes[0] . "\": [\n" .
                     "    {\n" .
                     "      \"imageIndex\": 0,\n" .
                     "      \"hasIssue\": true|false,\n" .
                     "      \"issue\": \"Description courte (MAX 100 caractères)\",\n" .
                     "      \"suggestion\": \"Action concrète en 1-2 phrases MAX. Première phrase : quoi faire. Deuxième : bénéfice utilisateur.\",\n" .
                     "      \"confidence\": 0.0-1.0 (1.0=certain, 0.8=évident, 0.6=probable, 0.4=possible, 0.2=incertain)\n" .
                     "    }\n" .
                     "  ],\n" .
                     (count($analysisTypes) > 1 ? "  \"" . $analysisTypes[1] . "\": [...],\n" : "") .
                     "  ...\n" .
                     "}\n\n" .
                     "CONTRAINTES STRICTES :\n" .
                     "- issue : MAX 100 caractères\n" .
                     "- suggestion : MAX 2 phrases courtes\n" .
                     "- Chaque type = un résultat par image\n" .
                     "- JSON uniquement, AUCUN texte avant/après"
        ];

        // Call Gemini API
        $urlWithKey = $this->geminiApiUrl . '?key=' . $this->geminiApiKey;

        $response = $this->httpClient->request('POST', $urlWithKey, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 180, // 3 minutes for batch processing with images
            'json' => [
                'contents' => [
                    [
                        'parts' => $parts
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.2, // Équilibre optimal : cohérent mais pas robotique
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 8192,
                ]
            ]
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            throw new \RuntimeException("Gemini API returned status {$statusCode}");
        }

        $data = $response->toArray();

        // Extract text from response
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$text) {
            throw new \RuntimeException('No text in Gemini response');
        }

        // Parse JSON response (now grouped by type)
        $analysisResults = $this->parseAnalysisResponse($text);

        // Map results back to images for each type
        $mappedResultsByType = [];

        foreach ($analysisTypes as $type) {
            $mappedResultsByType[$type] = [];
            $typeResults = $analysisResults[$type] ?? [];

            foreach ($batch as $img) {
                $analysis = $this->findAnalysisForImage($img['index'], $typeResults);

                $mappedResultsByType[$type][] = [
                    'index' => $img['index'],
                    'src' => $img['src'] ?? 'unknown',
                    'alt' => $img['alt'] ?? '',
                    'analysisType' => $type,
                    'hasIssue' => $analysis['hasIssue'] ?? null,
                    'issue' => $analysis['issue'] ?? null,
                    'suggestion' => $analysis['suggestion'] ?? null,
                    'confidence' => $analysis['confidence'] ?? 0.5
                ];
            }
        }

        return $mappedResultsByType;
    }

    /**
     * Analyze a batch of images for specific analysis type (DEPRECATED - use analyzeBatchMultipleTypes)
     */
    private function analyzeBatch(array $batch, string $analysisType): array
    {
        // Build prompt based on analysis type
        $prompt = $this->buildPromptForAnalysisType($analysisType);

        // Build parts array with images
        $parts = [['text' => $prompt]];

        foreach ($batch as $index => $img) {
            // Add image
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data' => $img['screenshot']
                ]
            ];

            // Add image info
            $altText = $img['alt'] ?: '(vide)';
            $parts[] = [
                'text' => "Image #{$img['index']}: alt=\"{$altText}\" | src: {$img['src']}\n\n"
            ];
        }

        // Add response format instruction
        $parts[] = [
            'text' => "\nRéponds UNIQUEMENT avec un JSON valide (pas de markdown) :\n" .
                     "[\n" .
                     "  {\n" .
                     "    \"imageIndex\": 0,\n" .
                     "    \"hasIssue\": true|false,\n" .
                     "    \"issue\": \"description du problème\" | null,\n" .
                     "    \"suggestion\": \"suggestion d'amélioration\" | null,\n" .
                     "    \"confidence\": 0.0-1.0\n" .
                     "  }\n" .
                     "]\n\n" .
                     "Réponds UNIQUEMENT avec le tableau JSON, sans texte avant ou après."
        ];

        // Call Gemini API
        $urlWithKey = $this->geminiApiUrl . '?key=' . $this->geminiApiKey;

        $response = $this->httpClient->request('POST', $urlWithKey, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 180, // 3 minutes for batch processing with images
            'json' => [
                'contents' => [
                    [
                        'parts' => $parts
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.2, // Équilibre optimal : cohérent mais pas robotique
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 8192,
                ]
            ]
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            throw new \RuntimeException("Gemini API returned status {$statusCode}");
        }

        $data = $response->toArray();

        // Extract text from response
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$text) {
            throw new \RuntimeException('No text in Gemini response');
        }

        // Parse JSON response
        $analysisResults = $this->parseAnalysisResponse($text);

        // Map results back to original images
        $mappedResults = [];
        foreach ($batch as $img) {
            $analysis = $this->findAnalysisForImage($img['index'], $analysisResults);

            $mappedResults[] = [
                'index' => $img['index'],
                'src' => $img['src'],
                'alt' => $img['alt'],
                'analysisType' => $analysisType,
                'hasIssue' => $analysis['hasIssue'] ?? null,
                'issue' => $analysis['issue'] ?? null,
                'suggestion' => $analysis['suggestion'] ?? null,
                'confidence' => $analysis['confidence'] ?? 0.5
            ];
        }

        return $mappedResults;
    }

    /**
     * Parse Gemini analysis response (handles both array and object formats)
     */
    private function parseAnalysisResponse(string $response): array
    {
        // Clean response
        $response = trim($response);

        // Remove markdown code blocks
        $response = preg_replace('/^```(?:json)?\s*/m', '', $response);
        $response = preg_replace('/\s*```$/m', '', $response);
        $response = trim($response);

        // Try to extract JSON (array or object)
        if (preg_match('/[\[{][\s\S]*[\]}]/s', $response, $matches)) {
            $response = $matches[0];
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to parse Gemini analysis response', [
                'error' => json_last_error_msg(),
                'response' => substr($response, 0, 500)
            ]);
            throw new \RuntimeException('Invalid JSON response from Gemini: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Find analysis result for specific image index
     */
    private function findAnalysisForImage(int $imageIndex, array $analysisResults): array
    {
        foreach ($analysisResults as $result) {
            if (isset($result['imageIndex']) && $result['imageIndex'] === $imageIndex) {
                return $result;
            }
        }

        return [
            'hasIssue' => null,
            'issue' => 'No analysis found',
            'suggestion' => null,
            'confidence' => 0
        ];
    }

    /**
     * Build prompt based on analysis type
     */
    private function buildPromptForAnalysisType(string $analysisType): string
    {
        $basePrompt = "Tu es un expert en accessibilité web RGAA. ";

        return match($analysisType) {
            ImageAnalysisType::ALT_RELEVANCE => $basePrompt . "Analyse ces images et vérifie si l'attribut alt est pertinent.\n\n" .
                "Critères d'évaluation (RGAA 1.3 / WCAG 1.1.1) :\n" .
                "- L'alt doit décrire le CONTENU de l'image, pas juste 'image', 'photo', ou le nom de fichier\n" .
                "- Si l'image est décorative, alt doit être vide (alt=\"\")\n" .
                "- L'alt doit donner la même information que l'image pour quelqu'un qui ne la voit pas\n" .
                "- L'alt ne doit pas commencer par 'image de' ou 'photo de'\n\n" .
                "Pour chaque image, indique si l'alt est pertinent (hasIssue: false) ou non (hasIssue: true).\n\n",

            ImageAnalysisType::DECORATIVE_DETECTION => $basePrompt . "Détermine si ces images sont décoratives ou informatives.\n\n" .
                "Critères d'évaluation (RGAA 1.2 / WCAG 1.1.1) :\n" .
                "- Image DÉCORATIVE : n'apporte aucune information, purement esthétique (doit avoir alt=\"\" ou role=\"presentation\")\n" .
                "- Image INFORMATIVE : contient une information utile (doit avoir un alt descriptif)\n\n" .
                "Vérifie si les images décoratives ont bien alt=\"\" et si les images informatives ont un alt descriptif.\n" .
                "Indique hasIssue: true si une image décorative a un alt non vide, ou si une image informative n'a pas d'alt.\n\n",

            ImageAnalysisType::TEXT_IN_IMAGE => $basePrompt . "Détecte si du texte est présent dans ces images.\n\n" .
                "Critères d'évaluation (RGAA 8.9 / WCAG 1.4.5) :\n" .
                "- Le texte doit être en HTML, pas dans une image (sauf logos, graphiques essentiels)\n" .
                "- Détecte tout texte lisible dans l'image (titres, paragraphes, labels, etc.)\n\n" .
                "Indique hasIssue: true si l'image contient du texte qui devrait être en HTML.\n" .
                "Exceptions acceptables : logos, graphiques avec données, captures d'écran nécessaires.\n\n",

            ImageAnalysisType::TEXT_CONTRAST => $basePrompt . "Vérifie le contraste des textes présents dans ces images.\n\n" .
                "Critères d'évaluation (RGAA 3.2 / WCAG 1.4.3) :\n" .
                "- Texte normal : ratio de contraste ≥ 4.5:1\n" .
                "- Texte large (≥18pt ou ≥14pt gras) : ratio ≥ 3:1\n\n" .
                "Analyse visuellement le contraste entre le texte et son arrière-plan.\n" .
                "Indique hasIssue: true si le contraste semble insuffisant.\n\n",

            ImageAnalysisType::COLOR_ONLY_INFO => $basePrompt . "Détecte si l'information est donnée uniquement par la couleur.\n\n" .
                "Critères d'évaluation (RGAA 3.3 / WCAG 1.4.1) :\n" .
                "- L'information ne doit pas reposer uniquement sur la couleur\n" .
                "- Il doit y avoir un autre indicateur (forme, texte, motif, icône)\n\n" .
                "Exemples problématiques :\n" .
                "- Graphiques avec légendes uniquement en couleur\n" .
                "- Liens distingués uniquement par la couleur\n" .
                "- Statuts (succès/erreur) uniquement en couleur\n\n" .
                "Indique hasIssue: true si l'information repose uniquement sur la couleur.\n\n",

            ImageAnalysisType::FORM_LABELS => $basePrompt . "Analyse VISUELLE des formulaires (RGAA 11.1 / WCAG 3.3.2).\n\n" .
                "🎯 TON RÔLE : Détecter les problèmes CONTEXTUELS que les tests automatiques ne voient pas.\n\n" .
                "Les tests auto ont déjà vérifié : labels manquants/cachés/génériques, associations techniques.\n\n" .
                "TOI, détecte ces 4 types de problèmes :\n\n" .
                "1️⃣ **Ambiguïté** : Plusieurs \"Email\" ou \"Date\" sans distinction (perso/pro, naissance/début)\n\n" .
                "2️⃣ **Disposition confuse** : Label qui semble lié au mauvais champ, ordre illogique (Email avant Nom)\n\n" .
                "3️⃣ **Manque d'indication** : Champs obligatoires (*) non marqués, format attendu absent (+33...)\n\n" .
                "4️⃣ **Clarté insuffisante** : \"Ville\" sans préciser laquelle, label incomplet\n\n" .
                "✅ Indique hasIssue: true UNIQUEMENT si problème visuel/contextuel réel.\n\n",

            default => $basePrompt . "Analyse ces images selon les critères d'accessibilité RGAA.\n\n"
        };
    }
}
