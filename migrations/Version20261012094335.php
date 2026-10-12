<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261012094335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Meal items: nutrition values per 100 g';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal_item ADD kcal_per100g DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_item ADD protein_per100g DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_item ADD carbs_per100g DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_item ADD fat_per100g DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal_item DROP kcal_per100g');
        $this->addSql('ALTER TABLE meal_item DROP protein_per100g');
        $this->addSql('ALTER TABLE meal_item DROP carbs_per100g');
        $this->addSql('ALTER TABLE meal_item DROP fat_per100g');
    }
}
