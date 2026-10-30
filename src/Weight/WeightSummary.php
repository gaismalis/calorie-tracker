<?php

namespace App\Weight;

use App\Entity\WeightEntry;

/**
 * The weight page's overview: where the trend is now, the change since the first weigh-in, the pace of the
 * last weeks against the goal, and the history grouped into weeks (Monday to Sunday) with their averages.
 * Pure calculation: give it the weigh-ins and the trend (WeightTrend::daily), get numbers back.
 */
final readonly class WeightSummary
{
    /** Days the pace is measured over, and the fewest that give a meaningful pace. */
    public const PACE_DAYS = 28;
    private const MIN_PACE_DAYS = 14;
    /** "Keep my weight": steady while the trend moves less than this per week. */
    private const STEADY_KG_PER_WEEK = 0.2;

    public const ON_TRACK = 'on_track';
    public const SLOWER = 'slower';
    public const FASTER = 'faster';
    public const WRONG_WAY = 'wrong_way';

    /**
     * @param list<array{from: \DateTimeImmutable, to: \DateTimeImmutable, entries: list<WeightEntry>, averageKg: float, change: float|null, toward: bool|null}> $weeks
     *                                                                                                                                                         newest first; change = average minus the previous week's; toward = whether that change goes the way of the goal
     */
    private function __construct(
        public ?WeightEntry $latest,
        public ?float $trendKg,
        public ?\DateTimeImmutable $since,
        public ?float $totalChange,
        public ?float $pacePerWeek,
        public int $paceDays,
        public float $goalPerWeek,
        public ?string $status,
        public array $weeks,
    ) {
    }

    /**
     * @param list<WeightEntry>    $entries weigh-ins to list, newest first
     * @param array<string, float> $weights every weigh-in, kg by 'Y-m-d' (for the change since the start)
     * @param array<string, float> $trend   daily trend over the same weigh-ins
     */
    public static function build(array $entries, array $weights, array $trend, float $goalPerWeek): self
    {
        ksort($weights);
        ksort($trend);
        if ([] === $weights || [] === $trend) {
            return new self(null, null, null, null, null, 0, $goalPerWeek, null, []);
        }

        $lastDay = array_key_last($trend);
        $trendKg = round($trend[$lastDay], 1);
        $firstDay = array_key_first($weights);

        // Pace: how the trend moved over the last PACE_DAYS (or since the first weigh-in, if at least MIN_PACE_DAYS ago).
        $paceFrom = max($firstDay, (new \DateTimeImmutable($lastDay))->modify(sprintf('-%d days', self::PACE_DAYS))->format('Y-m-d'));
        $paceDays = (int) (new \DateTimeImmutable($paceFrom))->diff(new \DateTimeImmutable($lastDay))->days;
        $pace = $paceDays >= self::MIN_PACE_DAYS && isset($trend[$paceFrom])
            ? round(($trend[$lastDay] - $trend[$paceFrom]) / $paceDays * 7, 2)
            : null;

        return new self(
            $entries[0] ?? null,
            $trendKg,
            new \DateTimeImmutable($firstDay),
            round($trend[$lastDay] - $weights[$firstDay], 1),
            $pace,
            $paceDays,
            $goalPerWeek,
            null === $pace ? null : self::status($pace, $goalPerWeek),
            self::weeks($entries, $goalPerWeek),
        );
    }

    private static function status(float $pace, float $goal): string
    {
        if (0.0 === $goal) {
            return abs($pace) <= self::STEADY_KG_PER_WEEK ? self::ON_TRACK : self::WRONG_WAY;
        }
        $share = $pace / $goal; // 1 = exactly the goal's pace
        if ($share <= 0) {
            return self::WRONG_WAY;
        }

        return match (true) {
            $share < 0.5 => self::SLOWER,
            $share > 1.75 => self::FASTER,
            default => self::ON_TRACK,
        };
    }

    /**
     * @param list<WeightEntry> $entries newest first
     *
     * @return list<array{from: \DateTimeImmutable, to: \DateTimeImmutable, entries: list<WeightEntry>, averageKg: float, change: float|null, toward: bool|null}>
     */
    private static function weeks(array $entries, float $goal): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $monday = $entry->getDate()->modify('monday this week')->format('Y-m-d');
            $groups[$monday][] = $entry;
        }

        $weeks = [];
        foreach ($groups as $monday => $weekEntries) {
            $from = new \DateTimeImmutable($monday);
            $weeks[] = [
                'from' => $from,
                'to' => $from->modify('+6 days'),
                'entries' => $weekEntries,
                'averageKg' => round(array_sum(array_map(fn (WeightEntry $entry) => $entry->getWeightKg(), $weekEntries)) / count($weekEntries), 1),
                'change' => null,
                'toward' => null,
            ];
        }
        foreach ($weeks as $i => $week) {
            $previous = $weeks[$i + 1] ?? null; // the week before (newest first)
            if (null !== $previous) {
                $change = round($week['averageKg'] - $previous['averageKg'], 1);
                $weeks[$i]['change'] = $change;
                $weeks[$i]['toward'] = match (true) {
                    $goal < 0 => $change < 0,
                    $goal > 0 => $change > 0,
                    default => abs($change) <= self::STEADY_KG_PER_WEEK,
                };
            }
        }

        return $weeks;
    }
}
