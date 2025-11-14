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
        $actionPlan->setName("Plan d'action {$campaign->getName()}");
        $actionPlan->setDescription("Plan pluriannuel de mise en conformité RGAA sur {$durationYears} ans");
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
     * Collect all issues from campaign audits grouped by type
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

                $errorType = $result->getErrorType();
                $key = $errorType . '_' . $result->getRgaaCriteria();

                if (!isset($issues[$severity][$key])) {
                    $issues[$severity][$key] = [
                        'errorType' => $errorType,
                        'rgaaCriteria' => $result->getRgaaCriteria(),
                        'wcagCriteria' => $result->getWcagCriteria(),
                        'description' => $result->getDescription(),
                        'recommendation' => $result->getRecommendation(),
                        'impactUser' => $result->getImpactUser(),
                        'occurrences' => 0,
                        'affectedPages' => []
                    ];
                }

                $issues[$severity][$key]['occurrences']++;
                $issues[$severity][$key]['affectedPages'][] = $audit->getUrl();
            }
        }

        return $issues;
    }

    /**
     * Generate action plan items from issues
     */
    private function generateActionItems(ActionPlan $actionPlan, array $issues, int $durationYears): void
    {
        $currentYear = (int) date('Y');
        $currentQuarter = (int) ceil(date('n') / 3);
        $priority = 1;

        // Phase 1: Quick wins - Critical issues (Q1)
        foreach ($issues['critical'] as $issue) {
            $item = $this->createActionItem(
                $actionPlan,
                $issue,
                ActionSeverity::CRITICAL,
                $priority++,
                $currentYear,
                $currentQuarter,
                true,
                ActionCategory::TECHNICAL
            );
            $actionPlan->addItem($item);
        }

        // Phase 2: High impact issues - Major issues (Q2-Q3)
        $quarterOffset = 1;
        foreach ($issues['major'] as $issue) {
            $quarter = $currentQuarter + $quarterOffset;
            $year = $currentYear;

            if ($quarter > 4) {
                $quarter -= 4;
                $year++;
            }

            $item = $this->createActionItem(
                $actionPlan,
                $issue,
                ActionSeverity::MAJOR,
                $priority++,
                $year,
                $quarter,
                false,
                $this->categorizeIssue($issue)
            );
            $actionPlan->addItem($item);

            $quarterOffset++;
            if ($quarterOffset > 2) $quarterOffset = 1; // Spread across Q2-Q3
        }

        // Phase 3: Long-term improvements - Minor issues (Year 2+)
        $yearOffset = 1;
        $quarterCycle = 1;
        foreach ($issues['minor'] as $issue) {
            $year = $currentYear + $yearOffset;
            $quarter = $quarterCycle;

            $item = $this->createActionItem(
                $actionPlan,
                $issue,
                ActionSeverity::MINOR,
                $priority++,
                $year,
                $quarter,
                false,
                $this->categorizeIssue($issue)
            );
            $actionPlan->addItem($item);

            $quarterCycle++;
            if ($quarterCycle > 4) {
                $quarterCycle = 1;
                $yearOffset++;
                if ($yearOffset >= $durationYears) {
                    break; // Don't plan beyond duration
                }
            }
        }
    }

    /**
     * Create an action plan item from an issue
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
        $item->setTitle($issue['errorType']);
        $item->setDescription($issue['description']);
        $item->setSeverity($severity);
        $item->setPriority($priority);
        $item->setYear($year);
        $item->setQuarter($quarter);
        $item->setQuickWin($quickWin);
        $item->setCategory($category);

        // Estimate effort based on occurrences and severity
        $baseEffort = $severity->getBaseEffort();
        $estimatedEffort = $baseEffort * min(10, $issue['occurrences']);
        $item->setEstimatedEffort($estimatedEffort);

        // Calculate impact score
        $impactScore = $severity->getImpactScore();
        $item->setImpactScore($impactScore);

        $item->setTechnicalDetails($issue['recommendation']);
        $item->setAffectedPages(array_unique($issue['affectedPages']));
        $item->setRgaaCriteria([$issue['rgaaCriteria'], $issue['wcagCriteria']]);
        $item->setAcceptanceCriteria("✅ Conformité {$issue['rgaaCriteria']}\n✅ Tests automatisés passent\n✅ Validation manuelle OK");

        return $item;
    }

    /**
     * Categorize issue type
     */
    private function categorizeIssue(array $issue): ActionCategory
    {
        $errorType = strtolower($issue['errorType']);

        if (str_contains($errorType, 'heading') || str_contains($errorType, 'landmark') || str_contains($errorType, 'semantic')) {
            return ActionCategory::STRUCTURAL;
        }

        if (str_contains($errorType, 'alt') || str_contains($errorType, 'label') || str_contains($errorType, 'link') || str_contains($errorType, 'button')) {
            return ActionCategory::CONTENT;
        }

        if (str_contains($errorType, 'contrast') || str_contains($errorType, 'color') || str_contains($errorType, 'focus')) {
            return ActionCategory::TECHNICAL;
        }

        return ActionCategory::TECHNICAL;
    }

    /**
     * Generate executive summary using Gemini AI
     */
    private function generateExecutiveSummary(AuditCampaign $campaign, array $issues, int $durationYears): string
    {
        $criticalCount = count($issues['critical']);
        $majorCount = count($issues['major']);
        $minorCount = count($issues['minor']);
        $totalIssues = $criticalCount + $majorCount + $minorCount;

        $prompt = <<<PROMPT
Tu es un expert en accessibilité web RGAA. Génère un résumé exécutif pour un plan d'action pluriannuel de mise en conformité RGAA.

**Contexte de la campagne d'audit :**
- Nom : {$campaign->getName()}
- Pages auditées : {$campaign->getTotalPages()}
- Taux de conformité actuel : {$campaign->getAvgConformityRate()}%
- Problèmes détectés : {$totalIssues} ({$criticalCount} critiques, {$majorCount} majeurs, {$minorCount} mineurs)

**Durée du plan :** {$durationYears} ans

**Génère un résumé exécutif structuré contenant :**
1. État des lieux (2-3 phrases)
2. Objectifs du plan (liste à puces, 3-4 objectifs SMART)
3. Approche recommandée (3-4 phases avec timing)
4. Bénéfices attendus (liste à puces, 3-4 bénéfices concrets)

Format : Markdown, professionnel, concis (maximum 300 mots).
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
