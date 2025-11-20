<?php

namespace App\Service;

use App\Entity\ActionPlan;
use App\Entity\ActionPlanItem;
use App\Entity\AuditCampaign;
use App\Enum\ActionCategory;
use App\Enum\ActionSeverity;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ActionPlanService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $geminiApiKey,
        private string $geminiApiUrl
    ) {
    }

    /**
     * Generate a multi-year action plan from campaign audit results
     */
    public function generateActionPlan(AuditCampaign $campaign, int $durationYears = 2): ActionPlan
    {
        $this->logger->info('Generating action plan for campaign', ['campaign_id' => $campaign->getId()]);

        // Collect all issues from completed audits
        $allIssues = $this->collectIssuesFromCampaign($campaign);

        // Create action plan entity
        $actionPlan = new ActionPlan();
        $actionPlan->setCampaign($campaign);
        $actionPlan->setName($this->cleanText("Plan d'action {$campaign->getName()}"));
        $actionPlan->setDescription($this->cleanText("Plan pluriannuel de mise en conformite RGAA sur {$durationYears} ans"));
        $actionPlan->setStartDate(new \DateTime());
        $actionPlan->setEndDate((new \DateTime())->modify("+{$durationYears} years"));
        $actionPlan->setDurationYears($durationYears);
        $actionPlan->setCurrentConformityRate($campaign->getAvgConformityRate());
        $actionPlan->setTotalIssues($campaign->getTotalIssues());
        $actionPlan->setCriticalIssues($campaign->getCriticalCount());
        $actionPlan->setMajorIssues($campaign->getMajorCount());
        $actionPlan->setMinorIssues($campaign->getMinorCount());

        // Calculate target conformity rate
        $currentRate = (float) ($campaign->getAvgConformityRate() ?? 0);
        $targetRate = min(100, $currentRate + (50 * $durationYears)); // Aim for +50% per year
        $actionPlan->setTargetConformityRate((string) $targetRate);

        // Generate executive summary using Gemini AI
        $executiveSummary = $this->generateExecutiveSummary($campaign, $allIssues, $durationYears);
        $actionPlan->setExecutiveSummary($executiveSummary);

        // Generate action items from issues
        $this->generateActionItems($actionPlan, $allIssues, $durationYears);

        // Persist action plan
        $this->entityManager->persist($actionPlan);
        $this->entityManager->flush();

        $this->logger->info('Action plan generated successfully', [
            'action_plan_id' => $actionPlan->getId(),
            'items_count' => $actionPlan->getItems()->count()
        ]);

        return $actionPlan;
    }

    /**
     * Collect all issues from campaign audits with intelligent grouping
     */
    private function collectIssuesFromCampaign(AuditCampaign $campaign): array
    {
        $issues = [
            'critical' => [],
            'major' => [],
            'minor' => []
        ];

        foreach ($campaign->getPageAudits() as $audit) {
            if ($audit->getStatus() !== \App\Enum\AuditStatus::COMPLETED) {
                continue;
            }

            foreach ($audit->getAuditResults() as $result) {
                $severity = $result->getSeverity();
                if (!in_array($severity, ['critical', 'major', 'minor'])) {
                    $severity = 'minor';
                }

                // Normalize RGAA criteria to group similar issues
                $rgaaCriteria = $this->normalizeRgaaCriteria($result->getRgaaCriteria());

                // Group by RGAA criteria + error type for better consolidation
                $key = $rgaaCriteria . '_' . $this->normalizeErrorType($result->getErrorType());

                if (!isset($issues[$severity][$key])) {
                    $issues[$severity][$key] = [
                        'errorType' => $result->getErrorType(),
                        'rgaaCriteria' => $rgaaCriteria,
                        'wcagCriteria' => $result->getWcagCriteria(),
                        'description' => $result->getDescription(),
                        'recommendation' => $result->getRecommendation(),
                        'impactUser' => $result->getImpactUser() ?? 'Impact sur l\'accessibilité',
                        'occurrences' => 0,
                        'affectedPages' => [],
                        'complexity' => $this->estimateComplexity($result->getErrorType(), $result->getRecommendation())
                    ];
                }

                $issues[$severity][$key]['occurrences']++;
                if (!in_array($audit->getUrl(), $issues[$severity][$key]['affectedPages'])) {
                    $issues[$severity][$key]['affectedPages'][] = $audit->getUrl();
                }
            }
        }

        return $issues;
    }

    /**
     * Normalize RGAA criteria to 2 levels (theme.criterion)
     */
    private function normalizeRgaaCriteria(string $criteria): string
    {
        if (preg_match('/^(\d+)\.(\d+)/', $criteria, $matches)) {
            return $matches[1] . '.' . $matches[2];
        }
        return $criteria;
    }

    /**
     * Normalize error type for better grouping
     */
    private function normalizeErrorType(string $errorType): string
    {
        // Remove numbers and special chars to group similar errors
        $normalized = preg_replace('/\d+/', '', $errorType);
        $normalized = preg_replace('/[^a-zA-Z\s]/', '', $normalized);
        return trim(strtolower($normalized));
    }

    /**
     * Estimate complexity based on error type and recommendation
     */
    private function estimateComplexity(string $errorType, ?string $recommendation): string
    {
        $lowComplexity = ['alt', 'label', 'title', 'aria-label', 'lang'];
        $highComplexity = ['structure', 'navigation', 'form', 'table', 'script', 'keyboard', 'focus'];

        $errorLower = strtolower($errorType);
        $recommendationLower = strtolower($recommendation ?? '');

        foreach ($highComplexity as $term) {
            if (str_contains($errorLower, $term) || str_contains($recommendationLower, $term)) {
                return 'high';
            }
        }

        foreach ($lowComplexity as $term) {
            if (str_contains($errorLower, $term)) {
                return 'low';
            }
        }

        return 'medium';
    }

    /**
     * Generate action plan items with intelligent distribution
     */
    private function generateActionItems(ActionPlan $actionPlan, array $issues, int $durationYears): void
    {
        $currentYear = (int) date('Y');
        $currentQuarter = (int) ceil(date('n') / 3);

        // Calculate total available quarters
        $totalQuarters = $durationYears * 4;
        $maxItemsPerQuarter = 8; // Realistic workload

        // Prioritize all issues with smart scoring
        $allPrioritizedIssues = $this->prioritizeIssues($issues);

        $quarterOffset = 0;
        $itemsInCurrentQuarter = 0;
        $priorityCounter = 1;

        foreach ($allPrioritizedIssues as $issueData) {
            $issue = $issueData['issue'];
            $severity = $issueData['severity'];
            $priorityScore = $issueData['priorityScore'];

            // Calculate target quarter
            $quarter = $currentQuarter + $quarterOffset;
            $year = $currentYear;

            // Handle year rollover
            while ($quarter > 4) {
                $quarter -= 4;
                $year++;
            }

            // Don't plan beyond duration
            if (($year - $currentYear) >= $durationYears) {
                break;
            }

            // Determine if it's a quick win (critical + low complexity + few occurrences)
            $isQuickWin = ($severity === ActionSeverity::CRITICAL) &&
                         ($issue['complexity'] === 'low') &&
                         ($issue['occurrences'] <= 5);

            // Create action item
            $item = $this->createActionItem(
                $actionPlan,
                $issue,
                $severity,
                $priorityCounter++,
                $year,
                $quarter,
                $isQuickWin,
                $this->categorizeIssue($issue)
            );
            $actionPlan->addItem($item);

            $itemsInCurrentQuarter++;

            // Move to next quarter if current is full
            if ($itemsInCurrentQuarter >= $maxItemsPerQuarter) {
                $quarterOffset++;
                $itemsInCurrentQuarter = 0;
            }
        }
    }

    /**
     * Prioritize issues using a smart scoring algorithm
     */
    private function prioritizeIssues(array $issues): array
    {
        $prioritized = [];

        foreach (['critical' => ActionSeverity::CRITICAL, 'major' => ActionSeverity::MAJOR, 'minor' => ActionSeverity::MINOR] as $severityKey => $severityEnum) {
            foreach ($issues[$severityKey] as $issue) {
                $priorityScore = $this->calculatePriorityScore($issue, $severityEnum);

                $prioritized[] = [
                    'issue' => $issue,
                    'severity' => $severityEnum,
                    'priorityScore' => $priorityScore
                ];
            }
        }

        // Sort by priority score (highest first)
        usort($prioritized, function($a, $b) {
            return $b['priorityScore'] <=> $a['priorityScore'];
        });

        return $prioritized;
    }

    /**
     * Calculate priority score (Impact vs Effort matrix)
     */
    private function calculatePriorityScore(array $issue, ActionSeverity $severity): float
    {
        // Base score from severity
        $severityWeight = match($severity) {
            ActionSeverity::CRITICAL => 100,
            ActionSeverity::MAJOR => 60,
            ActionSeverity::MINOR => 30,
        };

        // Impact multiplier based on affected pages
        $pageCount = count($issue['affectedPages']);
        $impactMultiplier = min(2.0, 1 + ($pageCount / 10));

        // Effort penalty based on complexity
        $effortPenalty = match($issue['complexity']) {
            'low' => 1.0,
            'medium' => 0.7,
            'high' => 0.4,
        };

        // Occurrence bonus (more occurrences = higher priority to fix once)
        $occurrenceBonus = min(20, $issue['occurrences'] * 2);

        return ($severityWeight * $impactMultiplier * $effortPenalty) + $occurrenceBonus;
    }

    /**
     * Create an action plan item from an issue with improved estimation
     */
    private function createActionItem(
        ActionPlan $actionPlan,
        array $issue,
        ActionSeverity $severity,
        int $priority,
        int $year,
        int $quarter,
        bool $quickWin,
        ActionCategory $category
    ): ActionPlanItem {
        $item = new ActionPlanItem();
        $item->setActionPlan($actionPlan);

        // Clean all text fields to avoid encoding issues
        $item->setTitle($this->cleanText($issue['errorType']));
        $item->setDescription($this->cleanText($issue['description']));
        $item->setSeverity($severity);
        $item->setPriority($priority);
        $item->setYear($year);
        $item->setQuarter($quarter);
        $item->setQuickWin($quickWin);
        $item->setCategory($category);

        // Improved effort estimation
        $estimatedEffort = $this->calculateEffort($issue, $severity);
        $item->setEstimatedEffort($estimatedEffort);

        // Calculate impact score based on pages affected and severity
        $impactScore = $this->calculateImpactScore($issue, $severity);
        $item->setImpactScore($impactScore);

        $item->setTechnicalDetails($this->cleanText($issue['recommendation']));
        $item->setAffectedPages(array_unique($issue['affectedPages']));
        $item->setRgaaCriteria([$issue['rgaaCriteria'], $issue['wcagCriteria']]);

        // Generate detailed acceptance criteria
        $acceptanceCriteria = $this->generateAcceptanceCriteria($issue);
        $item->setAcceptanceCriteria($acceptanceCriteria);

        return $item;
    }

    /**
     * Clean text to avoid encoding issues
     */
    private function cleanText(string $text): string
    {
        // Remove or replace problematic characters
        $text = str_replace([''', ''', '"', '"', '—', '–'], ["'", "'", '"', '"', '-', '-'], $text);
        // Remove emojis and special UTF-8 characters
        $text = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $text);
        // Ensure proper UTF-8 encoding
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        return $text;
    }

    /**
     * Calculate realistic effort in hours
     */
    private function calculateEffort(array $issue, ActionSeverity $severity): int
    {
        $baseEffort = $severity->getBaseEffort();

        // Complexity multiplier
        $complexityMultiplier = match($issue['complexity']) {
            'low' => 1.0,
            'medium' => 1.5,
            'high' => 2.5,
        };

        // Pages multiplier (diminishing returns)
        $pageCount = count($issue['affectedPages']);
        if ($pageCount <= 1) {
            $pageMultiplier = 1;
        } elseif ($pageCount <= 5) {
            $pageMultiplier = 1 + ($pageCount * 0.3); // +30% per page
        } else {
            $pageMultiplier = 2.5 + (($pageCount - 5) * 0.1); // Diminishing
        }

        // Occurrences factor (fixing one type of error across multiple instances)
        $occurrenceFactor = 1 + min(5, $issue['occurrences'] * 0.2);

        $totalEffort = $baseEffort * $complexityMultiplier * $pageMultiplier * $occurrenceFactor;

        // Round up and cap at reasonable maximum
        return min(160, (int) ceil($totalEffort)); // Max 4 weeks per item
    }

    /**
     * Calculate impact score (0-100)
     */
    private function calculateImpactScore(array $issue, ActionSeverity $severity): int
    {
        $baseScore = $severity->getImpactScore();

        // Boost score if affects multiple pages
        $pageCount = count($issue['affectedPages']);
        $pageBonus = min(20, $pageCount * 2);

        $score = $baseScore + $pageBonus;

        return min(100, $score);
    }

    /**
     * Generate detailed acceptance criteria
     */
    private function generateAcceptanceCriteria(array $issue): string
    {
        $criteria = [];
        $criteria[] = "- Conformite RGAA {$issue['rgaaCriteria']} respectee";
        $criteria[] = "- Correction appliquee sur " . count($issue['affectedPages']) . " page(s)";
        $criteria[] = "- Tests automatises d'accessibilite passent";
        $criteria[] = "- Validation manuelle avec lecteur d'ecran";
        $criteria[] = "- Documentation technique mise a jour";

        if ($issue['impactUser']) {
            // Remove accents and special characters from user impact
            $cleanImpact = $this->removeAccents(substr($issue['impactUser'], 0, 100));
            $criteria[] = "- Impact utilisateur valide : " . $cleanImpact;
        }

        return implode("\n", $criteria);
    }

    /**
     * Remove accents and special characters from text
     */
    private function removeAccents(string $text): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return str_replace(['`', '´', '^', '~'], '', $text);
    }

    /**
     * Categorize issue type with improved pattern matching
     */
    private function categorizeIssue(array $issue): ActionCategory
    {
        $errorType = strtolower($issue['errorType']);
        $description = strtolower($issue['description'] ?? '');
        $recommendation = strtolower($issue['recommendation'] ?? '');

        $allText = $errorType . ' ' . $description . ' ' . $recommendation;

        // Structural issues (HTML structure, semantics, landmarks)
        $structuralPatterns = [
            'heading', 'h1', 'h2', 'h3', 'landmark', 'region', 'main', 'nav', 'navigation',
            'header', 'footer', 'aside', 'article', 'section', 'semantic', 'structure',
            'hierarchy', 'outline', 'role', 'aria-role'
        ];

        // Content issues (alternative text, labels, links, buttons text)
        $contentPatterns = [
            'alt', 'alternative', 'label', 'aria-label', 'aria-labelledby', 'title',
            'link text', 'button text', 'image', 'img', 'text', 'content',
            'description', 'accessible name', 'legend', 'caption', 'figcaption'
        ];

        // Technical issues (contrast, colors, focus, keyboard, scripts)
        $technicalPatterns = [
            'contrast', 'color', 'focus', 'keyboard', 'tabindex', 'script', 'javascript',
            'css', 'style', 'interactive', 'click', 'hover', 'animation', 'autocomplete',
            'input type', 'aria-live', 'aria-hidden', 'display', 'visibility'
        ];

        // Training issues (process, organization, governance)
        $trainingPatterns = [
            'process', 'procedure', 'documentation', 'guide', 'policy', 'training',
            'team', 'workflow', 'validation', 'review'
        ];

        // Count matches for each category
        $scores = [
            'structural' => $this->countPatternMatches($allText, $structuralPatterns),
            'content' => $this->countPatternMatches($allText, $contentPatterns),
            'technical' => $this->countPatternMatches($allText, $technicalPatterns),
            'training' => $this->countPatternMatches($allText, $trainingPatterns),
        ];

        // Get category with highest score
        arsort($scores);
        $topCategory = array_key_first($scores);

        // Return matching category or default to TECHNICAL
        return match($topCategory) {
            'structural' => $scores['structural'] > 0 ? ActionCategory::STRUCTURAL : ActionCategory::TECHNICAL,
            'content' => $scores['content'] > 0 ? ActionCategory::CONTENT : ActionCategory::TECHNICAL,
            'training' => $scores['training'] > 0 ? ActionCategory::TRAINING : ActionCategory::TECHNICAL,
            default => ActionCategory::TECHNICAL,
        };
    }

    /**
     * Count pattern matches in text
     */
    private function countPatternMatches(string $text, array $patterns): int
    {
        $count = 0;
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Analyze category breakdown for summary
     */
    private function analyzeCategoryBreakdown(array $issues): string
    {
        $categories = [];

        foreach ($issues as $severityLevel => $issueList) {
            foreach ($issueList as $issue) {
                $category = $this->categorizeIssue($issue);
                $categoryLabel = $category->getLabel();

                if (!isset($categories[$categoryLabel])) {
                    $categories[$categoryLabel] = 0;
                }
                $categories[$categoryLabel]++;
            }
        }

        arsort($categories);
        $top3 = array_slice($categories, 0, 3, true);

        $parts = [];
        foreach ($top3 as $category => $count) {
            $parts[] = "$category ($count)";
        }

        return implode(', ', $parts);
    }

    /**
     * Generate executive summary using Gemini AI with improved prompt
     */
    private function generateExecutiveSummary(AuditCampaign $campaign, array $issues, int $durationYears): string
    {
        $criticalCount = count($issues['critical']);
        $majorCount = count($issues['major']);
        $minorCount = count($issues['minor']);
        $totalIssues = $criticalCount + $majorCount + $minorCount;

        $currentRate = (float) ($campaign->getAvgConformityRate() ?? 0);
        $targetRate = min(100, $currentRate + (50 * $durationYears));

        // Analyze main issue categories
        $categoryBreakdown = $this->analyzeCategoryBreakdown($issues);

        $prompt = <<<PROMPT
Tu es un expert en accessibilité web RGAA 4.1. Génère un résumé exécutif professionnel pour un plan d'action pluriannuel de mise en conformité RGAA.

**Contexte de l'audit :**
- Campagne : {$campaign->getName()}
- Pages auditées : {$campaign->getTotalPages()}
- Conformité actuelle : **{$currentRate}%**
- Objectif cible : **{$targetRate}%** d'ici {$durationYears} an(s)
- Problèmes identifiés : **{$totalIssues}** au total
  - {$criticalCount} critiques (bloquants)
  - {$majorCount} majeurs (impact significatif)
  - {$minorCount} mineurs (améliorations)
- Catégories principales : {$categoryBreakdown}

**Génère un résumé exécutif structuré :**

## État des lieux
(2-3 phrases décrivant la situation actuelle et les principaux enjeux identifiés)

## Objectifs stratégiques
(4-5 objectifs SMART basés sur les données ci-dessus, avec métriques précises)

## Approche par phases
(Décrire 3-4 phases concrètes avec timing et priorités, en commençant par les quick wins critiques)

## Retour sur investissement attendu
(4-5 bénéfices concrets : conformité légale, expérience utilisateur, SEO, image de marque, etc.)

**Ton :** Professionnel et factuel, orienté décideurs.
**Format :** Markdown avec titres niveau 2 (##).
**Longueur :** 350-400 mots maximum.
PROMPT;

        try {
            $response = $this->httpClient->request('POST', $this->geminiApiUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'key' => $this->geminiApiKey,
                ],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 800,
                    ]
                ],
            ]);

            $data = $response->toArray();
            $summary = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (empty($summary)) {
                return $this->getFallbackSummary($campaign, $criticalCount, $majorCount, $minorCount, $durationYears);
            }

            return $summary;
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate executive summary with Gemini', [
                'error' => $e->getMessage()
            ]);
            return $this->getFallbackSummary($campaign, $criticalCount, $majorCount, $minorCount, $durationYears);
        }
    }

    /**
     * Fallback summary if AI fails
     */
    private function getFallbackSummary(AuditCampaign $campaign, int $critical, int $major, int $minor, int $years): string
    {
        $currentRate = $campaign->getAvgConformityRate() ?? 0;
        $targetRate = min(100, $currentRate + (50 * $years));

        return <<<SUMMARY
## État des lieux

L'audit de conformité RGAA a révélé {$campaign->getTotalPages()} page(s) auditée(s) avec un taux de conformité actuel de **{$currentRate}%**. Au total, **{$campaign->getTotalIssues()} problèmes** ont été identifiés, dont {$critical} critiques, {$major} majeurs et {$minor} mineurs.

## Objectifs du plan ({$years} ans)

- ✅ Atteindre un taux de conformité de **{$targetRate}%** d'ici {$years} ans
- ✅ Corriger 100% des problèmes critiques en priorité (6 premiers mois)
- ✅ Implémenter un processus de test d'accessibilité systématique
- ✅ Former l'équipe aux bonnes pratiques RGAA

## Approche recommandée

### Phase 1 : Quick Wins (3-6 mois)
Correction des problèmes critiques à impact immédiat (textes alternatifs, contrastes, navigation clavier).

### Phase 2 : Améliorations structurelles (6-12 mois)
Refonte des composants non conformes, mise en place de patterns accessibles.

### Phase 3 : Conformité complète (Année 2+)
Corrections finales, audits de contrôle, certification.

## Bénéfices attendus

- 🎯 Conformité légale (obligation RGAA pour services publics)
- ♿ Accès universel pour tous les utilisateurs
- 📈 Amélioration du référencement SEO
- 💼 Image de marque inclusive et responsable
SUMMARY;
    }
}
