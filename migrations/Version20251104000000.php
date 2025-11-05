<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add notTestedCriteria column to audit table
 */
final class Version20251104000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notTestedCriteria column to audit table to track untested criteria separately from non-applicable criteria';
    }

    public function up(Schema $schema): void
    {
        // Add notTestedCriteria column
        $this->addSql('ALTER TABLE audit ADD not_tested_criteria INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Remove notTestedCriteria column
        $this->addSql('ALTER TABLE audit DROP not_tested_criteria');
    }
}
