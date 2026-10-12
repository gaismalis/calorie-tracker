<?php

namespace App\Tests\Unit\Chart;

use App\Chart\EnergyChart;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnergyChartTest extends TestCase
{
    public function testFourteenDaysEndingOnTheShownDay(): void
    {
        $chart = EnergyChart::build([], [], [], '2026-10-01', '2026-10-01');

        self::assertCount(14, $chart->days);
        self::assertSame('Fri, 18 Sep', $chart->days[0]['label']);
        self::assertSame('Thu, 1 Oct (so far)', $chart->days[13]['label']);
        self::assertTrue($chart->empty);
        self::assertFalse($chart->hasTargets);
        $ticks = $chart->xTicks;
        self::assertSame('1 Oct', end($ticks)['label']);
    }

    public function testDaysWithoutMealsHaveNoBar(): void
    {
        $chart = EnergyChart::build(['2026-09-28' => 2000.0, '2026-10-01' => 1900.0], [], [], '2026-10-01', '2026-10-02');

        self::assertNotNull($chart->days[10]['bar'], 'Sep 28');
        self::assertNull($chart->days[11]['bar'], 'Sep 29: not logged, not zero');
        self::assertNull($chart->days[11]['status']);
        self::assertSame(EnergyChart::STATUS_UNKNOWN, $chart->days[13]['status'], 'eaten, but no target to compare with');
        self::assertNull($chart->days[13]['targetMark']);
        self::assertSame(['series' => 'eaten-none', 'value' => 'not logged', 'name' => 'Eaten'], $chart->hover()[11]['rows'][0]);
    }

    public function testBarsStartAtZeroAndReachTheirValue(): void
    {
        $chart = EnergyChart::build(['2026-10-01' => 2000.0], [], [], '2026-10-01', '2026-10-01');

        self::assertSame(0, $chart->yTicks[0]['value']);
        $bar = $chart->days[13]['bar'];
        self::assertEqualsWithDelta($chart->plotBottom, $bar['y'] + $bar['height'], 0.11);
        $tick2000 = array_values(array_filter($chart->yTicks, fn ($t) => 2000 === $t['value']))[0];
        self::assertEqualsWithDelta($tick2000['y'], $bar['y'], 0.11);
        self::assertGreaterThanOrEqual($chart->plotLeft, $bar['x']);
        self::assertLessThan($chart->days[13]['x'], $bar['x'], 'bar is centred on its day');
    }

    public function testEachDayIsComparedWithItsOwnTarget(): void
    {
        $targets = $burned = [];
        for ($i = 13; $i >= 0; --$i) {
            $date = (new \DateTimeImmutable('2026-10-01'))->modify("-$i days")->format('Y-m-d');
            $targets[$date] = 2000.0;
            $burned[$date] = 2500.0;
        }
        $targets['2026-09-30'] = 2600.0; // workout day: higher target

        $chart = EnergyChart::build(
            ['2026-09-29' => 2400.0, '2026-09-30' => 2400.0, '2026-10-01' => 1200.0],
            $burned,
            $targets,
            '2026-10-01',
            '2026-10-01',
        );

        self::assertTrue($chart->hasTargets);
        self::assertSame(EnergyChart::STATUS_OVER, $chart->days[11]['status']);
        self::assertSame(400.0, $chart->days[11]['overBy']);
        self::assertSame(EnergyChart::STATUS_WITHIN, $chart->days[12]['status'], 'same intake, but the workout raised the target');
        self::assertNull($chart->days[12]['overBy']);
        self::assertGreaterThan($chart->days[12]['targetMark']['y'], $chart->days[11]['targetMark']['y'], 'higher target is drawn higher');
        self::assertNotNull($chart->days[0]['targetMark'], 'target shown on days without meals too');

        self::assertSame([
            ['series' => 'eaten-over', 'value' => '2 400 kcal', 'name' => 'Eaten'],
            ['series' => 'target', 'value' => '2 000 kcal', 'name' => 'Target'],
            ['series' => 'burned', 'value' => '2 500 kcal', 'name' => 'Burned'],
            ['series' => 'balance', 'value' => '400 kcal', 'name' => 'over target'],
        ], $chart->hover()[11]['rows']);
        self::assertSame(['series' => 'balance', 'value' => '200 kcal', 'name' => 'under target'], $chart->hover()[12]['rows'][3]);
        self::assertSame(['series' => 'balance', 'value' => '800 kcal', 'name' => 'left'], $chart->hover()[13]['rows'][3], 'today: what is left');
        self::assertTrue($chart->days[13]['today']);
    }

    public function testEatingExactlyTheTargetIsWithin(): void
    {
        $chart = EnergyChart::build(['2026-10-01' => 2000.4], [], ['2026-10-01' => 2000.0], '2026-10-01', '2026-10-02');

        self::assertSame(EnergyChart::STATUS_WITHIN, $chart->days[13]['status']);
    }

    public function testCompactVersionFitsAPhoneWithFewerDateLabels(): void
    {
        $wide = EnergyChart::build(['2026-10-01' => 2000.0], [], [], '2026-10-01', '2026-10-01');
        $compact = EnergyChart::build(['2026-10-01' => 2000.0], [], [], '2026-10-01', '2026-10-01', width: EnergyChart::COMPACT_WIDTH);

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

    /** @return iterable<string, array{float}> */
    public static function extremeDays(): iterable
    {
        yield 'huge day (regression: crashed the dashboard)' => [20850.0];
        yield 'absurd day beyond the largest step' => [250000.0];
    }

    #[DataProvider('extremeDays')]
    public function testExtremeValuesStillRender(float $kcal): void
    {
        $chart = EnergyChart::build(['2026-10-01' => $kcal], [], ['2026-10-01' => 2903.0], '2026-10-01', '2026-10-01');

        $ticks = array_column($chart->yTicks, 'value');
        self::assertGreaterThanOrEqual($kcal, end($ticks));
        self::assertGreaterThanOrEqual($chart->plotTop, $chart->days[13]['bar']['y']);
    }
}
