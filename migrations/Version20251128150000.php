<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add display_order column to action_plan_item for drag & drop functionality
 */
final class Version20251128150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add display_order column to action_plan_item table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE action_plan_item ADD display_order INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE action_plan_item DROP display_order');
    }
}
