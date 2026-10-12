<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261012122201 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Meal entries: short title (from the AI or the item names); existing meals get their item names';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal_entry ADD title VARCHAR(60) DEFAULT NULL');
        // Existing meals: their item names, in order, cut to 60 characters like new ones ("…" at the end).
        $this->addSql(<<<'SQL'
            UPDATE meal_entry e SET title = CASE WHEN char_length(t.names) > 60 THEN left(t.names, 59) || '…' ELSE t.names END
            FROM (SELECT entry_id, string_agg(name, ', ' ORDER BY id) AS names FROM meal_item GROUP BY entry_id) t
            WHERE t.entry_id = e.id AND e.title IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal_entry DROP title');
    }
}
