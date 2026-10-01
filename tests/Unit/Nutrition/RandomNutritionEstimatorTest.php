<?php

namespace App\Tests\Unit\Nutrition;

use App\Nutrition\RandomNutritionEstimator;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

class RandomNutritionEstimatorTest extends TestCase
{
    public function testSplitsDescriptionIntoItems(): void
    {
        $estimate = (new RandomNutritionEstimator())
            ->estimate("400 g yogurt, 50g jam + 50 g granola and big tablespoon of peanut butter\ncoffee; tea & milk");

        self::assertSame(
            ['400 g yogurt', '50g jam', '50 g granola', 'big tablespoon of peanut butter', 'coffee', 'tea', 'milk'],
            array_map(fn ($item) => $item->name, $estimate->items),
        );
        self::assertSame('random', $estimate->estimatedBy);
    }

    public function testCommaWithoutSpaceStillSeparatesItems(): void
    {
        $estimate = (new RandomNutritionEstimator())->estimate('yogurt,50g jam');

        self::assertSame(['yogurt', '50g jam'], array_map(fn ($item) => $item->name, $estimate->items));
    }

    public function testSplitsOnLatvianAnd(): void
    {
        $estimate = (new RandomNutritionEstimator())->estimate('maize un siers');

        self::assertCount(2, $estimate->items);
    }

    public function testUsesGramsFromTextAndMarksGuessedPortions(): void
    {
        $estimate = (new RandomNutritionEstimator())->estimate('400 g yogurt, 12,5gr butter, an apple');

        self::assertSame(400.0, $estimate->items[0]->grams);
        self::assertNull($estimate->items[0]->assumption);
        self::assertSame(12.5, $estimate->items[1]->grams);
        self::assertGreaterThanOrEqual(30, $estimate->items[2]->grams);
        self::assertLessThanOrEqual(300, $estimate->items[2]->grams);
        self::assertStringContainsString('fake provider', $estimate->items[2]->assumption);
    }

    public function testMacrosAreWithinRealisticRangesAndKcalMatchesMacros(): void
    {
        $estimator = new RandomNutritionEstimator();

        for ($i = 0; $i < 200; ++$i) {
            $item = $estimator->estimate('200 g something')->items[0];

            self::assertGreaterThanOrEqual(0, $item->protein);
            self::assertLessThanOrEqual(50, $item->protein);
            self::assertLessThanOrEqual(120, $item->carbs);
            self::assertLessThanOrEqual(60, $item->fat);
            self::assertEqualsWithDelta(4 * $item->protein + 4 * $item->carbs + 9 * $item->fat, $item->kcal, 0.1);
        }
    }

    public function testSameSeedGivesSameNumbers(): void
    {
        $a = (new RandomNutritionEstimator(new Randomizer(new Mt19937(42))))->estimate('rice, chicken');
        $b = (new RandomNutritionEstimator(new Randomizer(new Mt19937(42))))->estimate('rice, chicken');

        self::assertEquals($a, $b);
    }

    public function testDescriptionWithOnlySeparatorsGivesNoItems(): void
    {
        self::assertSame([], (new RandomNutritionEstimator())->estimate(' , + ; ')->items);
    }
}
