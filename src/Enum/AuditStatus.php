<?php

namespace App\Enum;

/**
 * Audit status constants
 */
final class AuditStatus
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    public const ALL = [
        self::PENDING,
        self::RUNNING,
        self::COMPLETED,
        self::FAILED,
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
