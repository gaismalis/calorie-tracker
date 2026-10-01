<?php

namespace App\Tests\Unit\Energy;

use App\Energy\AdaptiveTdeeCalculator;
use App\Energy\WeightTrend;
use PHPUnit\Framework\TestCase;

class AdaptiveTdeeCalculatorTest extends TestCase
{
    private const TODAY = '2026-10-29';

    public function testNeedsWeighIns(): void
    {
        $result = AdaptiveTdeeCalculator::calculate(self::days(28, fn () => 2500.0), [], self::TODAY);

        self::assertFalse($result->isAvailable());
        self::assertSame('weight', $result->missing);
        self::assertSame(14, $result->daysNeeded);
    }

    public function testNeedsAtLeastTwoWeeksOfWeightHistory(): void
    {
        $result = AdaptiveTdeeCalculator::calculate(self::days(10, fn () => 2500.0), self::days(10, fn () => 80.0), self::TODAY);

        self::assertSame('weight', $result->missing);
        self::assertSame(9, $result->days, 'first to last weigh-in');
        self::assertSame(5, $result->daysNeeded);
    }

    public function testDaysNeededCountsFromFirstWeighInEvenWithoutRecentOnes(): void
    {
        $result = AdaptiveTdeeCalculator::calculate([], ['2026-10-25' => 80.0], self::TODAY); // single weigh-in 4 days ago

        self::assertSame(11, $result->daysNeeded, 'weigh-ins until 8 Nov are needed; the estimate shows from 9 Nov');
    }

    public function testAtLeastOneMoreDayIsNeededWhenWeighInsStoppedLongAgo(): void
    {
        $result = AdaptiveTdeeCalculator::calculate([], ['2026-10-05' => 80.0, '2026-10-10' => 80.0], self::TODAY);

        self::assertSame('weight', $result->missing);
        self::assertSame(1, $result->daysNeeded, 'one weigh-in today would complete it');
    }

    public function testStableWeightMeansIntakeEqualsExpenditure(): void
    {
        $result = AdaptiveTdeeCalculator::calculate(self::days(28, fn () => 2500.0), self::days(28, fn () => 80.0), self::TODAY);

        self::assertTrue($result->isAvailable());
        self::assertSame(2500.0, $result->tdee);
        self::assertSame(27, $result->days);
        self::assertSame(27, $result->loggedDays);
        self::assertSame(2500.0, $result->averageIntake);
        self::assertSame(0.0, $result->trendChangeKg);
    }

    public function testLosingWeightMeansExpenditureIsAboveIntake(): void
    {
        $weights = self::days(28, fn (int $i) => 82.0 - 0.07 * $i); // ~ −2 kg in 4 weeks
        $result = AdaptiveTdeeCalculator::calculate(self::days(28, fn () => 2000.0), $weights, self::TODAY);

        $trend = WeightTrend::daily($weights);
        $change = end($trend) - reset($trend);
        self::assertSame(round(2000 - $change * 7700 / 27), $result->tdee);
        self::assertGreaterThan(2000, $result->tdee);
        self::assertLessThan(0, $result->trendChangeKg);
    }

    public function testGainingWeightMeansExpenditureIsBelowIntake(): void
    {
        $result = AdaptiveTdeeCalculator::calculate(
            self::days(28, fn () => 3000.0),
            self::days(28, fn (int $i) => 75.0 + 0.05 * $i),
            self::TODAY,
        );

        self::assertLessThan(3000, $result->tdee);
    }

    public function testDaysWithoutMealsAreLeftOutOfTheAverage(): void
    {
        $intake = self::days(28, fn (int $i) => 0 === $i % 3 ? 0.0 : 2400.0); // every third day not logged

        $result = AdaptiveTdeeCalculator::calculate($intake, self::days(28, fn () => 80.0), self::TODAY);

        self::assertSame(2400.0, $result->tdee, 'unlogged days are unknown, not zero');
        self::assertSame(18, $result->loggedDays);
    }

    public function testNeedsMealsOnMostDays(): void
    {
        $intake = self::days(28, fn (int $i) => $i < 10 ? 2500.0 : 0.0);

        $result = AdaptiveTdeeCalculator::calculate($intake, self::days(28, fn () => 80.0), self::TODAY);

        self::assertSame('meals', $result->missing);
        self::assertSame(10, $result->loggedDays);
        self::assertSame(7, $result->daysNeeded, '60 % of 27 days = 17 logged days');
    }

    public function testRejectsImplausibleResults(): void
    {
        $result = AdaptiveTdeeCalculator::calculate(self::days(28, fn () => 400.0), self::days(28, fn () => 80.0), self::TODAY);

        self::assertFalse($result->isAvailable());
        self::assertSame('implausible', $result->missing);
        self::assertSame(400.0, $result->averageIntake);
    }

    public function testTodayIsIgnoredBecauseItIsNotOver(): void
    {
        $intake = self::days(28, fn () => 2500.0) + [self::TODAY => 300.0];
        $weights = self::days(28, fn () => 80.0) + [self::TODAY => 90.0];

        self::assertSame(2500.0, AdaptiveTdeeCalculator::calculate($intake, $weights, self::TODAY)->tdee);
    }

    public function testLoggedExerciseIsSubtractedForTheBaseline(): void
    {
        // Every other day a 600 kcal workout; weight stable on 2600 kcal/day → total burn 2600, baseline 2300.
        $exercise = self::days(28, fn (int $i) => 0 === $i % 2 ? 600.0 : 0.0);

        $result = AdaptiveTdeeCalculator::calculate(self::days(28, fn () => 2600.0), self::days(28, fn () => 80.0), self::TODAY, $exercise);

        self::assertSame(2600.0, $result->tdee);
        self::assertSame(311.0, $result->averageExercise, '14 workouts in the 27-day window (8400 / 27)');
        self::assertSame(2289.0, $result->baseline);
    }

    public function testTodaysExerciseDoesNotCountForTheBaseline(): void
    {
        $result = AdaptiveTdeeCalculator::calculate(
            self::days(28, fn () => 2500.0),
            self::days(28, fn () => 80.0),
            self::TODAY,
            [self::TODAY => 1000.0],
        );

        self::assertSame(0.0, $result->averageExercise);
        self::assertSame(2500.0, $result->baseline);
    }

    public function testOnlyTheLast28DaysCount(): void
    {
        $intake = self::days(60, fn (int $i) => $i < 32 ? 5000.0 : 2500.0); // a feast more than 4 weeks ago
        $weights = self::days(60, fn () => 80.0);

        $result = AdaptiveTdeeCalculator::calculate($intake, $weights, self::TODAY);

        self::assertSame(2500.0, $result->tdee);
        self::assertSame(27, $result->days, 'from the weigh-in 28 days ago to yesterday');
    }

    /**
     * Values for the $count days before TODAY, oldest first; the callback gets the day index (0 = oldest).
     *
     * @return array<string, float>
     */
    private static function days(int $count, callable $value): array
    {
        $days = [];
        for ($i = 0; $i < $count; ++$i) {
            $date = (new \DateTimeImmutable(self::TODAY))->modify(sprintf('-%d days', $count - $i))->format('Y-m-d');
            $days[$date] = $value($i);
        }

        return $days;
    }
}
