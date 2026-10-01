<?php

namespace App\Tests\Unit\Exercise;

use App\Exercise\RandomExerciseEstimator;
use PHPUnit\Framework\TestCase;

class RandomExerciseEstimatorTest extends TestCase
{
    public function testUsesTypedDurationsAndMakesUpPlausibleBurn(): void
    {
        $estimate = (new RandomExerciseEstimator())->estimate('basketball 2h, 30 min running and yoga', 80);

        self::assertSame(['basketball 2h', '30 min running', 'yoga'], array_map(fn ($a) => $a->name, $estimate->items));
        self::assertSame(120.0, $estimate->items[0]->minutes);
        self::assertSame(30.0, $estimate->items[1]->minutes);
        self::assertStringContainsString('fake provider', $estimate->items[2]->assumption);
        foreach ($estimate->items as $activity) {
            self::assertGreaterThanOrEqual(4 * $activity->minutes - 1, $activity->kcal);
            self::assertLessThanOrEqual(10 * $activity->minutes + 1, $activity->kcal);
        }
        self::assertSame('random', $estimate->estimatedBy);
    }

    public function testDecimalHours(): void
    {
        self::assertSame(90.0, (new RandomExerciseEstimator())->estimate('hike 1,5 h', null)->items[0]->minutes);
    }
}
