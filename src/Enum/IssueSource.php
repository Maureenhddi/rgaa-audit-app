<?php

namespace App\Enum;

/**
 * Issue source tools
 */
final class IssueSource
{
    public const PLAYWRIGHT = 'playwright';
    public const AXE_CORE = 'axe-core';
    public const A11YLINT = 'a11ylint';
    public const GEMINI_IMAGE_ANALYSIS = 'gemini-image-analysis';
    public const IA_CONTEXT = 'ia_context'; // Hybrid Playwright + AI contextual analysis
    public const UNKNOWN = 'unknown';

    public const ALL = [
        self::PLAYWRIGHT,
        self::AXE_CORE,
        self::A11YLINT,
        self::GEMINI_IMAGE_ANALYSIS,
        self::IA_CONTEXT,
        self::UNKNOWN,
    ];

    public static function isValid(string $source): bool
    {
        return in_array($source, self::ALL, true);
    }

    /**
     * Detect source from test name
     */
    public static function detectFromTestName(string $testName): string
    {
        if (stripos($testName, 'Axe-core') !== false) {
            return self::AXE_CORE;
        }

        if (stripos($testName, 'A11yLint') !== false) {
            return self::A11YLINT;
        }

        return self::PLAYWRIGHT;
    }
}
