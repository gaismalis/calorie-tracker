<?php

namespace App\Summary;

use App\Energy\EnergyEstimate;

/**
 * "How did the last 7 days go": average eaten vs burned, what that means in kg per week next to the
 * goal, what the weight trend actually did, and exercise. Pure calculation, no I/O.
 *
 * Averages only count days with logged meals (a day without meals is unknown, not zero), and burned
 * on those days is the everyday burn plus that day's logged exercise, as everywhere else in the app.
 */
final readonly class WeeklySummary
{
    public const DAYS = 7;
    /** Fewer logged days than this and the averages are flagged as rough. */
    public const RELIABLE_DAYS = 4;
    /** Within this many kg/week of the goal counts as on track (estimates aren't more precise). */
    public const ON_TRACK_KG = 0.1;
    public const STATUS_ON_TRACK = 'on_track';
    public const STATUS_FASTER = 'faster';
    public const STATUS_SLOWER = 'slower';
    public const STATUS_WRONG_WAY = 'wrong_way';

    private function __construct(
        public string $from,
        /** Days covered: 7, or 6 when the week shown ends today (today is left out until it's over). */
        public int $days,
        public string $to,
        public int $loggedDays,
        public ?float $averageEaten,
        public ?float $averageBurned,
        public float $exerciseKcal,
        public int $exerciseDays,
        public ?float $trendChangeKg,
        public float $goalKgPerWeek,
        /** Total kcal eaten in the week (logged days). */
        public float $eatenKcal = 0.0,
        /** Total kcal of alcoholic drinks and of snacks in the week (part of $eatenKcal). */
        public float $alcoholKcal = 0.0,
        public float $snackKcal = 0.0,
    ) {
    }

    /**
     * @param array<string, float> $eaten    kcal eaten by date ('Y-m-d'); days without meals absent
     * @param array<string, float> $exercise kcal of logged exercise by date
     * @param array<string, float> $trend    weight trend kg by date (see WeightTrend::daily), may start before $from
     * @param float|null           $baseline everyday burn without workouts (null when unknown)
     * @param string               $to       last day of the week ('Y-m-d', inclusive)
     * @param array<string, float> $alcohol  kcal of alcoholic drinks by date
     * @param array<string, float> $snacks   kcal of snacks by date
     * @param int                  $days     days covered, ending on $to
     */
    public static function calculate(array $eaten, array $exercise, array $trend, ?float $baseline, string $to, float $goalKgPerWeek, array $alcohol = [], array $snacks = [], int $days = self::DAYS): self
    {
        $last = new \DateTimeImmutable($to);
        $dates = [];
        for ($i = $days - 1; $i >= 0; --$i) {
            $dates[] = $last->modify("-$i days")->format('Y-m-d');
        }

        $logged = array_values(array_filter($dates, fn (string $date) => isset($eaten[$date])));
        $average = fn (array $values) => [] === $values ? null : round(array_sum($values) / count($values));

        $exerciseInWeek = array_filter(array_intersect_key($exercise, array_flip($dates)), fn (float $kcal) => $kcal > 0);

        return new self(
            from: $dates[0],
            days: $days,
            to: $to,
            loggedDays: count($logged),
            averageEaten: $average(array_map(fn (string $date) => $eaten[$date], $logged)),
            averageBurned: null === $baseline ? null : $average(array_map(fn (string $date) => $baseline + ($exercise[$date] ?? 0.0), $logged)),
            exerciseKcal: round(array_sum($exerciseInWeek)),
            exerciseDays: count($exerciseInWeek),
            trendChangeKg: self::trendChange($trend, $last->modify('-'.$days.' days')->format('Y-m-d'), $to),
            goalKgPerWeek: $goalKgPerWeek,
            eatenKcal: round(array_sum(array_map(fn (string $date) => $eaten[$date], $logged))),
            alcoholKcal: round(array_sum(array_intersect_key($alcohol, array_flip($dates)))),
            snackKcal: round(array_sum(array_intersect_key($snacks, array_flip($dates)))),
        );
    }

    /** Share of everything eaten this week, in %, e.g. for alcohol: share($week->alcoholKcal). */
    public function share(float $kcal): ?int
    {
        return $this->eatenKcal > 0 ? (int) round($kcal / $this->eatenKcal * 100) : null;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->loggedDays && 0 === $this->exerciseDays && null === $this->trendChangeKg;
    }

    public function isRough(): bool
    {
        return $this->loggedDays < self::RELIABLE_DAYS;
    }

    /** Average eaten minus burned per logged day: negative = deficit. */
    public function getDailyBalance(): ?float
    {
        return null === $this->averageEaten || null === $this->averageBurned ? null : $this->averageEaten - $this->averageBurned;
    }

    /** What the logged balance would do to body weight over a week (7700 kcal ≈ 1 kg). */
    public function getExpectedKgPerWeek(): ?float
    {
        $balance = $this->getDailyBalance();

        return null === $balance ? null : round($balance * 7 / EnergyEstimate::KCAL_PER_KG, 2);
    }

    /** How the expected pace compares with the weekly goal; null without a pace. */
    public function getGoalStatus(): ?string
    {
        $expected = $this->getExpectedKgPerWeek();
        if (null === $expected) {
            return null;
        }
        if (abs($expected - $this->goalKgPerWeek) <= self::ON_TRACK_KG) {
            return self::STATUS_ON_TRACK;
        }
        if (0.0 === $this->goalKgPerWeek) {
            return self::STATUS_WRONG_WAY; // maintaining, but drifting up or down
        }
        // Same direction as the goal: faster or slower; opposite direction: the wrong way.
        if (($expected > 0) !== ($this->goalKgPerWeek > 0) || 0.0 === $expected) {
            return self::STATUS_WRONG_WAY;
        }

        return abs($expected) > abs($this->goalKgPerWeek) ? self::STATUS_FASTER : self::STATUS_SLOWER;
    }

    /**
     * Trend change over the week: from the trend the day before the week (or the earliest trend within
     * it) to the latest trend within it. Null when the trend doesn't cover at least two of those days.
     *
     * @param array<string, float> $trend
     */
    private static function trendChange(array $trend, string $dayBefore, string $to): ?float
    {
        $inRange = array_filter($trend, fn (string $date) => $date >= $dayBefore && $date <= $to, ARRAY_FILTER_USE_KEY);
        ksort($inRange);
        if (count($inRange) < 2) {
            return null;
        }

        return round(end($inRange) - reset($inRange), 2);
    }
}
