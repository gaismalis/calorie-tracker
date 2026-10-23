<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023121700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Meals can start from a recipe: its name and foods are kept, the text is what was added';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal_entry ADD recipe_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_entry ADD base_items JSON DEFAULT NULL');
        // Meals logged from a recipe so far had the recipe's name as their text and nothing added.
        $this->addSql("UPDATE meal_entry SET recipe_name = LEFT(raw_text, 100), raw_text = '' WHERE estimated_by = 'recipe'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal_entry DROP recipe_name');
        $this->addSql('ALTER TABLE meal_entry DROP base_items');
    }
}
