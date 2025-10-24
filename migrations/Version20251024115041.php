<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251024115041 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE manual_check (id INT AUTO_INCREMENT NOT NULL, audit_id INT NOT NULL, criteria_number VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL, notes LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_7E4B37B0BD29F359 (audit_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE manual_check ADD CONSTRAINT FK_7E4B37B0BD29F359 FOREIGN KEY (audit_id) REFERENCES audit (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE manual_check DROP FOREIGN KEY FK_7E4B37B0BD29F359');
        $this->addSql('DROP TABLE manual_check');
    }
}
