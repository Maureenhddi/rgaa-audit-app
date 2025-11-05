<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Création de la table visual_error_criteria pour le mapping automatique
 * des types d'erreurs visuelles vers les critères WCAG/RGAA
 */
final class Version20251028140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create visual_error_criteria table for auto-learning error type to WCAG/RGAA criteria mapping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE visual_error_criteria (
            id INT AUTO_INCREMENT NOT NULL,
            error_type VARCHAR(100) NOT NULL,
            wcag_criteria VARCHAR(20) NOT NULL,
            rgaa_criteria VARCHAR(20) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            detection_count INT NOT NULL DEFAULT 0,
            auto_learned TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_visual_error_type (error_type),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Pré-remplir avec les types connus (marqués comme non auto-learned car ce sont des références)
        $this->addSql("INSERT INTO visual_error_criteria (error_type, wcag_criteria, rgaa_criteria, description, detection_count, auto_learned, created_at, updated_at) VALUES
            ('image-alt-irrelevant', '1.1.1', '1.3', 'Alternative textuelle non pertinente', 0, 0, NOW(), NOW()),
            ('link-not-explicit', '2.4.4', '6.1', 'Intitulé de lien non explicite', 0, 0, NOW(), NOW()),
            ('color-only-info', '1.4.1', '3.2', 'Information uniquement par la couleur', 0, 0, NOW(), NOW()),
            ('text-as-image', '1.4.5', '1.8', 'Images texte', 0, 0, NOW(), NOW()),
            ('heading-hierarchy-visual', '1.3.1', '9.1', 'Hiérarchie des titres incohérente', 0, 0, NOW(), NOW()),
            ('small-touch-target', '2.5.5', '13.9', 'Taille des zones cliquables insuffisante', 0, 0, NOW(), NOW()),
            ('contrast-context', '1.4.3', '3.2', 'Contraste insuffisant', 0, 0, NOW(), NOW()),
            ('reading-order-inconsistent', '1.3.2', '10.3', 'Ordre de lecture incohérent', 0, 0, NOW(), NOW()),
            ('table-caption-irrelevant', '1.3.1', '5.4', 'Légende de tableau non pertinente', 0, 0, NOW(), NOW()),
            ('label-field-mismatch', '1.3.1', '11.1', 'Association champ/étiquette incorrecte', 0, 0, NOW(), NOW())
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE visual_error_criteria');
    }
}
