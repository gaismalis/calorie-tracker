<?php

namespace App\Tests\Unit\Weight;

use App\Entity\User;
use App\Entity\WeightEntry;
use App\Weight\WeightSummary;
use PHPUnit\Framework\TestCase;

class WeightSummaryTest extends TestCase
{
    public function testNothingLoggedYet(): void
    {
        $summary = WeightSummary::build([], [], [], -0.5);

        self::assertNull($summary->trendKg);
        self::assertNull($summary->status);
        self::assertSame([], $summary->weeks);
    }

    public function testChangeSinceTheFirstWeighInAndPaceOverTheLast4Weeks(): void
    {
        // Trend down 2 kg in the last 28 days: 0.5 kg a week.
        $trend = $this->line('2026-09-01', '2026-10-27', 90.0, 87.0);
        $trend['2026-09-29'] = 89.0;
        $trend['2026-10-27'] = 87.0;
        $summary = WeightSummary::build([], ['2026-09-01' => 90.4, '2026-10-27' => 86.8], $trend, -0.5);

        self::assertSame(87.0, $summary->trendKg);
        self::assertSame('2026-09-01', $summary->since->format('Y-m-d'));
        self::assertSame(-3.4, $summary->totalChange, 'from the first weigh-in to the trend now');
        self::assertSame(-0.5, $summary->pacePerWeek);
        self::assertSame(28, $summary->paceDays);
        self::assertSame(WeightSummary::ON_TRACK, $summary->status);
    }

    public function testNoPaceBeforeTwoWeeks(): void
    {
        $trend = $this->line('2026-10-20', '2026-10-27', 80.0, 79.5);
        $summary = WeightSummary::build([], ['2026-10-20' => 80.0], $trend, -0.5);

        self::assertNull($summary->pacePerWeek);
        self::assertNull($summary->status);
        self::assertSame(7, $summary->paceDays);
    }

    /** @return iterable<string, array{float, float, string}> */
    public static function paces(): iterable
    {
        yield 'losing as planned' => [-0.5, -0.45, WeightSummary::ON_TRACK];
        yield 'losing slowly' => [-0.5, -0.2, WeightSummary::SLOWER];
        yield 'losing too fast' => [-0.5, -1.0, WeightSummary::FASTER];
        yield 'gaining while losing' => [-0.5, 0.1, WeightSummary::WRONG_WAY];
        yield 'gaining as planned' => [0.25, 0.3, WeightSummary::ON_TRACK];
        yield 'keeping steady' => [0.0, -0.15, WeightSummary::ON_TRACK];
        yield 'keeping, but drifting' => [0.0, 0.4, WeightSummary::WRONG_WAY];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paces')]
    public function testStatusAgainstTheGoal(float $goal, float $pace, string $status): void
    {
        $trend = ['2026-09-29' => 80.0, '2026-10-27' => 80.0 + $pace * 4];
        $summary = WeightSummary::build([], ['2026-09-29' => 80.0], $trend, $goal);

        self::assertSame($status, $summary->status);
    }

    public function testWeeksRunMondayToSundayWithAveragesAndTheirDirection(): void
    {
        $user = new User();
        $entries = [ // newest first, as the repository returns them
            new WeightEntry($user, new \DateTimeImmutable('2026-10-25'), 85.0), // Sunday
            new WeightEntry($user, new \DateTimeImmutable('2026-10-19'), 85.4), // Monday, same week
            new WeightEntry($user, new \DateTimeImmutable('2026-10-14'), 86.0),
            new WeightEntry($user, new \DateTimeImmutable('2026-10-12'), 85.6),
        ];

        $weeks = WeightSummary::build($entries, ['2026-10-12' => 85.6], ['2026-10-25' => 85.3], -0.5)->weeks;

        self::assertSame(['2026-10-19', '2026-10-12'], array_map(fn ($week) => $week['from']->format('Y-m-d'), $weeks));
        self::assertSame('2026-10-25', $weeks[0]['to']->format('Y-m-d'));
        self::assertCount(2, $weeks[0]['entries']);
        self::assertSame(85.2, $weeks[0]['averageKg']);
        self::assertSame(85.8, $weeks[1]['averageKg']);
        self::assertSame(-0.6, $weeks[0]['change']);
        self::assertTrue($weeks[0]['toward'], 'down is the way of a "lose" goal');
        self::assertNull($weeks[1]['change'], 'nothing to compare the oldest week with');
    }

    /** @return array<string, float> a straight line, one value per day */
    private function line(string $from, string $to, float $start, float $end): array
    {
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
        $line = [];
        for ($i = 0; $i <= $days; ++$i) {
            $line[(new \DateTimeImmutable($from))->modify("+$i days")->format('Y-m-d')] = round($start + ($end - $start) * $i / $days, 3);
        }

        return $line;
    }
}
