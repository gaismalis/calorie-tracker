<?php

namespace App\Command;

use App\Entity\MealItem;
use App\Nutrition\Categorize\FoodCategorizer;
use App\Nutrition\Categorize\KeywordFoodCategorizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfills the category (food, snack, alcohol) of meal items logged before categories existed.
 * Only touches items without one, so it's safe to run again (production runs it on every start).
 */
#[AsCommand(name: 'app:meal-items:categorize', description: 'Set food/snack/alcohol for meal items that have no category yet')]
final class CategorizeMealItemsCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FoodCategorizer $categorizer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Distinct item names per AI request')] int $batch = 100,
        #[Option('Use keyword lists instead of the AI')] bool $keywords = false,
    ): int {
        $categorizer = $keywords ? new KeywordFoodCategorizer() : $this->categorizer;
        $batch = max(1, $batch);
        $updated = 0;

        while ([] !== $names = $this->uncategorizedNames($batch)) {
            try {
                $categories = $categorizer->categorize($names);
            } catch (\RuntimeException $e) {
                $io->error(sprintf('Categorizing failed, %d items done; run again later. %s', $updated, $e->getMessage()));

                return Command::FAILURE;
            }

            foreach ($categories as $name => $category) {
                $updated += $this->entityManager->createQuery(
                    'UPDATE '.MealItem::class.' i SET i.category = :category WHERE i.category IS NULL AND i.name = :name'
                )->execute(['category' => $category, 'name' => $name]);
            }
            if (array_diff($names, array_keys($categories))) {
                $io->error('The categorizer skipped some names; stopping so it doesn\'t ask again forever.');

                return Command::FAILURE;
            }
        }

        $io->success(0 === $updated ? 'All meal items already have a category.' : sprintf('Categorized %d meal items.', $updated));

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function uncategorizedNames(int $limit): array
    {
        return array_column($this->entityManager->createQuery(
            'SELECT DISTINCT i.name FROM '.MealItem::class.' i WHERE i.category IS NULL ORDER BY i.name'
        )->setMaxResults($limit)->getArrayResult(), 'name');
    }
}
