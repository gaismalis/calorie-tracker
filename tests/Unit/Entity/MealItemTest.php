<?php

namespace App\Tests\Unit\Entity;

use App\Entity\MealEntry;
use App\Entity\MealItem;
use App\Entity\User;
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
