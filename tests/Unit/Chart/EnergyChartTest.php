<?php

namespace App\Tests\Unit\Chart;

use App\Chart\EnergyChart;
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
        $ticks = $chart->xTicks;
        self::assertSame('1 Oct', end($ticks)['label']);
    }

    public function testCompactVersionFitsAPhoneWithFewerDateLabels(): void
    {
        $wide = EnergyChart::build(['2026-10-01' => 2000.0], [], '2026-10-01', '2026-10-01');
        $compact = EnergyChart::build(['2026-10-01' => 2000.0], [], '2026-10-01', '2026-10-01', width: EnergyChart::COMPACT_WIDTH);

        self::assertFalse($wide->isCompact());
        self::assertTrue($compact->isCompact());
        self::assertSame(EnergyChart::COMPACT_WIDTH, $compact->width);
        self::assertLessThan(EnergyChart::COMPACT_WIDTH, $compact->plotRight);
        self::assertLessThanOrEqual($compact->plotRight, $compact->eatenDots[array_key_last($compact->eatenDots)]['x']);
        self::assertLessThan(count($wide->xTicks), count($compact->xTicks));
        $ticks = $compact->xTicks;
        self::assertSame('1 Oct', end($ticks)['label']);
        self::assertSame($wide->yTicks[0]['value'], $compact->yTicks[0]['value'], 'same scale, only narrower');
    }

    public function testEatenLineHasGapsOnDaysWithoutMeals(): void
    {
        $chart = EnergyChart::build(
            ['2026-09-28' => 2000.0, '2026-09-29' => 2100.0, '2026-10-01' => 1900.0],
            [],
            '2026-10-01',
            '2026-10-02',
        );

        self::assertCount(2, $chart->eatenPaths, 'Sep 30 not logged → two separate lines');
        self::assertCount(3, $chart->eatenDots);
        self::assertSame('', $chart->burnedPath);
        self::assertNull($chart->days[12]['eaten'], 'Sep 30');
        self::assertSame(['series' => 'eaten', 'value' => 'not logged', 'name' => 'Eaten'], $chart->hover()[12]['rows'][0]);
    }

    public function testBurnedLineAndTooltipRows(): void
    {
        $burned = [];
        for ($i = 13; $i >= 0; --$i) {
            $burned[(new \DateTimeImmutable('2026-10-01'))->modify("-$i days")->format('Y-m-d')] = 2300.0;
        }
        $burned['2026-09-30'] = 2900.0; // workout day

        $chart = EnergyChart::build(['2026-09-30' => 2450.0], $burned, '2026-10-01', '2026-10-01');

        self::assertMatchesRegularExpression('/^M[\d.]+ [\d.]+( L[\d.]+ [\d.]+){13}$/', $chart->burnedPath);
        self::assertSame([
            ['series' => 'eaten', 'value' => '2 450 kcal', 'name' => 'Eaten'],
            ['series' => 'burned', 'value' => '2 900 kcal', 'name' => 'Burned'],
        ], $chart->hover()[12]['rows']);
        $ticks = array_column($chart->yTicks, 'value');
        self::assertLessThanOrEqual(2300, $ticks[0]);
        self::assertGreaterThanOrEqual(2900, end($ticks));
        self::assertLessThanOrEqual(6, count($ticks));
    }

    /** @return iterable<string, array{float}> */
    public static function extremeDays(): iterable
    {
        yield 'huge day (regression: crashed the dashboard)' => [20850.0];
        yield 'absurd day beyond the largest step' => [250000.0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('extremeDays')]
    public function testExtremeValuesStillRender(float $kcal): void
    {
        $burned = [];
        for ($i = 13; $i >= 0; --$i) {
            $burned[(new \DateTimeImmutable('2026-10-01'))->modify("-$i days")->format('Y-m-d')] = 2903.0;
        }

        $chart = EnergyChart::build(['2026-10-01' => $kcal], $burned, '2026-10-01', '2026-10-01');

        $ticks = array_column($chart->yTicks, 'value');
        self::assertGreaterThanOrEqual($kcal, end($ticks));
        self::assertLessThanOrEqual(2903, $ticks[0]);
        foreach ($chart->eatenDots as $dot) {
            self::assertGreaterThanOrEqual($chart->plotTop, $dot['y']);
        }
    }
}
