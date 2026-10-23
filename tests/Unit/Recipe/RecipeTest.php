<?php

namespace App\Tests\Unit\Recipe;

use App\Entity\Recipe;
use App\Entity\User;
use App\Nutrition\FoodCategory;
use App\Recipe\RecipeItem;
use PHPUnit\Framework\TestCase;

class RecipeTest extends TestCase
{
    public function testTotalsAndTheMealItMakes(): void
    {
        $recipe = new Recipe(new User(), 'Shake', [
            new RecipeItem('Whey protein', 30, 120, 24, 2, 1.5),
            new RecipeItem('Milk', 250, 115, 8.5, 12, 3.8, FoodCategory::Food),
        ]);

        self::assertSame(235.0, $recipe->getKcal());
        self::assertSame(32.5, $recipe->getProtein());

        $estimate = $recipe->toMealEstimate();
        self::assertSame('recipe', $estimate->estimatedBy);
        self::assertSame('Shake', $estimate->mealName);
        $whey = $estimate->items[0];
        self::assertSame([30.0, 120.0, 24.0], [$whey->grams, $whey->kcal, $whey->protein], 'exactly the recipe\'s numbers');
        self::assertSame(400.0, $whey->per100g->kcal, 'per 100 g, so the amount can still be adjusted');
        self::assertSame(80.0, $whey->per100g->protein);
    }

    public function testItemsSurviveStorage(): void
    {
        $item = new RecipeItem('Beer', 500, 215, 2, 18, 0, FoodCategory::Alcohol);

        self::assertEquals($item, RecipeItem::fromArray($item->toArray()));
    }
}
