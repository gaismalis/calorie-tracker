<?php

namespace App\Tests\Unit\Review;

use App\Review\FoodLog;
use PHPUnit\Framework\TestCase;

class FoodLogTest extends TestCase
{
    private static function item(string $name, string $category = 'food', string $time = '12:30'): array
    {
        return ['time' => $time, 'name' => $name, 'grams' => 250.0, 'kcal' => 310.5, 'protein' => 12.0, 'carbs' => 40.25, 'fat' => 9.0, 'category' => $category];
    }

    public function testTheTextListsEveryItemByDayWithTheContext(): void
    {
        $log = new FoodLog('2026-10-14', '2026-10-20', [
            '2026-10-14' => [self::item('Oatmeal', time: '08:05'), self::item('Beer', 'alcohol', '20:00')],
            '2026-10-16' => [self::item('Chocolate', 'snack')],
        ], -0.5, 'female', 34);

        $text = $log->toText();

        self::assertStringContainsString('Person: female, 34 years old, wants to lose 0.5 kg/week.', $text);
        self::assertStringContainsString('(2 of 7 days logged; days not listed were not logged)', $text);
        self::assertStringContainsString("Wednesday 2026-10-14:\n- 08:05 Oatmeal: 250 g, 310.5 kcal, protein 12 g, carbs 40.3 g, fat 9 g\n- 20:00 Beer: 250 g, 310.5 kcal, protein 12 g, carbs 40.3 g, fat 9 g (alcohol)", $text);
        self::assertStringContainsString('Chocolate: 250 g, 310.5 kcal, protein 12 g, carbs 40.3 g, fat 9 g (snack)', $text);
        self::assertSame(2, $log->loggedDays());
    }

    public function testSexAndAgeAreOnlySentWhenKnown(): void
    {
        $text = (new FoodLog('2026-10-14', '2026-10-20', [], 0.0))->toText();

        self::assertStringContainsString('Person: wants to keep their weight.', $text);
        self::assertStringContainsString('Person: wants to gain 0.25 kg/week.', (new FoodLog('2026-10-14', '2026-10-20', [], 0.25))->toText());
    }

    public function testTheHashChangesWithTheFood(): void
    {
        $days = ['2026-10-14' => [self::item('Oatmeal')]];
        $log = new FoodLog('2026-10-14', '2026-10-20', $days, 0.0);

        self::assertSame($log->hash(), (new FoodLog('2026-10-14', '2026-10-20', $days, 0.0))->hash());
        self::assertNotSame($log->hash(), (new FoodLog('2026-10-14', '2026-10-20', ['2026-10-14' => [self::item('Oatmeal'), self::item('Apple')]], 0.0))->hash());
    }
}
