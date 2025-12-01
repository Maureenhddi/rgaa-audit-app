<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Service to automatically detect non-applicable (N/A) RGAA criteria
 * based on page content analysis
 */
class NonApplicableCriteriaDetector
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Detect which RGAA criteria are not applicable based on page content
     *
     * @param array $pageContent Array with keys: 'hasImages', 'hasVideos', 'hasTables', 'hasForms', 'hasIframes', etc.
     * @return array List of criteria numbers that are N/A (e.g., ['4.1', '4.3', '5.4'])
     */
    public function detectNotApplicableCriteria(array $pageContent): array
    {
        $notApplicable = [];

        // Thème 1 - Images (1.1 to 1.9)
        if (!$pageContent['hasImages'] && !$pageContent['hasSvg']) {
            // If no images at all, some image criteria are N/A
            // Note: 1.1, 1.2, 1.3 are kept as they test the absence is correct
            // Only mark specific image content criteria as N/A
            $notApplicable = array_merge($notApplicable, [
                '1.6', // Images porteuses d'information avec légende
                '1.7', // Images décoratives sans légende
                '1.8', // Images texte (sauf exception)
                '1.9', // Légendes d'images
            ]);
        }

        // Thème 2 - Cadres (2.1, 2.2)
        if (!$pageContent['hasIframes']) {
            $notApplicable = array_merge($notApplicable, [
                '2.1', // Chaque cadre a-t-il un titre ?
                '2.2', // Titre de cadre pertinent ?
            ]);
        }

        // Thème 4 - Multimédia (4.1 to 4.22)
        if (!$pageContent['hasVideos'] && !$pageContent['hasAudio']) {
            $notApplicable = array_merge($notApplicable, [
                '4.1',  // Média temporel a-t-il une transcription ?
                '4.2',  // Média temporel préenregistré a-t-il des sous-titres ?
                '4.3',  // Média temporel synchronisé a-t-il des sous-titres ?
                '4.4',  // Média temporel a-t-il une audiodescription ?
                '4.5',  // Audiodescription étendue ?
                '4.6',  // Audiodescription est-elle pertinente ?
                '4.7',  // Média temporel en direct a-t-il des sous-titres ?
                '4.8',  // Média non temporel a-t-il une alternative ?
                '4.9',  // Média non temporel a-t-il une alternative pertinente ?
                '4.11', // Transcription textuelle est-elle pertinente ?
                '4.12', // Sous-titres synchronisés sont-ils pertinents ?
                '4.13', // Média synchronisé a-t-il une audiodescription ?
                '4.14', // Média temporel a-t-il des sous-titres ?
                '4.15', // Audiodescription synchronisée est-elle pertinente ?
                '4.16', // Média temporel a-t-il une version avec audiodescription ?
                '4.17', // Média temporel a-t-il une version avec langue des signes ?
                '4.18', // Média non temporel a-t-il une alternative textuelle ?
                '4.19', // Média temporel seulement audio a-t-il une transcription ?
                '4.20', // Média temporel seulement vidéo a-t-il une alternative ?
                '4.21', // Média temporel a-t-il une audiodescription étendue ?
                '4.22', // Média temporel a-t-il des sous-titres pour sourds et malentendants ?
            ]);
        }

        // If only video but no audio
        if ($pageContent['hasVideos'] && !$pageContent['hasAudio']) {
            // Keep video criteria, remove audio-only criteria
            $notApplicable = array_merge($notApplicable, [
                '4.19', // Média temporel seulement audio (no audio present)
            ]);
        }

        // If only audio but no video
        if (!$pageContent['hasVideos'] && $pageContent['hasAudio']) {
            // Keep audio criteria, remove video-specific criteria
            $notApplicable = array_merge($notApplicable, [
                '4.20', // Média temporel seulement vidéo (no video present)
            ]);
        }

        // Thème 5 - Tableaux (5.1 to 5.8)
        if (!$pageContent['hasTables']) {
            $notApplicable = array_merge($notApplicable, [
                '5.1', // Tableaux de données complexes ont-ils un résumé ?
                '5.2', // Tableaux de données ont-ils un titre ?
                '5.3', // Pour chaque tableau de mise en forme, le contenu linéarisé reste-t-il compréhensible ?
                '5.4', // Chaque tableau de données a-t-il un titre ?
                '5.5', // Pour chaque tableau de données ayant un titre, ce titre est-il pertinent ?
                '5.6', // Pour chaque tableau de données, chaque en-tête de colonnes et chaque en-tête de lignes sont-ils correctement déclarés ?
                '5.7', // Technique appropriée pour associer cellule et en-tête ?
                '5.8', // Chaque tableau de mise en forme ne doit pas utiliser d'éléments propres aux tableaux de données
            ]);
        }

        // Thème 11 - Formulaires (11.1 to 11.13)
        if (!$pageContent['hasForms']) {
            $notApplicable = array_merge($notApplicable, [
                '11.1',  // Chaque champ de formulaire a-t-il une étiquette ?
                '11.2',  // Étiquette associée à un champ est-elle pertinente ?
                '11.3',  // Dans chaque formulaire, chaque étiquette associée à un champ ayant la même fonction est-elle cohérente ?
                '11.4',  // Dans chaque formulaire, chaque étiquette de champ et son champ sont-ils accolés ?
                '11.5',  // Dans chaque formulaire, les champs de même nature sont-ils regroupés ?
                '11.6',  // Dans chaque formulaire, chaque regroupement de champs a-t-il une légende ?
                '11.7',  // Dans chaque formulaire, chaque légende associée à un regroupement de champs est-elle pertinente ?
                '11.8',  // Dans chaque formulaire, les items de même nature d'une liste sont-ils regroupés ?
                '11.9',  // Intitulé de chaque bouton est-il pertinent ?
                '11.10', // Contrôle de saisie est-il utilisé de manière pertinente ?
                '11.11', // Aide à la saisie est-elle pertinente ?
                '11.12', // Messages d'erreur fournissent-ils des suggestions pour corriger ?
                '11.13', // Finalité d'un champ peut-elle être déduite pour faciliter le remplissage automatique ?
            ]);
        }

        // Thème 13.8 - Contenu en mouvement ou clignotant
        if (!$pageContent['hasAnimations'] && !$pageContent['hasAutoplay']) {
            $notApplicable = array_merge($notApplicable, [
                '13.8', // Chaque contenu en mouvement ou clignotant est-il contrôlable ?
            ]);
        }

        // Thème 4.10 - Son déclenché automatiquement
        if (!$pageContent['hasAutoplayAudio']) {
            $notApplicable = array_merge($notApplicable, [
                '4.10', // Chaque son déclenché automatiquement est-il contrôlable ?
            ]);
        }

        // Thème 13.1 - Limite de temps
        if (!$pageContent['hasTimeLimit']) {
            $notApplicable = array_merge($notApplicable, [
                '13.1', // Pour chaque page web, l'utilisateur a-t-il le contrôle de chaque limite de temps ?
            ]);
        }

        // Thème 13.2 - Ouverture de nouvelle fenêtre
        if (!$pageContent['hasNewWindowLinks']) {
            $notApplicable = array_merge($notApplicable, [
                '13.2', // L'ouverture d'une nouvelle fenêtre ne doit pas être déclenchée sans action de l'utilisateur
            ]);
        }

        $this->logger->info('🔍 Détection automatique des critères N/A', [
            'total_na_detected' => count($notApplicable),
            'na_criteria' => $notApplicable,
            'page_content_analysis' => [
                'images' => $pageContent['hasImages'] ?? false,
                'videos' => $pageContent['hasVideos'] ?? false,
                'audio' => $pageContent['hasAudio'] ?? false,
                'tables' => $pageContent['hasTables'] ?? false,
                'forms' => $pageContent['hasForms'] ?? false,
                'iframes' => $pageContent['hasIframes'] ?? false,
            ]
        ]);

        return array_unique($notApplicable);
    }

    /**
     * Analyze page HTML to detect presence of various elements
     * This is called during audit to build the pageContent array
     *
     * @param string $html Page HTML content
     * @return array Page content analysis
     */
    public function analyzePage(string $html): array
    {
        $analysis = [
            'hasImages' => false,
            'hasSvg' => false,
            'hasVideos' => false,
            'hasAudio' => false,
            'hasTables' => false,
            'hasForms' => false,
            'hasIframes' => false,
            'hasAnimations' => false,
            'hasAutoplay' => false,
            'hasAutoplayAudio' => false,
            'hasTimeLimit' => false,
            'hasNewWindowLinks' => false,
        ];

        // Check for images
        if (preg_match('/<img\s/i', $html)) {
            $analysis['hasImages'] = true;
        }

        // Check for SVG
        if (preg_match('/<svg\s/i', $html)) {
            $analysis['hasSvg'] = true;
        }

        // Check for videos
        if (preg_match('/<video\s/i', $html)) {
            $analysis['hasVideos'] = true;
        }

        // Check for audio
        if (preg_match('/<audio\s/i', $html)) {
            $analysis['hasAudio'] = true;
        }

        // Check for tables (data tables, not layout)
        if (preg_match('/<table\s/i', $html)) {
            $analysis['hasTables'] = true;
        }

        // Check for forms
        if (preg_match('/<form\s/i', $html) || preg_match('/<input\s/i', $html)) {
            $analysis['hasForms'] = true;
        }

        // Check for iframes
        if (preg_match('/<iframe\s/i', $html)) {
            $analysis['hasIframes'] = true;
        }

        // Check for autoplay (video or audio)
        if (preg_match('/autoplay/i', $html)) {
            $analysis['hasAutoplay'] = true;

            // Check if it's audio autoplay specifically
            if (preg_match('/<audio[^>]*autoplay/i', $html)) {
                $analysis['hasAutoplayAudio'] = true;
            }
        }

        // Check for animations (CSS animations, transitions, or animated GIFs)
        if (preg_match('/\.gif/i', $html) || preg_match('/animation|transition/i', $html)) {
            $analysis['hasAnimations'] = true;
        }

        // Check for time limits (meta refresh, setTimeout in scripts)
        if (preg_match('/<meta[^>]*http-equiv=["\']refresh/i', $html)) {
            $analysis['hasTimeLimit'] = true;
        }

        // Check for new window links (target="_blank")
        if (preg_match('/target=["\']_blank/i', $html)) {
            $analysis['hasNewWindowLinks'] = true;
        }

        $this->logger->debug('📄 Analyse du contenu de la page', $analysis);

        return $analysis;
    }
}
