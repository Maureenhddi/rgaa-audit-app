<?php

namespace App\Command;

use App\Repository\AuditRepository;
use App\Repository\AuditResultRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:recalculate-last-audit',
    description: 'Recalculate conformity rate and RGAA mapping for the last audit'
)]
class RecalculateLastAuditCommand extends Command
{
    public function __construct(
        private AuditRepository $auditRepository,
        private AuditResultRepository $auditResultRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private string $projectDir
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🔄 Recalculating Last Audit');

        // Get the last completed audit
        $audit = $this->auditRepository->findOneBy(
            ['status' => 'completed'],
            ['createdAt' => 'DESC']
        );

        if (!$audit) {
            $io->warning('No completed audit found in database.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found audit #%d: %s (created %s)',
            $audit->getId(),
            $audit->getUrl(),
            $audit->getCreatedAt()->format('Y-m-d H:i:s')
        ));

        $io->section('Step 1: Updating RGAA criteria mapping');

        // Load RGAA error mapping
        $mappingFile = $this->projectDir . '/config/error_to_rgaa_mapping.json';
        if (!file_exists($mappingFile)) {
            $io->error('RGAA error mapping file not found: ' . $mappingFile);
            return Command::FAILURE;
        }

        $mappingData = json_decode(file_get_contents($mappingFile), true);
        if (!isset($mappingData['mappings'])) {
            $io->error('Invalid RGAA error mapping file format');
            return Command::FAILURE;
        }

        // Filter out comment keys
        $errorToRgaaMap = array_filter($mappingData['mappings'], function($key) {
            return strpos($key, '_') !== 0;
        }, ARRAY_FILTER_USE_KEY);

        // Update RGAA criteria for all results
        $results = $audit->getAuditResults();
        $updatedResults = 0;

        foreach ($results as $result) {
            $errorType = $result->getErrorType();
            $oldRgaa = $result->getRgaaCriteria();

            // Try to find RGAA criteria number
            $criteriaNumber = null;

            // 1. Check mapping
            if (isset($errorToRgaaMap[$errorType])) {
                $criteriaNumber = $errorToRgaaMap[$errorType];
            }

            // 2. Try to extract from error type name (e.g., "RGAA 1.1" or "rgaa-1-1")
            if (!$criteriaNumber) {
                if (preg_match('/rgaa[_\s-]*(\d+)[._-](\d+)/i', $errorType, $matches)) {
                    $criteriaNumber = $matches[1] . '.' . $matches[2];
                }
            }

            if ($criteriaNumber && $criteriaNumber !== $oldRgaa) {
                $result->setRgaaCriteria($criteriaNumber);
                $updatedResults++;
                $io->writeln(sprintf('  • %s: %s → %s',
                    $errorType,
                    $oldRgaa ?: 'null',
                    $criteriaNumber
                ));
            }
        }

        $io->success(sprintf('Updated RGAA criteria for %d results', $updatedResults));

        $io->section('Step 2: Improving generic recommendations');

        // Improve generic recommendations
        $improvedRecommendations = 0;
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

        foreach ($results as $result) {
            $recommendation = $result->getRecommendation();

            if (!$recommendation) {
                continue;
            }

            // Check if recommendation is generic
            $isGeneric = false;
            foreach ($genericPhrases as $phrase) {
                if (stripos($recommendation, $phrase) !== false) {
                    $isGeneric = true;
                    break;
                }
            }

            if ($isGeneric) {
                $improvedRecommendation = $this->getSpecificRecommendation($result);
                $result->setRecommendation($improvedRecommendation);
                $improvedRecommendations++;
                $io->writeln(sprintf('  • Improved: %s', substr($result->getErrorType(), 0, 50)));
            }
        }

        $io->success(sprintf('Improved %d generic recommendations', $improvedRecommendations));

        $io->section('Step 3: Recalculating conformity rate');

        // Load RGAA criteria
        $criteriaFile = $this->projectDir . '/config/rgaa_criteria.json';
        if (!file_exists($criteriaFile)) {
            $io->error('RGAA criteria file not found: ' . $criteriaFile);
            return Command::FAILURE;
        }

        $criteriaData = json_decode(file_get_contents($criteriaFile), true);
        if (!isset($criteriaData['criteria'])) {
            $io->error('Invalid RGAA criteria file format');
            return Command::FAILURE;
        }

        // Filter auto-testable criteria
        $autoTestableCriteria = array_filter($criteriaData['criteria'], function($criterion) {
            return isset($criterion['autoTestable']) && $criterion['autoTestable'] === true;
        });

        $io->info(sprintf('Total RGAA criteria: %d', count($criteriaData['criteria'])));
        $io->info(sprintf('Auto-testable criteria: %d', count($autoTestableCriteria)));

        // Determine non-conform criteria
        $nonConformCriteriaNumbers = [];

        foreach ($results as $result) {
            $criteriaNumber = $result->getRgaaCriteria();
            if ($criteriaNumber) {
                // Normalize to 2 levels (e.g., "1.1.1" becomes "1.1")
                $criteriaNumber = $this->normalizeRgaaCriteria($criteriaNumber);
                $nonConformCriteriaNumbers[$criteriaNumber] = true;
            }
        }

        // Count non-conform among auto-testable
        $nonConformCount = 0;
        foreach ($nonConformCriteriaNumbers as $number => $val) {
            foreach ($autoTestableCriteria as $criterion) {
                if ($criterion['number'] === $number) {
                    $nonConformCount++;
                    break;
                }
            }
        }

        $conformCount = count($autoTestableCriteria) - $nonConformCount;
        $applicableCriteria = $conformCount + $nonConformCount;

        if ($applicableCriteria > 0) {
            $conformityRate = ($conformCount / $applicableCriteria) * 100;

            $oldRate = $audit->getConformityRate();
            $newRate = round($conformityRate, 2);

            $audit->setConformityRate((string) $newRate);
            $audit->setConformCriteria($conformCount);
            $audit->setNonConformCriteria($nonConformCount);
            $audit->setNotApplicableCriteria(count($criteriaData['criteria']) - count($autoTestableCriteria));

            $io->table(
                ['Metric', 'Old Value', 'New Value'],
                [
                    ['Conformity Rate', $oldRate ? $oldRate . '%' : 'null', $newRate . '%'],
                    ['Conform Criteria', $audit->getConformCriteria() ?: 'null', $conformCount],
                    ['Non-Conform Criteria', $audit->getNonConformCriteria() ?: 'null', $nonConformCount],
                    ['Not Applicable', $audit->getNotApplicableCriteria() ?: 'null', count($criteriaData['criteria']) - count($autoTestableCriteria)],
                ]
            );
        }

        // Save all changes
        $io->section('Saving changes to database');
        $this->entityManager->flush();

        $io->success('✅ Last audit recalculated successfully!');
        $io->info(sprintf('Audit #%d updated with new conformity rate: %.2f%%', $audit->getId(), $newRate));

        return Command::SUCCESS;
    }

