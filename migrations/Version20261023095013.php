<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023095013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Food reviews keyed by first and last day (the latest week leaves out today, so it is 6 days)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX weekly_food_review_user_last_day');
        $this->addSql('ALTER TABLE weekly_food_review ADD first_day VARCHAR(10) DEFAULT NULL');
        // Reviews so far were all of 7 days.
        $this->addSql("UPDATE weekly_food_review SET first_day = to_char(last_day::date - 6, 'YYYY-MM-DD')");
        $this->addSql('ALTER TABLE weekly_food_review ALTER first_day SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX weekly_food_review_user_days ON weekly_food_review (user_id, first_day, last_day)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX weekly_food_review_user_days');
        $this->addSql('ALTER TABLE weekly_food_review DROP first_day');
        $this->addSql('CREATE UNIQUE INDEX weekly_food_review_user_last_day ON weekly_food_review (user_id, last_day)');
    }
}
