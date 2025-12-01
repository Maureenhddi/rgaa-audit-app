<?php

namespace App\Enum;

enum ActionSeverity: string
{
    case CRITICAL = 'critical';
    case MAJOR = 'major';
    case MINOR = 'minor';

    public function getLabel(): string
    {
        return match($this) {
            self::CRITICAL => 'Critique',
            self::MAJOR => 'Majeur',
            self::MINOR => 'Mineur',
        };
    }

    public function getBadgeClass(): string
    {
        return match($this) {
            self::CRITICAL => 'bg-danger',
            self::MAJOR => 'bg-warning text-dark',
            self::MINOR => 'bg-info',
        };
    }

    public function getBaseEffort(): int
    {
        return match($this) {
            self::CRITICAL => 4,  // Reduced from 8h - more realistic base
            self::MAJOR => 2,     // Reduced from 4h
            self::MINOR => 1,     // Reduced from 2h
        };
    }

    public function getImpactScore(): int
    {
        return match($this) {
            self::CRITICAL => 100,
            self::MAJOR => 70,
            self::MINOR => 40,
        };
    }

    /**
     * Get all valid severity values as array
     */
    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }
}