    private function getSpecificRecommendation($result): string
    {
        $normalizedType = strtolower($result->getErrorType());

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
            return 'Ajouter un texte visible ou un attribut aria-label descriptif au bouton. Remplacer les <div> avec des gestionnaires de clic par de vraies balises <button>.';
        }

        // Heading errors
        if (stripos($normalizedType, 'heading') !== false || stripos($normalizedType, 'titre') !== false || preg_match('/h[1-6]/i', $normalizedType)) {
            return 'Respecter la hiérarchie des titres : un seul <h1> par page, puis <h2>, <h3>, etc. sans sauter de niveau. Ne pas utiliser les titres uniquement pour le style visuel.';
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

        // Generic fallback
        return 'Corriger l\'élément ' . ($result->getSelector() ?: 'identifié') . ' pour respecter le critère RGAA correspondant. Consulter la documentation RGAA 4.1 pour les exigences détaillées.';
    }

    /**
     * Normalize RGAA criteria to 2 levels (theme.criterion)
     * Converts "1.1.1" or "1.1.2" to "1.1"
     * Keeps "1.1" as "1.1"
     */
    private function normalizeRgaaCriteria(string $criteria): string
    {
        // Extract first two levels: theme.criterion
        if (preg_match('/^(\d+)\.(\d+)/', $criteria, $matches)) {
            return $matches[1] . '.' . $matches[2];
        }
        return $criteria;
    }
}
