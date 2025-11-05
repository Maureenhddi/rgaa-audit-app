<?php

namespace App\Enum;

/**
 * Types of AI image analysis for RGAA criteria
 */
final class ImageAnalysisType
{
    // RGAA 1.3 - Alternative text relevance
    public const ALT_RELEVANCE = 'alt-relevance';

    // RGAA 1.2 - Decorative vs informative images
    public const DECORATIVE_DETECTION = 'decorative-detection';

    // RGAA 8.9 - Text in images
    public const TEXT_IN_IMAGE = 'text-in-image';

    // RGAA 3.2 - Contrast in text images
    public const TEXT_CONTRAST = 'text-contrast';

    // RGAA 3.3 - Color-only information
    public const COLOR_ONLY_INFO = 'color-only-info';

    // Note: RGAA 11.1 (Form labels) is now tested automatically by Playwright
    // No need for AI analysis - basic checks are done by code

    public const ALL = [
        self::ALT_RELEVANCE,
        self::DECORATIVE_DETECTION,
        self::TEXT_IN_IMAGE,
        self::TEXT_CONTRAST,
        self::COLOR_ONLY_INFO,
    ];

    /**
     * Get human-readable label for analysis type
     */
    public static function getLabel(string $type): string
    {
        return match($type) {
            self::ALT_RELEVANCE => 'Pertinence des alternatives textuelles',
            self::DECORATIVE_DETECTION => 'Images décoratives vs informatives',
            self::TEXT_IN_IMAGE => 'Texte sous forme d\'image',
            self::TEXT_CONTRAST => 'Contraste des textes dans les images',
            self::COLOR_ONLY_INFO => 'Information donnée uniquement par la couleur',
            default => $type,
        };
    }

    /**
     * Get RGAA criterion for analysis type
     */
    public static function getRgaaCriterion(string $type): string
    {
        return match($type) {
            self::ALT_RELEVANCE => '1.3',
            self::DECORATIVE_DETECTION => '1.2',
            self::TEXT_IN_IMAGE => '8.9',
            self::TEXT_CONTRAST => '3.2',
            self::COLOR_ONLY_INFO => '3.3',
            default => 'N/A',
        };
    }

    /**
     * Get WCAG criterion for analysis type
     */
    public static function getWcagCriterion(string $type): string
    {
        return match($type) {
            self::ALT_RELEVANCE => '1.1.1',
            self::DECORATIVE_DETECTION => '1.1.1',
            self::TEXT_IN_IMAGE => '1.4.5',
            self::TEXT_CONTRAST => '1.4.3',
            self::COLOR_ONLY_INFO => '1.4.1',
            default => 'N/A',
        };
    }

    /**
     * Get description for analysis type
     */
    public static function getDescription(string $type): string
    {
        return match($type) {
            self::ALT_RELEVANCE => 'Vérifie si l\'attribut alt décrit correctement le contenu de l\'image',
            self::DECORATIVE_DETECTION => 'Détecte si une image décorative a bien alt="" ou role="presentation"',
            self::TEXT_IN_IMAGE => 'Détecte si du texte est inclus dans l\'image (à éviter sauf logo)',
            self::TEXT_CONTRAST => 'Vérifie le contraste des textes présents dans les images (≥4.5:1)',
            self::COLOR_ONLY_INFO => 'Détecte si l\'information est donnée uniquement par la couleur',
            default => '',
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
