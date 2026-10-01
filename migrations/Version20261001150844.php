<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261001150844 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" ADD timezone VARCHAR(64) DEFAULT \'Europe/Riga\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD sex VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD birth_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD height_cm DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD activity_level VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" DROP timezone');
        $this->addSql('ALTER TABLE "user" DROP sex');
        $this->addSql('ALTER TABLE "user" DROP birth_date');
        $this->addSql('ALTER TABLE "user" DROP height_cm');
        $this->addSql('ALTER TABLE "user" DROP activity_level');
    }
}
