<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261001151935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal_entry ADD status VARCHAR(20) DEFAULT \'estimated\' NOT NULL');
        $this->addSql('ALTER TABLE meal_entry ADD estimation_attempts INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE meal_entry ADD last_estimation_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_entry ADD last_estimation_error TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_entry ALTER estimated_by DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal_entry DROP status');
        $this->addSql('ALTER TABLE meal_entry DROP estimation_attempts');
        $this->addSql('ALTER TABLE meal_entry DROP last_estimation_attempt_at');
        $this->addSql('ALTER TABLE meal_entry DROP last_estimation_error');
        $this->addSql('ALTER TABLE meal_entry ALTER estimated_by SET NOT NULL');
    }
}
