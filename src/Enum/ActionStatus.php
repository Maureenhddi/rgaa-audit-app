<?php

namespace App\Enum;

enum ActionStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case BLOCKED = 'blocked';

    public function getLabel(): string
    {
        return match($this) {
            self::PENDING => 'En attente',
            self::IN_PROGRESS => 'En cours',
            self::COMPLETED => 'Complétée',
            self::BLOCKED => 'Bloquée',
        };
    }

    public function getBadgeClass(): string
    {
        return match($this) {
            self::COMPLETED => 'bg-success',
            self::IN_PROGRESS => 'bg-primary',
            self::BLOCKED => 'bg-danger',
            self::PENDING => 'bg-secondary',
        };
    }

    public function getIcon(): string
    {
        return match($this) {
            self::PENDING => 'bi-circle',
            self::IN_PROGRESS => 'bi-arrow-repeat',
            self::COMPLETED => 'bi-check-circle',
            self::BLOCKED => 'bi-x-circle',
        };
    }

    public function getIconClass(): string
    {
        return match($this) {
            self::PENDING => 'text-secondary',
            self::IN_PROGRESS => 'text-primary',
            self::COMPLETED => 'text-success',
            self::BLOCKED => 'text-danger',
        };
    }

    /**
     * Get all valid status values as array
     */
    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }
}
