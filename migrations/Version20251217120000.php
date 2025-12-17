<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add audit_scope column to audit table to support:
 * - full: Complete page audit (default)
 * - transverse: Only transverse elements (header, footer, navigation)
 * - main_content: Only main content (excluding transverse elements)
 */
final class Version20251217120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit_scope column to audit table for scoped auditing';
    }

    public function up(Schema $schema): void
    {
        // Add audit_scope column with default value 'full'
        $this->addSql("ALTER TABLE audit
            ADD audit_scope VARCHAR(50) NOT NULL DEFAULT 'full'
            AFTER page_type");
    }

    public function down(Schema $schema): void
    {
        // Remove audit_scope column
        $this->addSql('ALTER TABLE audit DROP audit_scope');
    }
}
