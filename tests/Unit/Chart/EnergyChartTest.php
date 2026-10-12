<?php

namespace App\Tests\Unit\Chart;

use App\Chart\EnergyChart;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnergyChartTest extends TestCase
{
    public function testFourteenDaysEndingOnTheShownDay(): void
    {
        $chart = EnergyChart::build([], [], '2026-10-01', '2026-10-01');

        self::assertCount(14, $chart->days);
        self::assertSame('Fri, 18 Sep', $chart->days[0]['label']);
        self::assertSame('Thu, 1 Oct (so far)', $chart->days[13]['label']);
        self::assertTrue($chart->empty);
        self::assertFalse($chart->hasBurned);
        $ticks = $chart->xTicks;
        self::assertSame('1 Oct', end($ticks)['label']);
    }

    public function testDaysWithoutMealsHaveNoBar(): void
    {
        $chart = EnergyChart::build(['2026-09-28' => 2000.0, '2026-10-01' => 1900.0], [], '2026-10-01', '2026-10-02');

        self::assertNotNull($chart->days[10]['bar'], 'Sep 28');
        self::assertNull($chart->days[11]['bar'], 'Sep 29: not logged, not zero');
        self::assertNull($chart->days[11]['status']);
        self::assertSame(EnergyChart::STATUS_UNKNOWN, $chart->days[13]['status'], 'eaten, but no burn estimate to compare with');
        self::assertNull($chart->days[13]['burnedMark']);
        self::assertSame(['series' => 'eaten-none', 'value' => 'not logged', 'name' => 'Eaten'], $chart->hover()[11]['rows'][0]);
    }

    public function testBarsStartAtZeroAndReachTheirValue(): void
    {
        $chart = EnergyChart::build(['2026-10-01' => 2000.0], [], '2026-10-01', '2026-10-01');

        self::assertSame(0, $chart->yTicks[0]['value']);
        $bar = $chart->days[13]['bar'];
        self::assertEqualsWithDelta($chart->plotBottom, $bar['y'] + $bar['height'], 0.11);
        $tick2000 = array_values(array_filter($chart->yTicks, fn ($t) => 2000 === $t['value']))[0];
        self::assertEqualsWithDelta($tick2000['y'], $bar['y'], 0.11);
        self::assertGreaterThanOrEqual($chart->plotLeft, $bar['x']);
        self::assertLessThan($chart->days[13]['x'], $bar['x'], 'bar is centred on its day');
    }

    public function testEachDayIsComparedWithWhatWasBurnedThatDay(): void
    {
        $burned = [];
        for ($i = 13; $i >= 0; --$i) {
            $burned[(new \DateTimeImmutable('2026-10-01'))->modify("-$i days")->format('Y-m-d')] = 2000.0;
        }
        $burned['2026-09-30'] = 2600.0; // workout day: burned more

        $chart = EnergyChart::build(
            ['2026-09-29' => 2400.0, '2026-09-30' => 2400.0, '2026-10-01' => 1200.0],
            $burned,
            '2026-10-01',
            '2026-10-01',
        );

        self::assertTrue($chart->hasBurned);
        self::assertSame(EnergyChart::STATUS_OVER, $chart->days[11]['status']);
        self::assertSame(400.0, $chart->days[11]['overBy']);
        self::assertSame(EnergyChart::STATUS_UNDER, $chart->days[12]['status'], 'same intake, but the workout burned more');
        self::assertNull($chart->days[12]['overBy']);
        self::assertGreaterThan($chart->days[12]['burnedMark']['y'], $chart->days[11]['burnedMark']['y'], 'more burned is drawn higher');
        self::assertNotNull($chart->days[0]['burnedMark'], 'burned shown on days without meals too');

        self::assertSame([
            ['series' => 'eaten-over', 'value' => '2 400 kcal', 'name' => 'Eaten'],
            ['series' => 'burned', 'value' => '2 000 kcal', 'name' => 'Burned'],
            ['series' => 'balance', 'value' => '400 kcal', 'name' => 'more than burned'],
        ], $chart->hover()[11]['rows']);
        self::assertSame(['series' => 'balance', 'value' => '200 kcal', 'name' => 'less than burned'], $chart->hover()[12]['rows'][2]);
        self::assertSame(['series' => 'balance', 'value' => '800 kcal', 'name' => 'less than burned so far'], $chart->hover()[13]['rows'][2]);
        self::assertTrue($chart->days[13]['today']);
    }

    public function testEatingExactlyWhatWasBurnedIsNotMore(): void
    {
        $chart = EnergyChart::build(['2026-10-01' => 2000.4], ['2026-10-01' => 2000.0], '2026-10-01', '2026-10-02');

        self::assertSame(EnergyChart::STATUS_UNDER, $chart->days[13]['status']);
    }

    public function testCompactVersionFitsAPhoneWithFewerDateLabels(): void
    {
        $wide = EnergyChart::build(['2026-10-01' => 2000.0], [], '2026-10-01', '2026-10-01');
        $compact = EnergyChart::build(['2026-10-01' => 2000.0], [], '2026-10-01', '2026-10-01', width: EnergyChart::COMPACT_WIDTH);

        self::assertFalse($wide->isCompact());
        self::assertTrue($compact->isCompact());
        self::assertSame(EnergyChart::COMPACT_WIDTH, $compact->width);
        $bar = $compact->days[13]['bar'];
        self::assertLessThanOrEqual($compact->plotRight, $bar['x'] + $bar['width']);
        self::assertLessThan(count($wide->xTicks), count($compact->xTicks));
        $ticks = $compact->xTicks;
        self::assertSame('1 Oct', end($ticks)['label']);
        self::assertSame(array_column($wide->yTicks, 'value'), array_column($compact->yTicks, 'value'), 'same scale, only narrower');
    }

    public function testWeeklyBarsAverageTheLoggedDaysOfEachWeek(): void
    {
        // 14 days ending Thu 1 Oct → weeks 18–24 Sep and 25 Sep – 1 Oct.
        $burned = [];
        for ($i = 13; $i >= 0; --$i) {
            $burned[(new \DateTimeImmutable('2026-10-01'))->modify("-$i days")->format('Y-m-d')] = 2000.0;
        }
        $burned['2026-09-28'] = 2600.0; // workout day

        $chart = EnergyChart::build(
            ['2026-09-19' => 1800.0, '2026-09-28' => 2600.0, '2026-09-29' => 1800.0],
            $burned,
            '2026-10-01',
            '2026-10-01',
            14,
            daysPerBar: 7,
        );

        self::assertCount(2, $chart->days);
        self::assertSame(7, $chart->daysPerBar);
        self::assertSame('18 – 24 Sep', $chart->days[0]['label']);
        self::assertSame('25 Sep – 1 Oct (so far)', $chart->days[1]['label']);
        self::assertSame(1800.0, $chart->days[0]['eaten'], 'only the logged day counts, not 7');
        self::assertSame(2200.0, $chart->days[1]['eaten']);
        self::assertSame(2300.0, $chart->days[1]['burned'], 'burned averaged over the same logged days');
        self::assertSame(EnergyChart::STATUS_UNDER, $chart->days[1]['status']);
        self::assertSame('2026-09-18', $chart->from);
    }

    public function testAlcoholIsTheTopPartOfTheBar(): void
    {
        $chart = EnergyChart::build(
            ['2026-09-30' => 2000.0, '2026-10-01' => 1500.0],
            ['2026-09-30' => 2500.0, '2026-10-01' => 2500.0],
            '2026-10-01',
            '2026-10-02',
            7,
            alcohol: ['2026-09-30' => 500.0, '2026-09-29' => 300.0 /* no meals logged that day */],
        );

        self::assertTrue($chart->hasAlcohol);
        $day = $chart->days[5];
        self::assertSame(500.0, $day['alcohol']);
        self::assertSame($day['bar']['y'], $day['alcoholBar']['y'], 'starts at the top of the bar');
        self::assertEqualsWithDelta($day['bar']['height'] / 4, $day['alcoholBar']['height'], 0.2, '500 of 2000 kcal');
        self::assertNull($chart->days[6]['alcoholBar'], 'no alcohol that day');
        self::assertNull($chart->days[4]['alcoholBar'], 'no bar without meals');
        self::assertSame(['series' => 'alcohol', 'value' => '500 kcal', 'name' => 'of it alcohol'], $chart->hover()[5]['rows'][1]);
        self::assertFalse(EnergyChart::build(['2026-10-01' => 1500.0], [], '2026-10-01', '2026-10-01', 7)->hasAlcohol);
    }

    public function testWeeklyBarsAverageAlcoholPerLoggedDay(): void
    {
        $chart = EnergyChart::build(
            ['2026-09-26' => 2000.0, '2026-09-27' => 3000.0],
            [],
            '2026-10-01',
            '2026-10-01',
            7,
            daysPerBar: 7,
            alcohol: ['2026-09-27' => 1000.0],
        );

        self::assertSame(2500.0, $chart->days[0]['eaten']);
        self::assertSame(500.0, $chart->days[0]['alcohol'], '1000 kcal over 2 logged days');
    }

    public function testManyBarsLeaveNoRoomForOverLabels(): void
    {
        self::assertTrue(EnergyChart::build([], [], '2026-10-01', '2026-10-01', 7)->roomForLabels);
        self::assertTrue(EnergyChart::build([], [], '2026-10-01', '2026-10-01', 7, EnergyChart::COMPACT_WIDTH)->roomForLabels);
        self::assertFalse(EnergyChart::build([], [], '2026-10-01', '2026-10-01', 30)->roomForLabels);
    }

    /** @return iterable<string, array{float}> */
    public static function extremeDays(): iterable
    {
        yield 'huge day (regression: crashed the dashboard)' => [20850.0];
        yield 'absurd day beyond the largest step' => [250000.0];
    }

    #[DataProvider('extremeDays')]
    public function testExtremeValuesStillRender(float $kcal): void
    {
        $chart = EnergyChart::build(['2026-10-01' => $kcal], ['2026-10-01' => 2903.0], '2026-10-01', '2026-10-01');

        $ticks = array_column($chart->yTicks, 'value');
        self::assertGreaterThanOrEqual($kcal, end($ticks));
        self::assertGreaterThanOrEqual($chart->plotTop, $chart->days[13]['bar']['y']);
    }
}
