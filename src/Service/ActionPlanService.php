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
     * Generate a multi-year strategic action plan (PPA) and annual detailed plans from campaign audit results
     */
    public function generateActionPlan(AuditCampaign $campaign, int $durationYears = 2): ActionPlan
    {
        $this->logger->info('Generating PPA (Plan Pluriannuel) and annual action plans for campaign', ['campaign_id' => $campaign->getId()]);

        // Collect all issues from completed audits
        $allIssues = $this->collectIssuesFromCampaign($campaign);

        // Create PPA (Plan Pluriannuel d'Accessibilite) entity - STRATEGIC DOCUMENT
        $ppa = new ActionPlan();
        $ppa->setCampaign($campaign);
        $ppa->setName($this->cleanText("Plan Pluriannuel d'Accessibilite {$campaign->getName()}"));
        $ppa->setDescription($this->cleanText("Document strategique sur {$durationYears} ans"));
        $ppa->setStartDate(new \DateTime());
        $ppa->setEndDate((new \DateTime())->modify("+{$durationYears} years"));
        $ppa->setDurationYears($durationYears);
        $ppa->setCurrentConformityRate($campaign->getAvgConformityRate());
        $ppa->setTotalIssues($campaign->getTotalIssues());
        $ppa->setCriticalIssues($campaign->getCriticalCount());
        $ppa->setMajorIssues($campaign->getMajorCount());
        $ppa->setMinorIssues($campaign->getMinorCount());

        // Calculate target conformity rate
        $currentRate = (float) ($campaign->getAvgConformityRate() ?? 0);
        $targetRate = min(100, $currentRate + (50 * $durationYears)); // Aim for +50% per year
        $ppa->setTargetConformityRate((string) $targetRate);

        // Generate STRATEGIC executive summary using Gemini AI (NO technical details)
        $executiveSummary = $this->generateStrategicSummary($campaign, $allIssues, $durationYears);
        $ppa->setExecutiveSummary($executiveSummary);

        // Generate STRATEGIC content for PPA
        $this->generateStrategicContent($ppa, $campaign, $allIssues, $durationYears);

        // Persist PPA first
        $this->entityManager->persist($ppa);
        $this->entityManager->flush();

        // Generate annual action plans with DETAILED TECHNICAL content
        $this->generateAnnualActionPlans($ppa, $allIssues, $durationYears);

        $this->logger->info('PPA and annual plans generated successfully', [
            'ppa_id' => $ppa->getId(),
            'annual_plans_count' => $ppa->getAnnualPlans()->count()
        ]);

        return $ppa;
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

        // Calculate end year (duration is inclusive: 2 ans = 2025 to 2027 = 3 years to plan)
        $endYear = $currentYear + $durationYears;
        $totalQuarters = ($durationYears + 1) * 4; // +1 because duration is inclusive

        // Prioritize all issues with smart scoring
        $allPrioritizedIssues = $this->prioritizeIssues($issues);
        $totalIssues = count($allPrioritizedIssues);

        // Calculate average items per quarter to spread evenly across duration
        // But ensure we don't go below 2 or above 8 items per quarter
        $avgItemsPerQuarter = max(2, min(8, ceil($totalIssues / $totalQuarters)));

        // Separate quick wins (to be done first) from regular actions
        $quickWins = [];
        $regularActions = [];

        foreach ($allPrioritizedIssues as $issueData) {
            $issue = $issueData['issue'];
            $severity = $issueData['severity'];

            $isQuickWin = ($severity === ActionSeverity::CRITICAL) &&
                         ($issue['complexity'] === 'low') &&
                         ($issue['occurrences'] <= 5);

            if ($isQuickWin) {
                $quickWins[] = $issueData;
            } else {
                $regularActions[] = $issueData;
            }
        }

        // Reorder: quick wins first, then regular actions
        $orderedIssues = array_merge($quickWins, $regularActions);

        $quarterOffset = 0;
        $itemsInCurrentQuarter = 0;
        $priorityCounter = 1;

        foreach ($orderedIssues as $issueData) {
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

            // Don't plan beyond end year (inclusive)
            if ($year > $endYear) {
                break;
            }

            // Determine if it's a quick win
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

            // Move to next quarter when reaching target (spreads actions across duration)
            if ($itemsInCurrentQuarter >= $avgItemsPerQuarter) {
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
        ?ActionPlan $actionPlan,
        array $issue,
        ActionSeverity $severity,
        int $priority,
        int $year,
        int $quarter,
        bool $quickWin,
        ActionCategory $category
    ): ActionPlanItem {
        $item = new ActionPlanItem();
        if ($actionPlan !== null) {
            $item->setActionPlan($actionPlan);
        }

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
        // Remove or replace problematic characters (curly quotes, em dashes, etc)
        $text = str_replace(["\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x80\x94", "\xe2\x80\x93"], ["'", "'", '"', '"', '-', '-'], $text);
        // Remove emojis and special UTF-8 characters
        $text = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $text);
        // Ensure proper UTF-8 encoding
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        return $text;
    }

    /**
     * Calculate realistic effort in hours (IMPROVED - more realistic estimates)
     */
    private function calculateEffort(array $issue, ActionSeverity $severity): int
    {
        // Start with base effort
        $baseEffort = $severity->getBaseEffort(); // 8h (critical), 4h (major), 2h (minor)
        $totalEffort = $baseEffort;

        // Complexity adjustment (ADDITIVE, not multiplicative)
        $complexityAdjustment = match($issue['complexity']) {
            'low' => 0,           // No additional time
            'medium' => $baseEffort * 0.5,  // +50% of base
            'high' => $baseEffort * 1.0,    // +100% of base (double)
        };
        $totalEffort += $complexityAdjustment;

        // Pages adjustment (diminishing returns - templates help!)
        $pageCount = count($issue['affectedPages']);
        if ($pageCount > 1) {
            if ($pageCount <= 5) {
                // First 5 pages: +15% of base per page
                $pagesAdjustment = min(($pageCount - 1) * ($baseEffort * 0.15), $baseEffort * 0.75);
            } else {
                // More than 5 pages: +5% per additional page (templates/patterns)
                $pagesAdjustment = ($baseEffort * 0.75) + (($pageCount - 5) * ($baseEffort * 0.05));
            }
            $totalEffort += $pagesAdjustment;
        }

        // Occurrences bonus (many occurrences = fix once, apply everywhere)
        // More occurrences actually means LESS time per occurrence (economy of scale)
        if ($issue['occurrences'] > 10) {
            // Lots of same error = probably a pattern/template fix
            $occurrencesAdjustment = $baseEffort * 0.2; // +20% only
        } elseif ($issue['occurrences'] > 5) {
            $occurrencesAdjustment = $baseEffort * 0.3; // +30%
        } else {
            $occurrencesAdjustment = $issue['occurrences'] * ($baseEffort * 0.1); // +10% per occurrence
        }
        $totalEffort += $occurrencesAdjustment;

        // Round to nearest hour and cap at reasonable maximum
        $totalEffort = (int) round($totalEffort);
        return min(40, $totalEffort); // Max 1 week (40h) per action - more realistic!
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
     * Generate STRATEGIC content for the PPA (orientations, axes, moyens, indicateurs)
     */
    private function generateStrategicContent(ActionPlan $ppa, AuditCampaign $campaign, array $issues, int $durationYears): void
    {
        $currentYear = (int) date('Y');

        // Strategic Orientations (grandes orientations)
        $ppa->setStrategicOrientations([
            "Garantir l'egalite d'acces a nos services numeriques pour tous les utilisateurs",
            "Integrer l'accessibilite dans le processus de conception et developpement",
            "Former les equipes aux bonnes pratiques RGAA",
            "Mettre en place un suivi continu de la conformite"
        ]);

        // Progress Axes (axes de progres)
        $ppa->setProgressAxes([
            "Accessibilite des contenus" => "Amelioration des alternatives textuelles, de la structure semantique et de la navigation",
            "Accessibilite technique" => "Conformite des composants interactifs, du clavier et des contrastes",
            "Organisation et gouvernance" => "Processus de validation, documentation et formation continue",
            "Suivi et amelioration continue" => "Audits reguliers et correction systematique des non-conformites"
        ]);

        // Annual Objectives (objectifs annuels SANS details techniques)
        $annualObjectives = [];
        for ($i = 0; $i < $durationYears; $i++) {
            $year = $currentYear + $i;
            $objectiveYear = $i + 1;

            if ($objectiveYear === 1) {
                $annualObjectives[$year] = [
                    "Corriger 100% des erreurs critiques bloquantes",
                    "Traiter les quick wins a fort impact",
                    "Former l'equipe de developpement",
                    "Atteindre " . min(100, (int)$ppa->getCurrentConformityRate() + 30) . "% de conformite"
                ];
            } elseif ($objectiveYear === $durationYears) {
                $annualObjectives[$year] = [
                    "Finaliser les corrections restantes",
                    "Audit de conformite final",
                    "Atteindre " . $ppa->getTargetConformityRate() . "% de conformite",
                    "Mettre en place un processus de maintien de la conformite"
                ];
            } else {
                $annualObjectives[$year] = [
                    "Poursuivre les corrections structurelles",
                    "Optimiser les composants et templates",
                    "Renforcer la formation des equipes",
                    "Atteindre " . min(100, (int)$ppa->getCurrentConformityRate() + (30 * $objectiveYear)) . "% de conformite"
                ];
            }
        }
        $ppa->setAnnualObjectives($annualObjectives);

        // Resources (moyens)
        $ppa->setResources([
            "Equipe de developpement formee aux bonnes pratiques RGAA",
            "Outils d'audit automatise et manuel",
            "Accompagnement par des experts en accessibilite",
            "Integration de tests d'accessibilite dans le CI/CD",
            "Documentation et guides internes"
        ]);

        // Indicators (indicateurs)
        $ppa->setIndicators([
            "Taux de conformite RGAA" => "Objectif : " . $ppa->getTargetConformityRate() . "%",
            "Nombre d'erreurs critiques" => "Objectif : 0 en fin d'annee 1",
            "Couverture des tests d'accessibilite" => "Objectif : 100% des composants",
            "Taux de formation des equipes" => "Objectif : 100% de l'equipe formee",
            "Delai moyen de correction" => "Objectif : < 1 mois pour critiques"
        ]);
    }

    /**
     * Generate annual action plans with DETAILED TECHNICAL items
     */
    private function generateAnnualActionPlans(ActionPlan $ppa, array $issues, int $durationYears): void
    {
        $currentYear = (int) date('Y');
        $currentQuarter = (int) ceil(date('n') / 3);

        // Prioritize all issues with smart scoring
        $allPrioritizedIssues = $this->prioritizeIssues($issues);
        $totalIssues = count($allPrioritizedIssues);

        // Calculate total quarters for distribution
        $totalQuarters = ($durationYears + 1) * 4;
        $avgItemsPerQuarter = max(2, min(8, ceil($totalIssues / $totalQuarters)));

        // Separate quick wins from regular actions
        $quickWins = [];
        $regularActions = [];

        foreach ($allPrioritizedIssues as $issueData) {
            $issue = $issueData['issue'];
            $severity = $issueData['severity'];

            $isQuickWin = ($severity === \App\Enum\ActionSeverity::CRITICAL) &&
                         ($issue['complexity'] === 'low') &&
                         ($issue['occurrences'] <= 5);

            if ($isQuickWin) {
                $quickWins[] = $issueData;
            } else {
                $regularActions[] = $issueData;
            }
        }

        // Reorder: quick wins first, then regular actions
        $orderedIssues = array_merge($quickWins, $regularActions);

        // Create annual plans for each year
        $annualPlansByYear = [];
        for ($i = 0; $i < $durationYears; $i++) {
            $year = $currentYear + $i;
            $annualPlan = new \App\Entity\AnnualActionPlan();
            $annualPlan->setPluriAnnualPlan($ppa);
            $annualPlan->setYear($year);
            $annualPlan->setTitle("Plan d'action annuel " . $year);
            $annualPlan->setDescription("Plan d'action operationnel detaille pour l'annee " . $year . " - contient les corrections techniques specifiques a realiser");

            $ppa->addAnnualPlan($annualPlan);
            $this->entityManager->persist($annualPlan);

            $annualPlansByYear[$year] = $annualPlan;
        }

        // Distribute action items across years/quarters
        $quarterOffset = 0;
        $itemsInCurrentQuarter = 0;
        $priorityCounter = 1;

        foreach ($orderedIssues as $issueData) {
            $issue = $issueData['issue'];
            $severity = $issueData['severity'];

            // Calculate target quarter
            $quarter = $currentQuarter + $quarterOffset;
            $year = $currentYear;

            // Handle year rollover
            while ($quarter > 4) {
                $quarter -= 4;
                $year++;
            }

            // Don't plan beyond end year
            $endYear = $currentYear + $durationYears;
            if ($year > $endYear) {
                break;
            }

            // Skip if year doesn't have an annual plan
            if (!isset($annualPlansByYear[$year])) {
                break;
            }

            // Determine if it's a quick win
            $isQuickWin = ($severity === \App\Enum\ActionSeverity::CRITICAL) &&
                         ($issue['complexity'] === 'low') &&
                         ($issue['occurrences'] <= 5);

            // Create DETAILED action item
            $item = $this->createActionItem(
                null, // No direct link to ActionPlan (deprecated)
                $issue,
                $severity,
                $priorityCounter++,
                $year,
                $quarter,
                $isQuickWin,
                $this->categorizeIssue($issue)
            );

            // Link to annual plan instead
            $annualPlansByYear[$year]->addItem($item);
            $this->entityManager->persist($item);

            $itemsInCurrentQuarter++;

            // Move to next quarter when reaching target
            if ($itemsInCurrentQuarter >= $avgItemsPerQuarter) {
                $quarterOffset++;
                $itemsInCurrentQuarter = 0;
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Generate STRATEGIC summary using Gemini AI (NO technical details, NO RGAA criteria)
     */
    private function generateStrategicSummary(AuditCampaign $campaign, array $issues, int $durationYears): string
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
Tu es un expert en accessibilite web RGAA 4.1. IMPORTANT : Tu generes un resume STRATEGIQUE pour un Plan Pluriannuel d'Accessibilite (PPA).

**IMPORTANT - Ce resume est pour le PPA (document strategique), PAS pour un plan d'action annuel :**
- NE MENTIONNE PAS de criteres RGAA specifiques (ex: RGAA 1.1.1, RGAA 4.1.2)
- NE MENTIONNE PAS d'erreurs A11yLint ou techniques detaillees
- NE MENTIONNE PAS de composants precis ou de tickets
- RESTE strategique : orientations, axes de progres, objectifs globaux

**Contexte de l'audit :**
- Campagne : {$campaign->getName()}
- Pages auditees : {$campaign->getTotalPages()}
- Conformite actuelle : **{$currentRate}%**
- Objectif cible : **{$targetRate}%** d'ici {$durationYears} an(s)
- Problemes identifies : **{$totalIssues}** types d'erreurs
  - {$criticalCount} erreurs critiques (bloquants accessibilite)
  - {$majorCount} erreurs majeures (impact significatif)
  - {$minorCount} erreurs mineures (ameliorations recommandees)
- Domaines concernes : {$categoryBreakdown}

**Genere un resume strategique structure :**

## Vision et enjeux
(2-3 phrases sur l'importance de l'accessibilite pour l'organisation et les enjeux strategiques)

## Etat des lieux
(2 phrases decrivant la situation globale SANS details techniques)

## Grandes orientations strategiques
(3-4 orientations strategiques majeures sur {$durationYears} ans)

## Approche pluriannuelle
(Decrire l'approche generale en 3 phases sur {$durationYears} ans, SANS mentionner de criteres RGAA ou erreurs techniques :
- Phase 1 : Corrections prioritaires et formation
- Phase 2 : Ameliorations structurelles
- Phase 3 : Conformite complete et maintien)

## Benefices attendus
(4-5 benefices strategiques : conformite legale, experience utilisateur, image de marque, etc.)

**Ton :** Strategique, inspire, oriente vision et objectifs.
**Format :** Markdown avec titres niveau 2 (##).
**Longueur :** 250-300 mots maximum.
**EVITER ABSOLUMENT :** Criteres RGAA precis, erreurs A11yLint, composants techniques, estimations d'heures.
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
                return $this->getFallbackStrategicSummary($campaign, $criticalCount, $majorCount, $minorCount, $durationYears);
            }

            return $summary;
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate strategic summary with Gemini', [
                'error' => $e->getMessage()
            ]);
            return $this->getFallbackStrategicSummary($campaign, $criticalCount, $majorCount, $minorCount, $durationYears);
        }
    }

    /**
     * Fallback strategic summary if AI fails
     */
    private function getFallbackStrategicSummary(AuditCampaign $campaign, int $critical, int $major, int $minor, int $years): string
    {
        $currentRate = $campaign->getAvgConformityRate() ?? 0;
        $targetRate = min(100, $currentRate + (50 * $years));

        return <<<SUMMARY
## Vision et enjeux

L'accessibilite numerique est un pilier fondamental de notre engagement en faveur de l'inclusion. Notre organisation s'engage a garantir un acces egal a ses services numeriques pour tous les utilisateurs, quelles que soient leurs capacites.

## Etat des lieux

L'audit de conformite RGAA revele un taux de conformite actuel de **{$currentRate}%**. Des opportunites d'amelioration ont ete identifiees dans plusieurs domaines cles de l'accessibilite.

## Grandes orientations strategiques

1. **Conformite reglementaire** : Atteindre **{$targetRate}%** de conformite RGAA d'ici {$years} an(s)
2. **Formation et montee en competences** : Former l'ensemble des equipes aux bonnes pratiques d'accessibilite
3. **Integration dans les processus** : Integrer l'accessibilite des la phase de conception
4. **Amelioration continue** : Mettre en place un processus de suivi et d'amelioration continue

## Approche pluriannuelle

**Phase 1 : Corrections prioritaires et formation** (6-12 premiers mois)
Traitement des problemes bloquants, formation des equipes, mise en place des processus.

**Phase 2 : Ameliorations structurelles** (12-18 mois)
Refonte des composants, amelioration de la structure, optimisation de l'experience utilisateur.

**Phase 3 : Conformite complete et maintien** (derniers {$years - 1} mois)
Finalisation des corrections, audit de conformite, mise en place du maintien en condition operationnelle.

## Benefices attendus

- **Conformite legale** : Respect des obligations RGAA pour les services publics
- **Inclusion universelle** : Acces garanti pour tous les utilisateurs, y compris en situation de handicap
- **Amelioration de l'experience** : Navigation simplifiee beneficiant a l'ensemble des utilisateurs
- **Image de marque** : Demonstration de l'engagement societal de l'organisation
- **Performance SEO** : Amelioration du referencement naturel grace a une meilleure structure
SUMMARY;
    }

    /**
     * Generate executive summary using Gemini AI with improved prompt
     * @deprecated Use generateStrategicSummary instead
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
- Problèmes identifiés : **{$totalIssues}** types d'erreurs différentes
  - {$criticalCount} erreurs critiques (bloquants accessibilité)
  - {$majorCount} erreurs majeures (impact significatif)
  - {$minorCount} erreurs mineures (améliorations recommandées)
- Catégories principales : {$categoryBreakdown}

**IMPORTANT pour les estimations :**
- Les estimations doivent être RÉALISTES et basées sur des heures de développement effectives
- Beaucoup d'erreurs du même type = correction unique dans un composant/template réutilisable
- Une erreur "critique" simple (ex: alt manquant) = 30 minutes à 2h, PAS des jours
- Une erreur "structurelle" complexe = 1 à 3 jours maximum
- Prioriser les "quick wins" : corrections rapides à fort impact
- Regrouper les corrections par composant/page pour optimiser l'effort

**Génère un résumé exécutif structuré :**

## État des lieux
(2-3 phrases décrivant la situation actuelle, en mettant l'accent sur les opportunités d'amélioration plutôt que sur une vision négative)

## Objectifs stratégiques
(3-4 objectifs SMART basés sur les données, SANS mentionner de chiffres d'heures ou de budget - rester stratégique)

## Approche par phases
(Décrire 3 phases concrètes avec timing :
- Phase 1: Quick wins (1-3 mois) - corrections simples à fort impact
- Phase 2: Corrections structurelles (3-8 mois) - composants et templates
- Phase 3: Optimisation et conformité (derniers mois) - finitions et tests)

## Retour sur investissement attendu
(4-5 bénéfices concrets : conformité légale, expérience utilisateur améliorée, SEO, réduction des risques juridiques, image de marque inclusive)

**Ton :** Professionnel, factuel et OPTIMISTE. Orienté solutions, pas problèmes.
**Format :** Markdown avec titres niveau 2 (##).
**Longueur :** 300-350 mots maximum.
**ÉVITER :** Estimations d'heures/budget, jargon technique excessif, ton alarmiste.
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
