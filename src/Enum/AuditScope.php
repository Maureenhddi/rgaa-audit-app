<?php

namespace App\Enum;

class AuditScope
{
    public const FULL = 'full';
    public const TRANSVERSE = 'transverse';
    public const MAIN_CONTENT = 'main_content';

    public const LABELS = [
        self::FULL => 'Page complète',
        self::TRANSVERSE => 'Éléments transverses uniquement',
        self::MAIN_CONTENT => 'Contenu principal uniquement',
    ];

    public const DESCRIPTIONS = [
        self::FULL => 'Audite l\'intégralité de la page (header, navigation, contenu, footer)',
        self::TRANSVERSE => 'Audite uniquement les éléments transverses (header, footer, navigation, fil d\'ariane)',
        self::MAIN_CONTENT => 'Audite uniquement le contenu principal de la page (sans les éléments transverses)',
    ];

    public static function getChoices(): array
    {
        return [
            self::LABELS[self::FULL] => self::FULL,
            self::LABELS[self::TRANSVERSE] => self::TRANSVERSE,
            self::LABELS[self::MAIN_CONTENT] => self::MAIN_CONTENT,
        ];
    }

    public static function getLabel(string $scope): string
    {
        return self::LABELS[$scope] ?? $scope;
    }

    public static function getDescription(string $scope): string
    {
        return self::DESCRIPTIONS[$scope] ?? '';
    }
}
