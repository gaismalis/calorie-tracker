<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261030113959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demo account flag and when its data was last rebuilt';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" ADD demo BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD demo_refreshed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" DROP demo');
        $this->addSql('ALTER TABLE "user" DROP demo_refreshed_at');
    }
}
