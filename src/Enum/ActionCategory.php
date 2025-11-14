<?php

namespace App\Enum;

enum ActionCategory: string
{
    case QUICK_WIN = 'quick_win';
    case STRUCTURAL = 'structural';
    case CONTENT = 'content';
    case TECHNICAL = 'technical';
    case TRAINING = 'training';

    public function getLabel(): string
    {
        return match($this) {
            self::QUICK_WIN => 'Quick Win',
            self::STRUCTURAL => 'Structurel',
            self::CONTENT => 'Contenu',
            self::TECHNICAL => 'Technique',
            self::TRAINING => 'Formation',
        };
    }

    public function getBadgeClass(): string
    {
        return match($this) {
            self::QUICK_WIN => 'bg-success',
            self::STRUCTURAL => 'bg-primary',
            self::CONTENT => 'bg-info',
            self::TECHNICAL => 'bg-warning text-dark',
            self::TRAINING => 'bg-secondary',
        };
    }

    /**
     * Get all valid category values as array
     */
    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }
}
