<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251113130752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE action_plan (id INT AUTO_INCREMENT NOT NULL, campaign_id INT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, duration_years INT NOT NULL, current_conformity_rate NUMERIC(5, 2) DEFAULT NULL, target_conformity_rate NUMERIC(5, 2) DEFAULT NULL, total_issues INT NOT NULL, critical_issues INT NOT NULL, major_issues INT NOT NULL, minor_issues INT NOT NULL, executive_summary LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', status VARCHAR(50) NOT NULL, INDEX IDX_ABBBE073F639F774 (campaign_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE action_plan_item (id INT AUTO_INCREMENT NOT NULL, action_plan_id INT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, category VARCHAR(50) NOT NULL, severity VARCHAR(50) NOT NULL, priority INT NOT NULL, year INT NOT NULL, quarter INT NOT NULL, quick_win TINYINT(1) NOT NULL, estimated_effort INT DEFAULT NULL, impact_score INT DEFAULT NULL, acceptance_criteria LONGTEXT DEFAULT NULL, technical_details LONGTEXT DEFAULT NULL, affected_pages JSON DEFAULT NULL, rgaa_criteria JSON DEFAULT NULL, status VARCHAR(50) NOT NULL, completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_AECBFAF1323B8A7A (action_plan_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE action_plan ADD CONSTRAINT FK_ABBBE073F639F774 FOREIGN KEY (campaign_id) REFERENCES audit_campaign (id)');
        $this->addSql('ALTER TABLE action_plan_item ADD CONSTRAINT FK_AECBFAF1323B8A7A FOREIGN KEY (action_plan_id) REFERENCES action_plan (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE action_plan DROP FOREIGN KEY FK_ABBBE073F639F774');
        $this->addSql('ALTER TABLE action_plan_item DROP FOREIGN KEY FK_AECBFAF1323B8A7A');
        $this->addSql('DROP TABLE action_plan');
        $this->addSql('DROP TABLE action_plan_item');
    }
}
