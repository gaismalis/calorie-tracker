<?php

namespace App\Tests\Unit\Energy;

use App\Energy\WeightTrend;
use PHPUnit\Framework\TestCase;

class WeightTrendTest extends TestCase
{
    public function testEmptyAndSingleWeighIn(): void
    {
        self::assertSame([], WeightTrend::daily([]));
        self::assertSame(['2026-09-01' => 80.0], WeightTrend::daily(['2026-09-01' => 80.0]));
    }

    public function testSmoothsTowardsNewWeightsByTenPercentPerDay(): void
    {
        $trend = WeightTrend::daily(['2026-09-01' => 80.0, '2026-09-02' => 81.0, '2026-09-03' => 81.0]);

        self::assertSame(['2026-09-01' => 80.0, '2026-09-02' => 80.1, '2026-09-03' => 80.19], $trend);
    }

    public function testFillsGapsByInterpolationAndIgnoresInputOrder(): void
    {
        $trend = WeightTrend::daily(['2026-09-03' => 82.0, '2026-09-01' => 80.0]);

        // day 2 interpolated to 81: 80 + 0.1 × (81 − 80) = 80.1; day 3: 80.1 + 0.1 × (82 − 80.1) = 80.29
        self::assertSame(['2026-09-01' => 80.0, '2026-09-02' => 80.1, '2026-09-03' => 80.29], $trend);
    }

    public function testOneHeavyDayBarelyMovesTheTrend(): void
    {
        $weights = [];
        foreach (range(1, 20) as $day) {
            $weights[sprintf('2026-09-%02d', $day)] = 80.0;
        }
        $weights['2026-09-21'] = 81.5; // salty dinner, water weight

        $trend = WeightTrend::daily($weights);

        self::assertSame(80.15, $trend['2026-09-21']);
    }

    public function testSpansDaylightSavingChange(): void
    {
        $trend = WeightTrend::daily(['2026-10-24' => 80.0, '2026-10-26' => 80.0]);

        self::assertSame(['2026-10-24', '2026-10-25', '2026-10-26'], array_keys($trend));
    }
}
