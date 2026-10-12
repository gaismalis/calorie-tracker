<?php

namespace App\Tests\Unit\Entity;

use App\Entity\MealEntry;
use App\Entity\MealItem;
use App\Entity\User;
use App\Nutrition\FoodCategory;
use App\Nutrition\Per100g;
use PHPUnit\Framework\TestCase;

class MealItemTest extends TestCase
{
    public function testChangingGramsScalesKcalAndMacros(): void
    {
        $item = $this->item(grams: 400, kcal: 240, protein: 16, carbs: 18, fat: 12);

        $item->changeGrams(100);

        self::assertSame(100.0, $item->getGrams());
        self::assertSame(60.0, $item->getKcal());
        self::assertSame(4.0, $item->getProtein());
        self::assertSame(4.5, $item->getCarbs());
        self::assertSame(3.0, $item->getFat());
    }

    public function testPer100gValuesAreKeptAndDriveTheTotals(): void
    {
        $entry = new MealEntry((new User())->setEmail('a@b.c'), 'skyr', new \DateTimeImmutable());
        $item = new MealItem($entry, 'Skyr', 150, 94.5, 16.5, 6, 0.3, null, new Per100g(63, 11, 4, 0.2));

        self::assertEquals(new Per100g(63, 11, 4, 0.2), $item->getPer100g());
        $item->changeGrams(300);
        self::assertSame([189.0, 33.0, 12.0, 0.6], [$item->getKcal(), $item->getProtein(), $item->getCarbs(), $item->getFat()]);
        self::assertEquals(new Per100g(63, 11, 4, 0.2), $item->getPer100g(), 'same food, different portion');
    }

    public function testCorrectingValuesPer100gRecalculatesTotalsForTheSameAmount(): void
    {
        $item = $this->item(grams: 200, kcal: 300, protein: 10, carbs: 40, fat: 10); // older item, no stored values

        $item->changePer100g(new Per100g(120, 6, 15, 4));

        self::assertSame(200.0, $item->getGrams());
        self::assertSame([240.0, 12.0, 30.0, 8.0], [$item->getKcal(), $item->getProtein(), $item->getCarbs(), $item->getFat()]);
        $item->changeGrams(50);
        self::assertSame(60.0, $item->getKcal(), 'corrected values are used from now on');
    }

    public function testOlderItemsWithoutPer100gWorkThemOutFromTotals(): void
    {
        $item = $this->item(grams: 400, kcal: 240, protein: 16, carbs: 18, fat: 12);

        self::assertEquals(new Per100g(60, 4, 4.5, 3), $item->getPer100g());
        self::assertNull($this->item(grams: 0, kcal: 5, protein: 0, carbs: 1, fat: 0)->getPer100g(), 'no weight, no per-100 g');
    }

    public function testOlderItemsWithoutCategoryCountAsFoodUntilCategorized(): void
    {
        $item = $this->item(grams: 500, kcal: 215, protein: 2, carbs: 18, fat: 0);

        self::assertFalse($item->isCategorized());
        self::assertSame(FoodCategory::Food, $item->getCategory());
        $item->setCategory(FoodCategory::Alcohol);
        self::assertTrue($item->isCategorized());
        self::assertSame(FoodCategory::Alcohol, $item->getCategory());
    }

    public function testRepeatedChangesDoNotDrift(): void
    {
        $item = $this->item(grams: 30, kcal: 177, protein: 7.5, carbs: 6, fat: 15);

        foreach ([17, 43, 3, 250, 30] as $grams) {
            $item->changeGrams($grams);
        }

        self::assertEqualsWithDelta(177, $item->getKcal(), 1e-9);
    }

    public function testItemWithZeroGramsKeepsItsValues(): void
    {
        $item = $this->item(grams: 0, kcal: 5, protein: 0, carbs: 1, fat: 0);

        $item->changeGrams(100);

        self::assertSame(100.0, $item->getGrams());
        self::assertSame(5.0, $item->getKcal(), 'cannot scale from 0 g');
    }

    public function testNegativeGramsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->item(grams: 100, kcal: 100, protein: 1, carbs: 1, fat: 1)->changeGrams(-1);
    }

    private function item(float $grams, float $kcal, float $protein, float $carbs, float $fat): MealItem
    {
        return new MealItem(new MealEntry(new User(), 'x', new \DateTimeImmutable()), 'Food', $grams, $kcal, $protein, $carbs, $fat);
    }
}
