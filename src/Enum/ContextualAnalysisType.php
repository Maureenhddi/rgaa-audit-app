<?php

namespace App\Enum;

/**
 * Types of contextual AI analysis (hybrid Playwright + AI)
 * These analyses combine automated technical checks with AI contextual understanding
 */
final class ContextualAnalysisType
{
    // RGAA 3.2 - Contextual contrast verification (borderline cases on complex backgrounds)
    public const CONTRAST_CONTEXT = 'contrast-context';

    // RGAA 6.1 / 9.1 - Heading relevance and structure coherence
    public const HEADING_RELEVANCE = 'heading-relevance';

    // RGAA 6.2 - Link clarity in context
    public const LINK_CONTEXT = 'link-context';

    // RGAA 5.7 - Table headers descriptiveness
    public const TABLE_HEADERS = 'table-headers';

    // RGAA 3.1 - Information conveyed by color alone
    public const COLOR_INFORMATION = 'color-information';

    // RGAA 10.7 - Focus visibility
    public const FOCUS_VISIBLE = 'focus-visible';

    // RGAA 4.1 - Media transcription availability
    public const MEDIA_TRANSCRIPTION = 'media-transcription';

    // RGAA 12.9 - Documented keyboard shortcuts
    public const KEYBOARD_SHORTCUTS = 'keyboard-shortcuts';

    // RGAA 7.2 - Focus management by scripts
    public const FOCUS_MANAGEMENT_SCRIPTS = 'focus-management-scripts';

    // RGAA 12.10 - Keyboard trap
    public const KEYBOARD_TRAP = 'keyboard-trap';

    // RGAA 10.13 / 13.9 - Additional content on hover/focus
    public const ADDITIONAL_CONTENT_HOVER = 'additional-content-hover';

    // RGAA 12.1 - Multiple navigation systems
    public const NAVIGATION_SYSTEMS = 'navigation-systems';

    public const ALL = [
        self::CONTRAST_CONTEXT,
        self::HEADING_RELEVANCE,
        self::LINK_CONTEXT,
        self::TABLE_HEADERS,
        self::COLOR_INFORMATION,
        self::FOCUS_VISIBLE,
        self::MEDIA_TRANSCRIPTION,
        self::KEYBOARD_SHORTCUTS,
        self::FOCUS_MANAGEMENT_SCRIPTS,
        self::KEYBOARD_TRAP,
        self::ADDITIONAL_CONTENT_HOVER,
        self::NAVIGATION_SYSTEMS,
    ];

    /**
     * Get human-readable label for analysis type
     */
    public static function getLabel(string $type): string
    {
        return match($type) {
            self::CONTRAST_CONTEXT => 'Contraste contextuel (arrière-plans complexes)',
            self::HEADING_RELEVANCE => 'Pertinence des titres et structure',
            self::LINK_CONTEXT => 'Clarté des liens dans leur contexte',
            self::TABLE_HEADERS => 'Descriptivité des en-têtes de tableaux',
            self::COLOR_INFORMATION => 'Information transmise uniquement par la couleur',
            self::FOCUS_VISIBLE => 'Visibilité de la prise de focus',
            self::MEDIA_TRANSCRIPTION => 'Transcription textuelle des médias',
            self::KEYBOARD_SHORTCUTS => 'Documentation des raccourcis clavier',
            self::FOCUS_MANAGEMENT_SCRIPTS => 'Gestion du focus par scripts',
            self::KEYBOARD_TRAP => 'Piège au clavier',
            self::ADDITIONAL_CONTENT_HOVER => 'Contenus additionnels au survol/focus',
            self::NAVIGATION_SYSTEMS => 'Systèmes de navigation multiples',
            default => $type,
        };
    }

    /**
     * Get RGAA criterion for analysis type
     */
    public static function getRgaaCriterion(string $type): string
    {
        return match($type) {
            self::CONTRAST_CONTEXT => '3.2',
            self::HEADING_RELEVANCE => '6.1, 9.1',
            self::LINK_CONTEXT => '6.2',
            self::TABLE_HEADERS => '5.7',
            self::COLOR_INFORMATION => '3.1',
            self::FOCUS_VISIBLE => '10.7',
            self::MEDIA_TRANSCRIPTION => '4.1',
            self::KEYBOARD_SHORTCUTS => '12.9',
            self::FOCUS_MANAGEMENT_SCRIPTS => '7.2',
            self::KEYBOARD_TRAP => '12.10',
            self::ADDITIONAL_CONTENT_HOVER => '10.13, 13.9',
            self::NAVIGATION_SYSTEMS => '12.1',
            default => 'N/A',
        };
    }

