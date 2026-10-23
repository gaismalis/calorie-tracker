<?php

namespace App\Tests\Unit\Summary;

use App\Summary\WeeklySummary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WeeklySummaryTest extends TestCase
{
    public function testAveragesOnlyCountDaysWithMeals(): void
    {
        $week = WeeklySummary::calculate(
            eaten: ['2026-10-05' => 2000.0, '2026-10-07' => 2400.0, '2026-10-04' => 9999.0 /* before the week */],
            exercise: ['2026-10-07' => 600.0],
            trend: [],
            baseline: 2100.0,
            to: '2026-10-11',
            goalKgPerWeek: -0.5,
        );

        self::assertSame('2026-10-05', $week->from);
        self::assertSame(2, $week->loggedDays);
        self::assertSame(2200.0, $week->averageEaten);
        self::assertSame(2400.0, $week->averageBurned, '(2100 + 2700) / 2: exercise on its day');
        self::assertSame(-200.0, $week->getDailyBalance());
        self::assertSame(-0.18, $week->getExpectedKgPerWeek(), '−200 × 7 / 7700');
        self::assertSame(600.0, $week->exerciseKcal);
        self::assertSame(1, $week->exerciseDays);
        self::assertTrue($week->isRough());
        self::assertFalse($week->isEmpty());
    }

    public function testTheLatestWeekCanLeaveOutToday(): void
    {
        $week = WeeklySummary::calculate(
            eaten: ['2026-10-05' => 1000.0 /* a day earlier than 6 days */, '2026-10-06' => 2000.0, '2026-10-11' => 2400.0],
            exercise: [],
            trend: [],
            baseline: 2100.0,
            to: '2026-10-11',
            goalKgPerWeek: 0.0,
            days: 6,
        );

        self::assertSame('2026-10-06', $week->from);
        self::assertSame(6, $week->days);
        self::assertSame(2, $week->loggedDays);
        self::assertSame(2200.0, $week->averageEaten);
    }

    public function testAlcoholAndSnacksForTheWeek(): void
    {
        $week = WeeklySummary::calculate(
            eaten: ['2026-10-05' => 2000.0, '2026-10-06' => 3000.0],
            exercise: [],
            trend: [],
            baseline: 2100.0,
            to: '2026-10-11',
            goalKgPerWeek: 0.0,
            alcohol: ['2026-10-06' => 750.0, '2026-10-01' => 999.0 /* before the week */],
            snacks: ['2026-10-05' => 250.0],
        );

        self::assertSame(5000.0, $week->eatenKcal);
        self::assertSame(750.0, $week->alcoholKcal);
        self::assertSame(250.0, $week->snackKcal);
        self::assertSame(15, $week->share($week->alcoholKcal));
        self::assertSame(5, $week->share($week->snackKcal));
        self::assertNull(WeeklySummary::calculate([], [], [], null, '2026-10-11', 0.0)->share(100), 'nothing eaten: no share');
    }

    public function testWithoutBurnEstimateThereIsNoBalance(): void
    {
        $week = WeeklySummary::calculate(['2026-10-11' => 2000.0], [], [], null, '2026-10-11', 0.0);

        self::assertNull($week->averageBurned);
        self::assertNull($week->getDailyBalance());
        self::assertNull($week->getExpectedKgPerWeek());
        self::assertNull($week->getGoalStatus());
    }

    public function testTrendChangeFromTheDayBeforeTheWeekToItsLatestValue(): void
    {
        $trend = ['2026-10-03' => 81.0, '2026-10-04' => 80.9, '2026-10-08' => 80.6, '2026-10-09' => 80.55];

        $week = WeeklySummary::calculate([], [], $trend, 2100.0, '2026-10-11', 0.0);

        self::assertSame(-0.35, $week->trendChangeKg, '80.55 (9 Oct, latest) − 80.9 (4 Oct, day before the week)');
        self::assertFalse($week->isEmpty(), 'weigh-ins alone are something to show');
        self::assertNull(WeeklySummary::calculate([], [], ['2026-10-08' => 80.0], 2100.0, '2026-10-11', 0.0)->trendChangeKg, 'one day is no change');
    }

    public function testNothingLogged(): void
    {
        self::assertTrue(WeeklySummary::calculate([], [], [], 2100.0, '2026-10-11', -0.5)->isEmpty());
    }

    /** @return iterable<string, array{float, float, string}> eaten per day (burned 2100), goal, status */
    public static function goals(): iterable
    {
        yield 'losing at the goal pace' => [1550.0, -0.5, WeeklySummary::STATUS_ON_TRACK];   // −0.5 kg/week
        yield 'losing faster' => [1000.0, -0.5, WeeklySummary::STATUS_FASTER];               // −1.0
        yield 'losing slower' => [1850.0, -0.5, WeeklySummary::STATUS_SLOWER];               // −0.23
        yield 'gaining while trying to lose' => [2500.0, -0.5, WeeklySummary::STATUS_WRONG_WAY];
        yield 'eating exactly what is burned, trying to lose' => [2100.0, -0.5, WeeklySummary::STATUS_WRONG_WAY];
        yield 'keeping weight' => [2150.0, 0.0, WeeklySummary::STATUS_ON_TRACK];             // +0.05
        yield 'drifting while keeping weight' => [2500.0, 0.0, WeeklySummary::STATUS_WRONG_WAY];
        yield 'gaining slower' => [2300.0, 0.5, WeeklySummary::STATUS_SLOWER];               // +0.18
    }

    #[DataProvider('goals')]
    public function testGoalStatus(float $eatenPerDay, float $goal, string $status): void
    {
        $eaten = [];
        for ($d = 5; $d <= 11; ++$d) {
            $eaten[sprintf('2026-10-%02d', $d)] = $eatenPerDay;
        }

        self::assertSame($status, WeeklySummary::calculate($eaten, [], [], 2100.0, '2026-10-11', $goal)->getGoalStatus());
    }
}
