<?php

namespace App\Tests\Unit\Energy;

use App\Energy\AdaptiveTdee;
use App\Energy\EnergyEstimate;
use PHPUnit\Framework\TestCase;

class EnergyEstimateTest extends TestCase
{
    public function testAdaptiveEstimateWinsOverFormula(): void
    {
        $estimate = (new EnergyEstimate(1780, 2759))->withAdaptive(new AdaptiveTdee(2500));

        self::assertSame(2500.0, $estimate->getBaseline());
        self::assertSame('adaptive', $estimate->getSource());
    }

    public function testFallsBackToFormula(): void
    {
        $estimate = (new EnergyEstimate(1780, 2759))->withAdaptive(new AdaptiveTdee(null, missing: 'weight'));

        self::assertSame(2759.0, $estimate->getBaseline());
        self::assertSame('formula', $estimate->getSource());
        self::assertNull((new EnergyEstimate(null, null))->getSource());
    }

    /** @return iterable<string, array{float, float}> */
    public static function goals(): iterable
    {
        yield 'maintain' => [0.0, 2500.0];
        yield 'lose 0.5 kg/week = −550 kcal/day' => [-0.5, 1950.0];
        yield 'lose 1 kg/week = −1100 kcal/day' => [-1.0, 1400.0];
        yield 'gain 0.25 kg/week = +275 kcal/day' => [0.25, 2775.0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('goals')]
    public function testTargetForWeeklyGoal(float $goal, float $target): void
    {
        self::assertSame($target, (new EnergyEstimate(null, 2500))->targetFor($goal));
    }

    public function testTargetIsNeverBelowSafeMinimum(): void
    {
        self::assertSame(1200.0, (new EnergyEstimate(null, 1900))->targetFor(-1.0));
    }

    public function testNoTargetWithoutEstimate(): void
    {
        self::assertNull((new EnergyEstimate(null, null))->targetFor(-0.5));
    }

    public function testDailyAdjustment(): void
    {
        self::assertSame(-550.0, EnergyEstimate::dailyAdjustment(-0.5));
        self::assertSame(825.0, EnergyEstimate::dailyAdjustment(0.75));
    }

    public function testLoggedExerciseIsAddedOnTopOfTheBaseline(): void
    {
        $estimate = new EnergyEstimate(null, 2200);

        self::assertSame(2800.0, $estimate->burnedWith(600));
        self::assertSame(2250.0, $estimate->targetFor(-0.5, 600), '2200 + 600 − 550');
        self::assertSame(1650.0, $estimate->targetFor(-0.5));
    }

    public function testAdaptiveBaselineExcludesAverageLoggedExercise(): void
    {
        $estimate = (new EnergyEstimate(null, 2759))->withAdaptive(new AdaptiveTdee(2700, averageExercise: 300));

        self::assertSame(2400.0, $estimate->getBaseline());
        self::assertSame(2900.0, $estimate->burnedWith(500));
    }
}
