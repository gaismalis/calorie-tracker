<?php

namespace App\Tests\Unit\Chart;

use App\Chart\WeightChart;
use PHPUnit\Framework\TestCase;

class WeightChartTest extends TestCase
{
    public function testEmptyChartStillHasAxesCentredOnTheLastKnownWeight(): void
    {
        $chart = WeightChart::build([], [], '2026-10-01', 30, fallback: 82.0);

        self::assertTrue($chart->isEmpty());
        self::assertSame('', $chart->trendPath);
        self::assertSame([], $chart->days);
        self::assertNull($chart->trendEnd);
        self::assertNotEmpty($chart->xTicks);
        $ticks = array_column($chart->yTicks, 'value');
        self::assertLessThan(82.0, $ticks[0]);
        self::assertGreaterThan(82.0, end($ticks));
    }

    public function testEmptyChartWithoutAnyWeightUsesADefaultScale(): void
    {
        $ticks = array_column(WeightChart::build([], [], '2026-10-01', 90)->yTicks, 'value');

        self::assertSame([73.0, 74.0, 75.0, 76.0, 77.0], $ticks);
    }

    public function testSingleWeighIn(): void
    {
        $chart = WeightChart::build(['2026-09-30' => 80.0], ['2026-09-30' => 80.0], '2026-10-01', 30);

        self::assertFalse($chart->isEmpty());
        self::assertCount(1, $chart->dots);
        self::assertSame(80.0, $chart->trendEnd['kg']);
        self::assertCount(1, $chart->days);
    }

    public function testFirstDayIsAtTheLeftAndLastDayAtTheRight(): void
    {
        $chart = WeightChart::build(['2026-09-02' => 80.0, '2026-10-01' => 81.0], [], '2026-10-01', 30);

        self::assertSame($chart->plotLeft, $chart->dots[0]['x']);
        self::assertSame($chart->plotRight, $chart->dots[1]['x']);
    }

    public function testHeavierIsHigherAndEverythingFitsThePlot(): void
    {
        $chart = WeightChart::build(['2026-09-20' => 79.2, '2026-09-25' => 82.7], ['2026-09-20' => 79.2, '2026-09-25' => 79.55], '2026-10-01', 30);

        [$light, $heavy] = $chart->dots;
        self::assertLessThan($light['y'], $heavy['y'], 'SVG y grows downwards');
        foreach ([...$chart->dots, ...array_map(fn ($t) => ['y' => $t['y']], $chart->yTicks)] as $point) {
            self::assertGreaterThanOrEqual($chart->plotTop, $point['y']);
            self::assertLessThanOrEqual($chart->plotBottom, $point['y']);
        }
    }

    public function testYTicksAreCleanAndFew(): void
    {
        $chart = WeightChart::build(['2026-09-20' => 79.2, '2026-09-25' => 82.7], [], '2026-10-01', 30);

        $values = array_column($chart->yTicks, 'value');
        self::assertSame([78.0, 79.0, 80.0, 81.0, 82.0, 83.0], $values, 'whole kg, at most 6 ticks');
        self::assertLessThanOrEqual(79.2, $values[0]);
        self::assertGreaterThanOrEqual(82.7, end($values));
    }

    public function testSmallRangeUsesHalfKgTicks(): void
    {
        $chart = WeightChart::build(['2026-09-20' => 80.1, '2026-09-25' => 80.6], [], '2026-10-01', 30);

        self::assertSame([79.5, 80.0, 80.5, 81.0], array_column($chart->yTicks, 'value'));
    }

    public function testTrendPathAndEndLabel(): void
    {
        $chart = WeightChart::build(
            ['2026-09-29' => 80.0, '2026-10-01' => 80.0],
            ['2026-09-29' => 80.0, '2026-09-30' => 80.0, '2026-10-01' => 80.0],
            '2026-10-01',
            30,
        );

        self::assertMatchesRegularExpression('/^M[\d.]+ [\d.]+ L[\d.]+ [\d.]+ L[\d.]+ [\d.]+$/', $chart->trendPath);
        self::assertSame(80.0, $chart->trendEnd['kg']);
        self::assertSame($chart->plotRight, $chart->trendEnd['x']);
    }

    public function testHoverDataHasEveryDayWithDataInOrder(): void
    {
        $chart = WeightChart::build(
            ['2026-09-29' => 80.4, '2026-10-01' => 80.0],
            ['2026-09-29' => 80.4, '2026-09-30' => 80.36, '2026-10-01' => 80.32],
            '2026-10-01',
            30,
        );

        self::assertSame(['Tue, 29 Sep', 'Wed, 30 Sep', 'Thu, 1 Oct'], array_column($chart->days, 'label'));
        self::assertSame([80.4, null, 80.0], array_column($chart->days, 'kg'), 'days without a weigh-in only have a trend');
        self::assertSame(80.36, $chart->days[1]['trend']);
    }

    public function testXTicksEndOnTheLastDay(): void
    {
        $chart = WeightChart::build(['2026-07-04' => 80.0, '2026-10-01' => 81.0], [], '2026-10-01', 90);

        $ticks = $chart->xTicks;
        self::assertSame('1 Oct', end($ticks)['label']);
        self::assertLessThanOrEqual(7, count($ticks));
    }

    public function testWildlyDifferentWeightsStillRender(): void
    {
        $chart = WeightChart::build(['2026-09-20' => 60.0, '2026-09-25' => 400.0], [], '2026-10-01', 30);

        $ticks = array_column($chart->yTicks, 'value');
        self::assertLessThanOrEqual(60, $ticks[0]);
        self::assertGreaterThanOrEqual(400, end($ticks));
    }
}
