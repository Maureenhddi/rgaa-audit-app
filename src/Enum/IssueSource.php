<?php

namespace App\Enum;

/**
 * Issue source tools
 */
final class IssueSource
{
    public const PLAYWRIGHT = 'playwright';
    public const AXE_CORE = 'axe-core';
    public const HTML_CODESNIFFER = 'html_codesniffer';
    public const UNKNOWN = 'unknown';

    public const ALL = [
        self::PLAYWRIGHT,
        self::AXE_CORE,
        self::HTML_CODESNIFFER,
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

        if (stripos($testName, 'HTML_CodeSniffer') !== false) {
            return self::HTML_CODESNIFFER;
        }

        return self::PLAYWRIGHT;
    }
}
