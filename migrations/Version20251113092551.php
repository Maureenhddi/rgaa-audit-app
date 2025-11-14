<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251113092551 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_campaign (id INT AUTO_INCREMENT NOT NULL, project_id INT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, start_date DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', end_date DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', status VARCHAR(50) NOT NULL, sample_type VARCHAR(50) NOT NULL, avg_conformity_rate NUMERIC(5, 2) DEFAULT NULL, total_pages INT DEFAULT NULL, total_issues INT DEFAULT NULL, critical_count INT DEFAULT NULL, major_count INT DEFAULT NULL, minor_count INT DEFAULT NULL, report_pdf_path VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_4F014134166D1F9C (project_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE audit_campaign ADD CONSTRAINT FK_4F014134166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE audit ADD campaign_id INT DEFAULT NULL, ADD page_type VARCHAR(100) DEFAULT NULL, ADD page_title VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE audit ADD CONSTRAINT FK_9218FF79F639F774 FOREIGN KEY (campaign_id) REFERENCES audit_campaign (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_9218FF79F639F774 ON audit (campaign_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit DROP FOREIGN KEY FK_9218FF79F639F774');
        $this->addSql('ALTER TABLE audit_campaign DROP FOREIGN KEY FK_4F014134166D1F9C');
        $this->addSql('DROP TABLE audit_campaign');
        $this->addSql('DROP INDEX IDX_9218FF79F639F774 ON audit');
        $this->addSql('ALTER TABLE audit DROP campaign_id, DROP page_type, DROP page_title');
    }
}
