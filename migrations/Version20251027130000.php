<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add screenshot_path column to audit table
 */
final class Version20251027130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add screenshot_path column to audit table for storing Gemini Vision screenshots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit ADD screenshot_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit DROP screenshot_path');
    }
}
