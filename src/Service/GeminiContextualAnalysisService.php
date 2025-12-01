<?php

namespace App\Service;

use App\Enum\ContextualAnalysisType;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gemini AI service for contextual accessibility analysis
 * Works with Playwright-captured context to provide hybrid automated + AI testing
 */
class GeminiContextualAnalysisService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $geminiApiKey,
        private string $geminiApiUrl,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Analyze contextual elements extracted by Playwright
     *
     * @param array $contextualElements Data from extractContextForIA()
     * @param array $analysisTypes Array of ContextualAnalysisType constants
     * @return array Analysis results grouped by type
     */
    public function analyzeContext(array $contextualElements, array $analysisTypes): array
    {
        if (empty($contextualElements)) {
            $this->logger->info('No contextual elements to analyze');
            return [];
        }

        if (empty($analysisTypes)) {
            $this->logger->info('No contextual analysis types selected');
            return [];
        }

        $this->logger->info("🎯 HYBRID ANALYSIS: Starting contextual analysis with " . count($analysisTypes) . " analysis types");

        // Validate analysis types
        $validTypes = [];
        foreach ($analysisTypes as $analysisType) {
            if (!ContextualAnalysisType::isValid($analysisType)) {
                $this->logger->warning("Invalid contextual analysis type: {$analysisType}");
                continue;
            }
            $validTypes[] = $analysisType;
        }

        if (empty($validTypes)) {
            $this->logger->warning("No valid contextual analysis types provided");
            return [];
        }

        // Log what we're analyzing
        $typeLabels = array_map(fn($type) => ContextualAnalysisType::getLabel($type), $validTypes);
        $this->logger->info("Analyzing: " . implode(", ", $typeLabels));

        $results = [];

        // Process each analysis type
        foreach ($validTypes as $type) {
            try {
                $results[$type] = $this->analyzeByType($contextualElements, $type);
            } catch (\Exception $e) {
                $this->logger->error("Failed to analyze type {$type}: {$e->getMessage()}");
                $results[$type] = [];
            }
        }

        return $results;
    }

    /**
     * Analyze contextual elements for a specific type
     */
    private function analyzeByType(array $contextualElements, string $analysisType): array
    {
        return match($analysisType) {
            ContextualAnalysisType::CONTRAST_CONTEXT => $this->analyzeContrastContext($contextualElements['lowContrastElements'] ?? []),
            ContextualAnalysisType::HEADING_RELEVANCE => $this->analyzeHeadingRelevance($contextualElements['headingsWithContext'] ?? []),
            ContextualAnalysisType::LINK_CONTEXT => $this->analyzeLinkContext($contextualElements['linksWithSurroundings'] ?? []),
            ContextualAnalysisType::TABLE_HEADERS => $this->analyzeTableHeaders($contextualElements['complexTables'] ?? []),
            ContextualAnalysisType::COLOR_INFORMATION => $this->analyzeColorInformation($contextualElements['colorBasedElements'] ?? []),
            ContextualAnalysisType::FOCUS_VISIBLE => $this->analyzeFocusVisible($contextualElements['interactiveElements'] ?? []),
            ContextualAnalysisType::MEDIA_TRANSCRIPTION => $this->analyzeMediaTranscription($contextualElements['mediaElements'] ?? []),
            ContextualAnalysisType::KEYBOARD_SHORTCUTS => $this->analyzeKeyboardShortcuts($contextualElements['keyboardShortcuts'] ?? []),
            ContextualAnalysisType::FOCUS_MANAGEMENT_SCRIPTS => $this->analyzeFocusManagementScripts($contextualElements['dynamicElements'] ?? []),
            ContextualAnalysisType::KEYBOARD_TRAP => $this->analyzeKeyboardTrap($contextualElements['modalsOverlays'] ?? []),
            ContextualAnalysisType::ADDITIONAL_CONTENT_HOVER => $this->analyzeAdditionalContentHover($contextualElements['tooltipsPopovers'] ?? []),
            ContextualAnalysisType::NAVIGATION_SYSTEMS => $this->analyzeNavigationSystems($contextualElements['navigationSystems'] ?? []),
            default => []
        };
    }

    /**
     * Analyze contrast on complex backgrounds
     */
    private function analyzeContrastContext(array $elements): array
    {
        if (empty($elements)) {
            return [];
        }

        $this->logger->info("Analyzing " . count($elements) . " borderline contrast elements");

        // Build prompt
        $prompt = "Tu es un expert en accessibilité web RGAA.\n\n";
        $prompt .= "⚠️ CONTEXTE : Les tests automatiques ont détecté des éléments avec un contraste LIMITE (ratio 3.5-5.0).\n";
        $prompt .= "Ton rôle est d'analyser VISUELLEMENT si ces éléments sont vraiment problématiques dans leur contexte.\n\n";
        $prompt .= "🎯 FOCUS : Analyse visuelle du contraste dans des situations complexes\n\n";
        $prompt .= "Critères RGAA 3.2 / WCAG 1.4.3 :\n";
        $prompt .= "- Texte normal : contraste ≥ 4.5:1\n";
        $prompt .= "- Texte large (≥18pt ou ≥14pt gras) : contraste ≥ 3:1\n";
        $prompt .= "- Arrière-plans complexes (dégradés, images, textures) nécessitent une analyse visuelle\n\n";
        $prompt .= "Pour CHAQUE élément screenshot :\n";
        $prompt .= "1. Évalue la LISIBILITÉ visuelle réelle (pas juste le ratio mathématique)\n";
        $prompt .= "2. Considère le contexte : arrière-plan, taille de police, épaisseur\n";
        $prompt .= "3. Indique si c'est un vrai problème (hasIssue: true) ou acceptable dans ce contexte (hasIssue: false)\n\n";

        // Build parts with screenshots
        $parts = [['text' => $prompt]];

        foreach ($elements as $index => $el) {
            // Add screenshot
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data' => $el['screenshot']
                ]
            ];

            // Add element info
            $parts[] = [
                'text' => "Élément #{$index}: \"{$el['text']}\" | " .
                         "Ratio détecté: {$el['contrast']} | " .
                         "Couleur: {$el['color']} sur {$el['backgroundColor']} | " .
                         "Taille: {$el['fontSize']} | Poids: {$el['fontWeight']}\n\n"
            ];
        }

        // Add response format with correction examples
        $parts[] = [
            'text' => "\nRéponds UNIQUEMENT avec un JSON valide :\n" .
                     "[\n" .
                     "  {\n" .
                     "    \"elementIndex\": 0,\n" .
                     "    \"hasIssue\": true|false,\n" .
                     "    \"issue\": \"description du problème\",\n" .
                     "    \"suggestion\": \"suggestion d'amélioration détaillée\",\n" .
                     "    \"codeExample\": {\n" .
                     "      \"before\": \"<div style='color:#777;background:#fff'>Texte</div>\",\n" .
                     "      \"after\": \"<div style='color:#595959;background:#fff'>Texte</div> /* Contraste 4.54:1 */\"\n" .
                     "    },\n" .
                     "    \"confidence\": 0.0-1.0\n" .
                     "  }\n" .
                     "]\n" .
                     "Note: Fournis des exemples de code AVANT/APRES pour CHAQUE problème détecté.\n"
        ];

        // Call API
        $response = $this->callGeminiAPI($parts);

        // Map results back to elements
        return $this->mapResultsToElements($elements, $response, ContextualAnalysisType::CONTRAST_CONTEXT);
    }

    /**
     * Analyze heading relevance
     */
    private function analyzeHeadingRelevance(array $headings): array
    {
        if (empty($headings)) {
            return [];
        }

        $this->logger->info("Analyzing " . count($headings) . " headings for relevance");

        // Build prompt
        $prompt = "Tu es un expert en accessibilité web RGAA.\n\n";
        $prompt .= "⚠️ CONTEXTE : Les tests automatiques ont vérifié la structure hiérarchique des titres.\n";
        $prompt .= "Ton rôle est d'analyser si les TITRES sont PERTINENTS par rapport au contenu qu'ils introduisent.\n\n";
        $prompt .= "🎯 FOCUS : Pertinence sémantique des titres (RGAA 6.1, 9.1 / WCAG 2.4.6, 1.3.1)\n\n";
        $prompt .= "Pour CHAQUE titre, évalue :\n";
        $prompt .= "1. Le titre décrit-il bien le contenu qui suit ?\n";
        $prompt .= "2. Est-il suffisamment descriptif et unique ?\n";
        $prompt .= "3. Évite-t-il les formulations génériques (\"Introduction\", \"Contenu\", \"Section\") ?\n";
        $prompt .= "4. Est-il cohérent avec son niveau hiérarchique ?\n\n";

        // Build parts (text only, no screenshots needed)
        $parts = [['text' => $prompt]];

        foreach ($headings as $heading) {
            $parts[] = [
                'text' => "---\n" .
                         "Titre: <{$heading['level']}>{$heading['text']}</{$heading['level']}>\n" .
                         "Contenu suivant: {$heading['nextContent']}\n" .
                         "Contexte section: {$heading['sectionContext']}\n\n"
            ];
        }

        // Add response format with correction examples
        $parts[] = [
            'text' => "\nRéponds UNIQUEMENT avec un JSON valide :\n" .
                     "[\n" .
                     "  {\n" .
                     "    \"headingIndex\": 0,\n" .
                     "    \"hasIssue\": true|false,\n" .
                     "    \"issue\": \"description du problème\",\n" .
                     "    \"suggestion\": \"suggestion d'amélioration détaillée\",\n" .
                     "    \"codeExample\": {\n" .
                     "      \"before\": \"<h2>Introduction</h2>\",\n" .
                     "      \"after\": \"<h2>Introduction aux services de notre plateforme</h2>\"\n" .
                     "    },\n" .
                     "    \"confidence\": 0.0-1.0\n" .
                     "  }\n" .
                     "]\n" .
                     "Note: Fournis des exemples de titres AVANT/APRES plus descriptifs.\n"
            ];

        // Call API
        $response = $this->callGeminiAPI($parts);

        // Map results
        return $this->mapResultsToElements($headings, $response, ContextualAnalysisType::HEADING_RELEVANCE, 'headingIndex');
    }

    /**
     * Analyze link clarity in context
     */
    private function analyzeLinkContext(array $links): array
    {
        if (empty($links)) {
            return [];
        }

        $this->logger->info("Analyzing " . count($links) . " ambiguous links");

        // Build prompt
        $prompt = "Tu es un expert en accessibilité web RGAA.\n\n";
        $prompt .= "⚠️ CONTEXTE : Les tests automatiques ont détecté des liens potentiellement ambigus.\n";
        $prompt .= "Ton rôle est d'évaluer si ces liens sont COMPRÉHENSIBLES HORS CONTEXTE.\n\n";
        $prompt .= "🎯 FOCUS : Clarté des liens (RGAA 6.2 / WCAG 2.4.4)\n\n";
        $prompt .= "Un lien doit être compréhensible pour un utilisateur de lecteur d'écran qui navigue de lien en lien.\n\n";
        $prompt .= "Pour CHAQUE lien, évalue :\n";
        $prompt .= "1. Le texte du lien est-il explicite seul (sans le contexte) ?\n";
        $prompt .= "2. Un aria-label ou title complète-t-il le sens ?\n";
        $prompt .= "3. Évite-t-il les formulations vagues (\"cliquez ici\", \"en savoir plus\", \"lire la suite\") ?\n";
        $prompt .= "4. S'il y a plusieurs liens similaires, sont-ils différenciables ?\n\n";

        // Build parts
        $parts = [['text' => $prompt]];

        foreach ($links as $link) {
            $parts[] = [
                'text' => "---\n" .
                         "Texte du lien: \"{$link['text']}\"\n" .
                         "Destination: {$link['href']}\n" .
                         "Aria-label: " . ($link['ariaLabel'] ?? 'aucun') . "\n" .
                         "Title: " . ($link['title'] ?? 'aucun') . "\n" .
                         "Contexte environnant: {$link['surroundingContext']}\n\n"
            ];
        }

        // Add response format with correction examples
        $parts[] = [
            'text' => "\nRéponds UNIQUEMENT avec un JSON valide :\n" .
                     "[\n" .
                     "  {\n" .
                     "    \"linkIndex\": 0,\n" .
                     "    \"hasIssue\": true|false,\n" .
                     "    \"issue\": \"description du problème\",\n" .
                     "    \"suggestion\": \"suggestion d'amélioration détaillée\",\n" .
                     "    \"codeExample\": {\n" .
                     "      \"before\": \"<a href='/product/123'>En savoir plus</a>\",\n" .
                     "      \"after\": \"<a href='/product/123' aria-label='En savoir plus sur le produit iPhone 15 Pro'>En savoir plus</a>\"\n" .
                     "    },\n" .
                     "    \"confidence\": 0.0-1.0\n" .
                     "  }\n" .
                     "]\n" .
                     "Note: Fournis des exemples de liens AVANT/APRES avec aria-label explicite.\n"
        ];

        // Call API
        $response = $this->callGeminiAPI($parts);

        // Map results
        return $this->mapResultsToElements($links, $response, ContextualAnalysisType::LINK_CONTEXT, 'linkIndex');
    }

    /**
     * Analyze table headers descriptiveness
     */
    private function analyzeTableHeaders(array $tables): array
    {
        if (empty($tables)) {
            return [];
        }

        $this->logger->info("Analyzing " . count($tables) . " tables");

        // Build prompt
        $prompt = "Tu es un expert en accessibilité web RGAA.\n\n";
        $prompt .= "⚠️ CONTEXTE : Les tests automatiques ont vérifié la présence d'en-têtes de tableaux.\n";
        $prompt .= "Ton rôle est d'évaluer si ces EN-TÊTES sont DESCRIPTIFS et CLAIRS.\n\n";
        $prompt .= "🎯 FOCUS : Descriptivité des en-têtes de tableaux (RGAA 5.7 / WCAG 1.3.1)\n\n";
        $prompt .= "Pour CHAQUE tableau, évalue :\n";
        $prompt .= "1. Les en-têtes décrivent-ils clairement les données des colonnes/lignes ?\n";
        $prompt .= "2. Évitent-ils les abréviations obscures ou jargon technique ?\n";
        $prompt .= "3. Le caption (si présent) aide-t-il à comprendre le tableau ?\n";
        $prompt .= "4. Les en-têtes sont-ils cohérents entre eux ?\n\n";

        // Build parts with screenshots
        $parts = [['text' => $prompt]];

        foreach ($tables as $index => $table) {
            // Add screenshot
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data' => $table['screenshot']
                ]
            ];

            // Add table info
            $parts[] = [
                'text' => "---\n" .
                         "Tableau #{$index}\n" .
                         "Caption: " . ($table['captionText'] ?: 'aucun') . "\n" .
                         "En-têtes: " . implode(', ', $table['headers']) . "\n" .
                         "Exemple de données (premières lignes): " . json_encode($table['sampleData']) . "\n\n"
            ];
        }

        // Add response format
        $parts[] = [
            'text' => "\nRéponds UNIQUEMENT avec un JSON valide :\n" .
                     "[\n" .
                     "  {\n" .
                     "    \"tableIndex\": 0,\n" .
                     "    \"hasIssue\": true|false,\n" .
                     "    \"issue\": \"description du problème\" | null,\n" .
                     "    \"suggestion\": \"suggestion d'amélioration\" | null,\n" .
                     "    \"confidence\": 0.0-1.0\n" .
                     "  }\n" .
                     "]\n"
        ];

        // Call API
        $response = $this->callGeminiAPI($parts);

        // Map results
        return $this->mapResultsToElements($tables, $response, ContextualAnalysisType::TABLE_HEADERS, 'tableIndex');
    }

    /**
     * Call Gemini API with parts
     */
    private function callGeminiAPI(array $parts): string
    {
        $urlWithKey = $this->geminiApiUrl . '?key=' . $this->geminiApiKey;

        $response = $this->httpClient->request('POST', $urlWithKey, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 180,
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

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$text) {
            throw new \RuntimeException('No text in Gemini response');
        }

        return $text;
    }

    /**
     * Parse Gemini response and map to elements
     */
    private function mapResultsToElements(array $elements, string $response, string $analysisType, string $indexKey = 'elementIndex'): array
    {
        // Parse JSON response
        $response = trim($response);
        $response = preg_replace('/^```(?:json)?\s*/m', '', $response);
        $response = preg_replace('/\s*```$/m', '', $response);
        $response = trim($response);

        if (preg_match('/\[[\s\S]*\]/s', $response, $matches)) {
            $response = $matches[0];
        }

        $analysisResults = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to parse Gemini contextual analysis response', [
                'error' => json_last_error_msg(),
                'response' => substr($response, 0, 500)
            ]);
            throw new \RuntimeException('Invalid JSON response from Gemini: ' . json_last_error_msg());
        }

        // Map results
        $mappedResults = [];

        foreach ($elements as $index => $element) {
            $analysis = null;

            // Find matching analysis result
            foreach ($analysisResults as $result) {
                if (isset($result[$indexKey]) && $result[$indexKey] === $index) {
                    $analysis = $result;
                    break;
                }
            }

            $mappedResults[] = [
                'index' => $index,
                'element' => $element,
                'analysisType' => $analysisType,
                'hasIssue' => $analysis['hasIssue'] ?? null,
                'issue' => $analysis['issue'] ?? null,
                'suggestion' => $analysis['suggestion'] ?? null,
                'codeExample' => $analysis['codeExample'] ?? null,
                'confidence' => $analysis['confidence'] ?? 0.5
            ];
        }

        return $mappedResults;
    }

    /**
     * Analyze information conveyed by color alone (RGAA 3.1)
     */
    private function analyzeColorInformation(array $elements): array
    {
        if (empty($elements)) {
            return [];
        }

        $this->logger->info("Analyzing " . count($elements) . " elements for color-based information");

        // Build prompt
        $prompt = "Tu es un expert en accessibilité web RGAA.\n\n";
        $prompt .= "⚠️ CONTEXTE : Les tests automatiques ont détecté des éléments qui pourraient transmettre de l'information uniquement par la couleur.\n";
        $prompt .= "Ton rôle est d'analyser VISUELLEMENT si ces éléments transmettent de l'information UNIQUEMENT par la couleur.\n\n";
        $prompt .= "🎯 FOCUS : Information par couleur seule (RGAA 3.1 / WCAG 1.4.1)\n\n";
        $prompt .= "Exemples problématiques :\n";
        $prompt .= "- Graphiques où les données sont différenciées uniquement par couleur\n";
        $prompt .= "- Statuts (erreur/succès) indiqués uniquement en rouge/vert\n";
        $prompt .= "- Liens différenciés du texte uniquement par la couleur\n";
        $prompt .= "- Champs obligatoires marqués uniquement par une étoile rouge\n\n";
        $prompt .= "Pour CHAQUE élément screenshot :\n";
        $prompt .= "1. Identifie si l'information est transmise UNIQUEMENT par la couleur\n";
        $prompt .= "2. Vérifie s'il existe des indicateurs supplémentaires (icônes, texte, motifs, bordures)\n";
        $prompt .= "3. Indique si c'est problématique (hasIssue: true) ou si des alternatives existent (hasIssue: false)\n\n";

        // Build parts with screenshots
        $parts = [['text' => $prompt]];

        foreach ($elements as $index => $el) {
            // Add screenshot
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data' => $el['screenshot']
                ]
            ];

            // Add element info
            $parts[] = [
                'text' => "Élément #{$index}: Type: {$el['type']} | " .
                         "Texte: \"{$el['text']}\" | " .
                         "Couleurs détectées: {$el['colors']}\n\n"
            ];
        }

        // Add response format
        $parts[] = [
            'text' => "\nRéponds UNIQUEMENT avec un JSON valide :\n" .
                     "[\n" .
                     "  {\n" .
                     "    \"elementIndex\": 0,\n" .
                     "    \"hasIssue\": true|false,\n" .
                     "    \"issue\": \"description du problème\" | null,\n" .
                     "    \"suggestion\": \"suggestion d'amélioration (ajouter icône, motif, texte)\" | null,\n" .
                     "    \"confidence\": 0.0-1.0\n" .
                     "  }\n" .
                     "]\n"
        ];

        // Call API
        $response = $this->callGeminiAPI($parts);

        // Map results
        return $this->mapResultsToElements($elements, $response, ContextualAnalysisType::COLOR_INFORMATION);
    }

    /**
     * Analyze focus visibility (RGAA 10.7)
     */
    private function analyzeFocusVisible(array $elements): array
    {
        if (empty($elements)) {
            return [];
        }

        $this->logger->info("Analyzing " . count($elements) . " interactive elements for focus visibility");

        // Build prompt
        $prompt = "Tu es un expert en accessibilité web RGAA.\n\n";
        $prompt .= "⚠️ CONTEXTE : Playwright a capturé des screenshots d'éléments interactifs AVANT et APRÈS la prise de focus.\n";
        $prompt .= "Ton rôle est d'analyser VISUELLEMENT si l'indicateur de focus est VISIBLE et SUFFISANT.\n\n";
        $prompt .= "🎯 FOCUS : Visibilité de la prise de focus (RGAA 10.7 / WCAG 2.4.7)\n\n";
        $prompt .= "Critères :\n";
        $prompt .= "- L'indicateur de focus doit être VISIBLE (contraste suffisant)\n";
        $prompt .= "- Il doit être DISTINCT de l'état normal\n";
        $prompt .= "- Un simple changement de couleur de fond n'est pas toujours suffisant\n";
        $prompt .= "- Idéalement : outline, border, ou changement visuel marqué\n\n";
        $prompt .= "Pour CHAQUE paire de screenshots (avant/après focus) :\n";
        $prompt .= "1. Compare visuellement les deux états\n";
        $prompt .= "2. Évalue si la différence est suffisamment visible\n";
        $prompt .= "3. Indique si c'est problématique (hasIssue: true) ou acceptable (hasIssue: false)\n\n";

        // Build parts with before/after screenshots
        $parts = [['text' => $prompt]];

        foreach ($elements as $index => $el) {
            $parts[] = ['text' => "--- Élément #{$index}: {$el['type']} \"{$el['text']}\" ---\n"];

            // Before focus
            $parts[] = ['text' => "État SANS focus:\n"];
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data' => $el['screenshotBefore']
                ]
            ];

            // After focus
            $parts[] = ['text' => "État AVEC focus:\n"];
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data' => $el['screenshotAfter']
                ]
            ];

            $parts[] = ['text' => "\n"];
        }

        // Add response format
        $parts[] = [
            'text' => "\nRéponds UNIQUEMENT avec un JSON valide :\n" .
                     "[\n" .
                     "  {\n" .
                     "    \"elementIndex\": 0,\n" .
                     "    \"hasIssue\": true|false,\n" .
                     "    \"issue\": \"description du problème\" | null,\n" .
                     "    \"suggestion\": \"suggestion d'amélioration (outline, border, changement visible)\" | null,\n" .
                     "    \"confidence\": 0.0-1.0\n" .
                     "  }\n" .
                     "]\n"
        ];

        // Call API
        $response = $this->callGeminiAPI($parts);

        // Map results
        return $this->mapResultsToElements($elements, $response, ContextualAnalysisType::FOCUS_VISIBLE);
    }

    /**
     * Analyze media transcription availability (RGAA 4.1)
     */
    private function analyzeMediaTranscription(array $elements): array
    {
        if (empty($elements)) {
            return [];
        }

        $this->logger->info("Analyzing " . count($elements) . " media elements for transcription");

        // Build prompt
        $prompt = "Tu es un expert en accessibilité web RGAA.\n\n";
        $prompt .= "⚠️ CONTEXTE : Les tests automatiques ont détecté des éléments audio/vidéo sur la page.\n";
        $prompt .= "Ton rôle est d'analyser si une TRANSCRIPTION TEXTUELLE est disponible et accessible.\n\n";
        $prompt .= "🎯 FOCUS : Transcription textuelle des médias (RGAA 4.1 / WCAG 1.2.1)\n\n";
        $prompt .= "Pour CHAQUE média, vérifie :\n";
        $prompt .= "1. Y a-t-il un lien ou bouton \"Transcription\" / \"Transcript\" visible ?\n";
        $prompt .= "2. Le texte de transcription est-il présent à proximité du média ?\n";
        $prompt .= "3. Y a-t-il un attribut <track kind=\"descriptions\"> ?\n";
        $prompt .= "4. Le contexte suggère-t-il une transcription disponible ?\n\n";

        // Build parts with screenshots
        $parts = [['text' => $prompt]];

        foreach ($elements as $index => $el) {
            // Add screenshot
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data' => $el['screenshot']
                ]
            ];

            // Add media info
            $parts[] = [
                'text' => "---\n" .
                         "Média #{$index}: Type: {$el['type']} ({$el['tagName']})\n" .
                         "Source: {$el['src']}\n" .
                         "Tracks: " . ($el['tracks'] ?: 'aucun') . "\n" .
                         "Aria-label: " . ($el['ariaLabel'] ?? 'aucun') . "\n" .
                         "Contexte environnant: {$el['surroundingContext']}\n\n"
            ];
        }

        // Add response format
        $parts[] = [
            'text' => "\nRéponds UNIQUEMENT avec un JSON valide :\n" .
                     "[\n" .
                     "  {\n" .
                     "    \"elementIndex\": 0,\n" .
                     "    \"hasIssue\": true|false,\n" .
                     "    \"issue\": \"description du problème\" | null,\n" .
                     "    \"suggestion\": \"suggestion d'amélioration (ajouter lien transcription, <track>)\" | null,\n" .
                     "    \"confidence\": 0.0-1.0\n" .
                     "  }\n" .
                     "]\n"
        ];

        // Call API
        $response = $this->callGeminiAPI($parts);

        // Map results
        return $this->mapResultsToElements($elements, $response, ContextualAnalysisType::MEDIA_TRANSCRIPTION);
    }

    /**
     * Analyze keyboard shortcuts documentation (RGAA 12.9)
     */
    private function analyzeKeyboardShortcuts(array $elements): array
    {
        if (empty($elements)) {
            return [];
        }

        $this->logger->info("Analyzing keyboard shortcuts documentation");

        // Build prompt
        $prompt = "Tu es un expert en accessibilité web RGAA.\n\n";
        $prompt .= "⚠️ CONTEXTE : Les tests automatiques ont détecté des raccourcis clavier implémentés sur la page.\n";
        $prompt .= "Ton rôle est d'analyser si ces raccourcis sont DOCUMENTÉS et ACCESSIBLES.\n\n";
        $prompt .= "🎯 FOCUS : Documentation des raccourcis clavier (RGAA 12.9 / WCAG 2.1.4)\n\n";
        $prompt .= "Pour CHAQUE raccourci détecté, vérifie :\n";
        $prompt .= "1. Est-il documenté quelque part sur la page (aide, info-bulle, menu) ?\n";
        $prompt .= "2. Y a-t-il un attribut aria-keyshortcuts ?\n";
        $prompt .= "3. Le raccourci est-il visible dans l'interface (ex: \"Ctrl+S\" affiché) ?\n";
        $prompt .= "4. Y a-t-il une page d'aide accessible listant tous les raccourcis ?\n\n";

        // Build parts with screenshots
        $parts = [['text' => $prompt]];

        foreach ($elements as $index => $el) {
            // Add screenshot if available
            if (!empty($el['screenshot'])) {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => 'image/jpeg',
                        'data' => $el['screenshot']
                    ]
                ];
            }

            // Add shortcut info
            $parts[] = [
                'text' => "---\n" .
                         "Raccourci #{$index}: {$el['key']}\n" .
                         "Élément cible: {$el['targetElement']}\n" .
                         "Action: {$el['action']}\n" .
                         "Aria-keyshortcuts: " . ($el['ariaKeyshortcuts'] ?? 'aucun') . "\n" .
                         "Contexte page: {$el['pageContext']}\n\n"
            ];
        }

        // Add response format
        $parts[] = [
            'text' => "\nRéponds UNIQUEMENT avec un JSON valide :\n" .
                     "[\n" .
                     "  {\n" .
                     "    \"elementIndex\": 0,\n" .
                     "    \"hasIssue\": true|false,\n" .
                     "    \"issue\": \"description du problème\" | null,\n" .
                     "    \"suggestion\": \"suggestion d'amélioration (documenter, aria-keyshortcuts, page aide)\" | null,\n" .
                     "    \"confidence\": 0.0-1.0\n" .
                     "  }\n" .
                     "]\n"
        ];

        // Call API
        $response = $this->callGeminiAPI($parts);

        // Map results
        return $this->mapResultsToElements($elements, $response, ContextualAnalysisType::KEYBOARD_SHORTCUTS);
    }

    /**
     * Analyze focus management by scripts (RGAA 7.2)
     */
    private function analyzeFocusManagementScripts(array $elements): array
    {
        if (empty($elements)) {
            return [];
        }

        $this->logger->info("Analyzing " . count($elements) . " dynamic elements for focus management");

        // Build prompt
        $prompt = "Tu es un expert en accessibilité web RGAA.\n\n";
        $prompt .= "⚠️ CONTEXTE : Les tests automatiques ont détecté des éléments qui apparaissent/disparaissent dynamiquement.\n";
        $prompt .= "Ton rôle est d'analyser si le FOCUS est CORRECTEMENT GÉRÉ lors de ces changements.\n\n";
        $prompt .= "🎯 FOCUS : Gestion du focus par scripts (RGAA 7.2 / WCAG 2.4.3)\n\n";
        $prompt .= "Pour CHAQUE élément dynamique, vérifie :\n";
        $prompt .= "1. Quand l'élément apparaît, le focus est-il déplacé dessus automatiquement (pour modales) ?\n";
        $prompt .= "2. Le focus reste-t-il piégé dans l'élément tant qu'il est ouvert ?\n";
        $prompt .= "3. Quand l'élément disparaît, le focus retourne-t-il à l'élément déclencheur ?\n";
        $prompt .= "4. Le contexte de focus est-il logique et prévisible ?\n\n";

        // Build parts
        $parts = [['text' => $prompt]];

        foreach ($elements as $index => $el) {
            $parts[] = [
                'text' => "---\n" .
                         "Élément #{$index}: {$el['type']}\n" .
                         "Sélecteur: {$el['selector']}\n" .
                         "Déclencheur: {$el['trigger']}\n" .
                         "Focus après ouverture: {$el['focusAfterOpen']}\n" .
                         "Focus après fermeture: {$el['focusAfterClose']}\n" .
                         "Attributs ARIA: {$el['ariaAttributes']}\n\n"
            ];
        }

        // Add response format
        $parts[] = [
            'text' => "\nRéponds UNIQUEMENT avec un JSON valide :\n" .
                     "[\n" .
                     "  {\n" .
                     "    \"elementIndex\": 0,\n" .
                     "    \"hasIssue\": true|false,\n" .
                     "    \"issue\": \"description du problème\" | null,\n" .
                     "    \"suggestion\": \"suggestion d'amélioration (gérer focus programmatiquement)\" | null,\n" .
                     "    \"confidence\": 0.0-1.0\n" .
                     "  }\n" .
                     "]\n"
        ];

        // Call API
        $response = $this->callGeminiAPI($parts);

        // Map results
        return $this->mapResultsToElements($elements, $response, ContextualAnalysisType::FOCUS_MANAGEMENT_SCRIPTS);
    }

    /**
     * Analyze keyboard trap (RGAA 12.10)
     */
    private function analyzeKeyboardTrap(array $elements): array
    {
        if (empty($elements)) {
            return [];
        }

        $this->logger->info("Analyzing " . count($elements) . " modals/overlays for keyboard traps");

        // Build prompt
        $prompt = "Tu es un expert en accessibilité web RGAA.\n\n";
        $prompt .= "⚠️ CONTEXTE : Les tests automatiques ont détecté des modales/overlays.\n";
        $prompt .= "Ton rôle est d'analyser s'il existe des PIÈGES AU CLAVIER.\n\n";
        $prompt .= "�� FOCUS : Piège au clavier (RGAA 12.10 / WCAG 2.1.2)\n\n";
        $prompt .= "Pour CHAQUE modale/overlay, vérifie :\n";
        $prompt .= "1. Peut-on sortir de l'élément avec Tab/Shift+Tab sans piège ?\n";
        $prompt .= "2. La touche Échap (Esc) ferme-t-elle la modale ?\n";
        $prompt .= "3. Y a-t-il un bouton de fermeture accessible au clavier ?\n";
        $prompt .= "4. Le focus est-il correctement géré (retour à l'élément déclencheur) ?\n\n";

        // Build parts
        $parts = [['text' => $prompt]];

        foreach ($elements as $index => $el) {
            $parts[] = [
                'text' => "---\n" .
                         "Modale/Overlay #{$index}: {$el['type']}\n" .
                         "Sélecteur: {$el['selector']}\n" .
                         "Peut naviguer hors avec Tab: {$el['canTabOut']}\n" .
                         "Esc ferme la modale: {$el['escCloses']}\n" .
                         "Bouton fermer visible: {$el['closeButtonVisible']}\n" .
                         "Role: {$el['role']}\n" .
                         "Aria-modal: {$el['ariaModal']}\n\n"
            ];
        }

        // Add response format
        $parts[] = [
            'text' => "\nRéponds UNIQUEMENT avec un JSON valide :\n" .
                     "[\n" .
                     "  {\n" .
                     "    \"elementIndex\": 0,\n" .
                     "    \"hasIssue\": true|false,\n" .
                     "    \"issue\": \"description du problème\" | null,\n" .
                     "    \"suggestion\": \"suggestion d'amélioration (ajouter Esc, gestion Tab)\" | null,\n" .
                     "    \"confidence\": 0.0-1.0\n" .
                     "  }\n" .
                     "]\n"
        ];

        // Call API
        $response = $this->callGeminiAPI($parts);

        // Map results
        return $this->mapResultsToElements($elements, $response, ContextualAnalysisType::KEYBOARD_TRAP);
    }

    /**
     * Analyze additional content on hover/focus (RGAA 10.13, 13.9)
     */
    private function analyzeAdditionalContentHover(array $elements): array
    {
        if (empty($elements)) {
            return [];
        }

        $this->logger->info("Analyzing " . count($elements) . " tooltips/popovers for accessibility");

        // Build prompt
        $prompt = "Tu es un expert en accessibilité web RGAA.\n\n";
        $prompt .= "⚠️ CONTEXTE : Les tests automatiques ont détecté des tooltips/popovers.\n";
        $prompt .= "Ton rôle est d'analyser si ces contenus additionnels sont ACCESSIBLES.\n\n";
        $prompt .= "🎯 FOCUS : Contenus additionnels au survol/focus (RGAA 10.13, 13.9 / WCAG 1.4.13)\n\n";
        $prompt .= "Pour CHAQUE tooltip/popover, vérifie :\n";
        $prompt .= "1. Le contenu est-il DISMISSIBLE (Esc pour fermer) ?\n";
        $prompt .= "2. Le contenu PERSISTE au survol de la souris dessus ?\n";
        $prompt .= "3. Le contenu est-il HOVERABLE (on peut déplacer le curseur dessus) ?\n";
        $prompt .= "4. Le contenu est-il accessible au clavier (pas que au survol souris) ?\n\n";

        // Build parts with screenshots
        $parts = [['text' => $prompt]];

        foreach ($elements as $index => $el) {
            // Add screenshot if available
            if (!empty($el['screenshot'])) {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => 'image/jpeg',
                        'data' => $el['screenshot']
                    ]
                ];
            }

            $parts[] = [
                'text' => "---\n" .
                         "Tooltip/Popover #{$index}: {$el['type']}\n" .
                         "Déclencheur: {$el['trigger']}\n" .
                         "Méthode d'affichage: {$el['displayMethod']}\n" .
                         "Dismissible avec Esc: {$el['dismissibleEsc']}\n" .
                         "Persiste au survol: {$el['persistsOnHover']}\n" .
                         "Accessible clavier: {$el['keyboardAccessible']}\n\n"
            ];
        }

        // Add response format
        $parts[] = [
            'text' => "\nRéponds UNIQUEMENT avec un JSON valide :\n" .
                     "[\n" .
                     "  {\n" .
                     "    \"elementIndex\": 0,\n" .
                     "    \"hasIssue\": true|false,\n" .
                     "    \"issue\": \"description du problème\" | null,\n" .
                     "    \"suggestion\": \"suggestion d'amélioration (ajouter Esc, hover persistant)\" | null,\n" .
                     "    \"confidence\": 0.0-1.0\n" .
                     "  }\n" .
                     "]\n"
        ];

        // Call API
        $response = $this->callGeminiAPI($parts);

        // Map results
        return $this->mapResultsToElements($elements, $response, ContextualAnalysisType::ADDITIONAL_CONTENT_HOVER);
    }

    /**
     * Analyze navigation systems (RGAA 12.1)
     */
    private function analyzeNavigationSystems(array $systems): array
    {
        if (empty($systems)) {
            return [];
        }

        $this->logger->info("Analyzing navigation systems");

        // Build prompt
        $prompt = "Tu es un expert en accessibilité web RGAA.\n\n";
        $prompt .= "⚠️ CONTEXTE : Les tests automatiques ont détecté des systèmes de navigation sur la page.\n";
        $prompt .= "Ton rôle est de COMPTER et VALIDER qu'il y a AU MOINS 2 systèmes de navigation DIFFÉRENTS.\n\n";
        $prompt .= "🎯 FOCUS : Systèmes de navigation multiples (RGAA 12.1 / WCAG 2.4.5)\n\n";
        $prompt .= "Systèmes de navigation reconnus :\n";
        $prompt .= "1. Menu de navigation principal (<nav>)\n";
        $prompt .= "2. Plan du site (sitemap)\n";
        $prompt .= "3. Moteur de recherche\n";
        $prompt .= "4. Fil d'Ariane (breadcrumb)\n";
        $prompt .= "5. Table des matières (pour pages longues)\n\n";
        $prompt .= "RÈGLE : Il doit y avoir AU MOINS 2 de ces systèmes sur la page.\n\n";

        // Build parts
        $parts = [['text' => $prompt]];

        // Add detected systems info
        $parts[] = [
            'text' => "--- Systèmes détectés sur la page ---\n" .
                     "Nombre de systèmes trouvés: " . count($systems) . "\n\n"
        ];

        foreach ($systems as $index => $system) {
            $parts[] = [
                'text' => "Système #{$index}: {$system['type']}\n" .
                         "Sélecteur: {$system['selector']}\n" .
                         "Description: {$system['description']}\n" .
                         "Visible: {$system['visible']}\n\n"
            ];
        }

        // Add response format
        $parts[] = [
            'text' => "\nRéponds UNIQUEMENT avec un JSON valide :\n" .
                     "[\n" .
                     "  {\n" .
                     "    \"systemIndex\": 0,\n" .
                     "    \"hasIssue\": true|false,\n" .
                     "    \"issue\": \"Il manque X systèmes de navigation (total trouvé: Y, requis: 2)\" | null,\n" .
                     "    \"suggestion\": \"Ajouter un moteur de recherche / plan du site / fil d'Ariane\" | null,\n" .
                     "    \"confidence\": 0.0-1.0\n" .
                     "  }\n" .
                     "]\n" .
                     "Note: Si moins de 2 systèmes sont trouvés, hasIssue doit être true.\n"
        ];

        // Call API
        $response = $this->callGeminiAPI($parts);

        // Map results
        return $this->mapResultsToElements($systems, $response, ContextualAnalysisType::NAVIGATION_SYSTEMS, 'systemIndex');
    }
}