    /**
     * Get WCAG criterion for analysis type
     */
    public static function getWcagCriterion(string $type): string
    {
        return match($type) {
            self::CONTRAST_CONTEXT => '1.4.3',
            self::HEADING_RELEVANCE => '2.4.6, 1.3.1',
            self::LINK_CONTEXT => '2.4.4',
            self::TABLE_HEADERS => '1.3.1',
            self::COLOR_INFORMATION => '1.4.1',
            self::FOCUS_VISIBLE => '2.4.7',
            self::MEDIA_TRANSCRIPTION => '1.2.1',
            self::KEYBOARD_SHORTCUTS => '2.1.4',
            self::FOCUS_MANAGEMENT_SCRIPTS => '2.4.3',
            self::KEYBOARD_TRAP => '2.1.2',
            self::ADDITIONAL_CONTENT_HOVER => '1.4.13',
            self::NAVIGATION_SYSTEMS => '2.4.5',
            default => 'N/A',
        };
    }

    /**
     * Get description for analysis type
     */
    public static function getDescription(string $type): string
    {
        return match($type) {
            self::CONTRAST_CONTEXT => 'Analyse visuelle du contraste sur arrière-plans complexes (dégradés, images, motifs)',
            self::HEADING_RELEVANCE => 'Vérifie si les titres sont pertinents par rapport au contenu qu\'ils introduisent',
            self::LINK_CONTEXT => 'Vérifie si les liens sont compréhensibles hors contexte (pas "cliquez ici", "lire la suite" ambigus)',
            self::TABLE_HEADERS => 'Vérifie si les en-têtes de tableaux décrivent clairement les données',
            self::COLOR_INFORMATION => 'Détecte si l\'information est transmise uniquement par la couleur (graphiques, statuts, liens)',
            self::FOCUS_VISIBLE => 'Vérifie si l\'indicateur de focus est visible sur les éléments interactifs',
            self::MEDIA_TRANSCRIPTION => 'Détecte la présence de transcription textuelle pour les médias audio et vidéo',
            self::KEYBOARD_SHORTCUTS => 'Vérifie si les raccourcis clavier sont documentés et accessibles',
            self::FOCUS_MANAGEMENT_SCRIPTS => 'Vérifie si le focus est correctement géré lors d\'apparition/disparition dynamique d\'éléments',
            self::KEYBOARD_TRAP => 'Détecte les pièges au clavier dans les modales et overlays',
            self::ADDITIONAL_CONTENT_HOVER => 'Vérifie si les contenus additionnels (tooltips, popovers) sont accessibles et dismissibles',
            self::NAVIGATION_SYSTEMS => 'Vérifie la présence d\'au moins 2 systèmes de navigation différents',
            default => '',
        };
    }

    /**
     * Get impact on user for analysis type
     */
    public static function getImpactUser(string $type): string
    {
        return match($type) {
            self::CONTRAST_CONTEXT => 'Déficients visuels, malvoyants',
            self::HEADING_RELEVANCE => 'Lecteurs d\'écran, navigation clavier',
            self::LINK_CONTEXT => 'Lecteurs d\'écran, navigation clavier',
            self::TABLE_HEADERS => 'Lecteurs d\'écran',
            self::COLOR_INFORMATION => 'Daltoniens, déficients visuels',
            self::FOCUS_VISIBLE => 'Navigation clavier, déficients visuels',
            self::MEDIA_TRANSCRIPTION => 'Sourds, malentendants, environnements bruyants',
            self::KEYBOARD_SHORTCUTS => 'Utilisateurs au clavier, lecteurs d\'écran',
            self::FOCUS_MANAGEMENT_SCRIPTS => 'Navigation clavier, lecteurs d\'écran',
            self::KEYBOARD_TRAP => 'Navigation clavier, lecteurs d\'écran',
            self::ADDITIONAL_CONTENT_HOVER => 'Navigation clavier, déficients moteurs',
            self::NAVIGATION_SYSTEMS => 'Tous utilisateurs, en particulier déficients cognitifs',
            default => 'Tous utilisateurs',
        };
    }

    /**
     * Check if analysis type is valid
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
