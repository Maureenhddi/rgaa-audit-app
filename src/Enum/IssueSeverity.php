<?php

namespace App\Enum;

/**
 * Issue severity levels
 */
final class IssueSeverity
{
    public const CRITICAL = 'critical';
    public const MAJOR = 'major';
    public const MINOR = 'minor';

    public const ALL = [
        self::CRITICAL,
        self::MAJOR,
        self::MINOR,
    ];

    public static function isValid(string $severity): bool
    {
        return in_array($severity, self::ALL, true);
    }
}
