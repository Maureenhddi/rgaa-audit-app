<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to restructure action plans:
 * - Add AnnualActionPlan table for annual operational plans
 * - Add strategic fields to ActionPlan (PPA)
 * - Update ActionPlanItem to link to AnnualActionPlan
 */
final class Version20251128160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restructure action plans into PPA (strategic) and Annual Plans (operational)';
    }

    public function up(Schema $schema): void
    {
        // Create annual_action_plan table
        $this->addSql('CREATE TABLE annual_action_plan (
            id INT AUTO_INCREMENT NOT NULL,
            pluri_annual_plan_id INT NOT NULL,
            year INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_ANNUAL_PLAN_PPA (pluri_annual_plan_id),
            INDEX IDX_ANNUAL_PLAN_YEAR (year),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign key
        $this->addSql('ALTER TABLE annual_action_plan
            ADD CONSTRAINT FK_ANNUAL_PLAN_PPA
            FOREIGN KEY (pluri_annual_plan_id)
            REFERENCES action_plan (id)
            ON DELETE CASCADE');

        // Add strategic fields to action_plan
        $this->addSql('ALTER TABLE action_plan
            ADD strategic_orientations JSON DEFAULT NULL,
            ADD progress_axes JSON DEFAULT NULL,
            ADD annual_objectives JSON DEFAULT NULL,
            ADD resources JSON DEFAULT NULL,
            ADD indicators JSON DEFAULT NULL');

        // Add annual_plan_id to action_plan_item
        $this->addSql('ALTER TABLE action_plan_item
            ADD annual_plan_id INT DEFAULT NULL');

        // Add index and foreign key for annual_plan_id
        $this->addSql('CREATE INDEX IDX_ITEM_ANNUAL_PLAN ON action_plan_item (annual_plan_id)');
        $this->addSql('ALTER TABLE action_plan_item
            ADD CONSTRAINT FK_ITEM_ANNUAL_PLAN
            FOREIGN KEY (annual_plan_id)
            REFERENCES annual_action_plan (id)
            ON DELETE CASCADE');

        // Make action_plan_id nullable in action_plan_item (for new items linked to annual plans)
        $this->addSql('ALTER TABLE action_plan_item
            MODIFY action_plan_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Remove annual_plan_id from action_plan_item
        $this->addSql('ALTER TABLE action_plan_item DROP FOREIGN KEY FK_ITEM_ANNUAL_PLAN');
        $this->addSql('DROP INDEX IDX_ITEM_ANNUAL_PLAN ON action_plan_item');
        $this->addSql('ALTER TABLE action_plan_item DROP annual_plan_id');

        // Make action_plan_id NOT NULL again
        $this->addSql('ALTER TABLE action_plan_item
            MODIFY action_plan_id INT NOT NULL');

        // Remove strategic fields from action_plan
        $this->addSql('ALTER TABLE action_plan
            DROP strategic_orientations,
            DROP progress_axes,
            DROP annual_objectives,
            DROP resources,
            DROP indicators');

        // Drop annual_action_plan table
        $this->addSql('DROP TABLE annual_action_plan');
    }
}
