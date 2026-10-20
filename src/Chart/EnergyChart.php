<?php

namespace App\Chart;

/**
 * Geometry for the "eaten vs burned" chart on the dashboard: one bar per day (what was eaten) with a
 * mark at what was burned that day (baseline + logged exercise, no goal adjustment). Days where more was
 * eaten than burned are flagged. Days without logged meals have no bar (unknown, not zero); today is
 * marked as still in progress.
 *
 * The alcohol part of what was eaten is the top part of the bar, and the snack part is right below it,
 * each in its own colour.
 *
 * Long ranges use one bar per week instead ($daysPerBar = 7): eaten and burned are then averages per
 * logged day of that week, so a bar stays comparable with a single day.
 */
final readonly class EnergyChart
{
    public const WIDTH = 680;
    /** Width of the version shown on phones, close to the card's real width so text keeps its size. */
    public const COMPACT_WIDTH = 340;
    public const HEIGHT = 220;
    public const STATUS_UNDER = 'under';
    public const STATUS_OVER = 'over';
    /** Eaten, but there's no burn estimate to compare with yet. */
    public const STATUS_UNKNOWN = 'unknown';
    private const MARGIN_LEFT = 48;
    private const MARGIN_RIGHT = 16;
    private const MARGIN_TOP = 18; // room for the "+900" label above the tallest bar
    private const MARGIN_BOTTOM = 28;
    private const BAR_SHARE = 0.6;
    private const BURNED_MARK_SHARE = 0.9;
    /** Narrower bar slots than this have no room for "+900" labels (they would touch). */
    private const MIN_SLOT_FOR_LABELS = 36;
    private const Y_STEPS = [250, 500, 1000, 2000, 5000, 10000];

    /**
     * @param list<array{value: int, y: float}>  $yTicks
     * @param list<array{label: string, x: float}> $xTicks
     * @param list<array{
     *     x: float, label: string, eaten: float|null, burned: float|null, today: bool,
     *     status: string|null, overBy: float|null, bar: array{x: float, y: float, width: float, height: float}|null,
     *     burnedMark: array{x1: float, x2: float, y: float}|null,
     *     alcohol: float|null, alcoholBar: array{x: float, y: float, width: float, height: float}|null,
     *     snacks: float|null, snackBar: array{x: float, y: float, width: float, height: float}|null
     * }> $days one entry per bar (a day, or a week when $daysPerBar is 7)
     */
    private function __construct(
        public array $yTicks,
        public array $xTicks,
        public array $days,
        public bool $empty,
        public bool $hasBurned,
        public float $plotLeft,
        public float $plotRight,
        public float $plotTop,
        public float $plotBottom,
        public int $width,
        public int $daysPerBar,
        public bool $roomForLabels,
        /** First date shown ('Y-m-d'). */
        public string $from,
        public bool $hasAlcohol,
        public bool $hasSnacks,
    ) {
    }

    /**
     * @param array<string, float> $eaten      kcal eaten by date ('Y-m-d'); days without meals absent
     * @param array<string, float> $burned     kcal burned by date (baseline + exercise); empty when unknown
     * @param string               $lastDay    last date shown ('Y-m-d')
     * @param string               $today      today's date, marked as "so far"
     * @param int                  $days       number of days shown (a multiple of $daysPerBar)
     * @param int                  $width      drawing width (WIDTH, or COMPACT_WIDTH for phones)
     * @param int                  $daysPerBar 1 (a bar per day) or 7 (a bar per week, for long ranges)
     * @param array<string, float> $alcohol    kcal of alcoholic drinks by date (part of $eaten)
     * @param array<string, float> $snacks     kcal of snacks by date (part of $eaten)
     */
    public static function build(array $eaten, array $burned, string $lastDay, string $today, int $days = 14, int $width = self::WIDTH, int $daysPerBar = 1, array $alcohol = [], array $snacks = []): self
    {
        $last = new \DateTimeImmutable($lastDay);
        $dates = [];
        for ($i = $days - 1; $i >= 0; --$i) {
            $dates[] = $last->modify("-$i days")->format('Y-m-d');
        }
        $buckets = array_chunk($dates, $daysPerBar);
        $bars = count($buckets);

        $left = self::MARGIN_LEFT;
        $right = $width - self::MARGIN_RIGHT;
        $top = self::MARGIN_TOP;
        $bottom = self::HEIGHT - self::MARGIN_BOTTOM;
        $slot = ($right - $left) / $bars;
        $x = fn (int $i): float => round($left + $slot * ($i + 0.5), 1);

        // Eaten and burned per bar: a day's values, or a week's averages per logged day.
        $values = [];
        $perBar = [];
        foreach ($buckets as $bucket) {
            $logged = array_values(array_filter($bucket, fn (string $date) => isset($eaten[$date])));
            $withBurned = array_values(array_filter([] === $logged ? $bucket : $logged, fn (string $date) => isset($burned[$date])));
            $e = [] === $logged ? null : array_sum(array_map(fn ($d) => $eaten[$d], $logged)) / count($logged);
            $b = [] === $withBurned ? null : array_sum(array_map(fn ($d) => $burned[$d], $withBurned)) / count($withBurned);
            $average = fn (array $byDate) => [] === $logged ? null : array_sum(array_map(fn ($d) => $byDate[$d] ?? 0.0, $logged)) / count($logged);
            $a = $average($alcohol);
            $a = null !== $a && $a > 0 ? min($a, $e) : null;
            $s = $average($snacks);
            $s = null !== $s && $s > 0 ? min($s, $e - ($a ?? 0.0)) : null;
            $perBar[] = [$e, $b, $a, $s];
            array_push($values, ...array_filter([$e, $b], fn ($v) => null !== $v));
        }
        [$max, $step] = self::yDomain($values);
        $y = fn (float $kcal): float => round($bottom - ($bottom - $top) * $kcal / $max, 1);

        $chartDays = [];
        foreach ($buckets as $i => $bucket) {
            [$e, $b, $a, $s] = $perBar[$i];
            $barX = round($x($i) - $slot * self::BAR_SHARE / 2, 1);
            $barWidth = round($slot * self::BAR_SHARE, 1);
            $status = match (true) {
                null === $e => null,
                null === $b => self::STATUS_UNKNOWN,
                round($e) > round($b) => self::STATUS_OVER,
                default => self::STATUS_UNDER,
            };
            $isToday = in_array($today, $bucket, true);
            $chartDays[] = [
                'x' => $x($i),
                'label' => self::label($bucket).($isToday ? ' (so far)' : ''),
                'eaten' => null === $e ? null : round($e),
                'burned' => null === $b ? null : round($b),
                'today' => $isToday,
                'status' => $status,
                'overBy' => self::STATUS_OVER === $status ? round($e) - round($b) : null,
                'bar' => null === $e ? null : [
                    'x' => round($x($i) - $slot * self::BAR_SHARE / 2, 1),
                    'y' => $y($e),
                    'width' => round($slot * self::BAR_SHARE, 1),
                    'height' => round($bottom - $y($e), 1),
                ],
                'burnedMark' => null === $b ? null : [
                    'x1' => round($x($i) - $slot * self::BURNED_MARK_SHARE / 2, 1),
                    'x2' => round($x($i) + $slot * self::BURNED_MARK_SHARE / 2, 1),
                    'y' => $y($b),
                ],
                'alcohol' => null === $a ? null : round($a),
                // Top part of the bar: from the top of the bar down by the alcohol kcal.
                'alcoholBar' => null === $a ? null : [
                    'x' => $barX,
                    'y' => $y($e),
                    'width' => $barWidth,
                    'height' => round($y($e - $a) - $y($e), 1),
                ],
                'snacks' => null === $s ? null : round($s),
                // Right below the alcohol part (or at the top of the bar when there's no alcohol).
                'snackBar' => null === $s ? null : [
                    'x' => $barX,
                    'y' => $y($e - ($a ?? 0.0)),
                    'width' => $barWidth,
                    'height' => round($y($e - ($a ?? 0.0) - $s) - $y($e - ($a ?? 0.0)), 1),
                ],
            ];
        }

        $yTicks = [];
        for ($value = 0; $value <= $max + 1e-9; $value += $step) {
            $yTicks[] = ['value' => (int) $value, 'y' => $y($value)];
        }

        // At most ~6 date labels (4 on phones), so they don't touch; anchored on the last bar.
        $xTicks = [];
        $tickEvery = (int) max(1, ceil($bars / ($width < self::WIDTH ? 4 : 6)));
        for ($i = $bars - 1; $i >= 0; $i -= $tickEvery) {
            array_unshift($xTicks, ['label' => (new \DateTimeImmutable($buckets[$i][0]))->format('j M'), 'x' => $x($i)]);
        }

        $shown = fn (array $byDate) => array_intersect_key($byDate, array_flip($dates));

        return new self($yTicks, $xTicks, $chartDays, [] === $shown($eaten), [] !== $shown($burned), $left, $right, $top, $bottom,
            $width, $daysPerBar, $slot >= self::MIN_SLOT_FOR_LABELS, $dates[0], [] !== array_filter(array_column($perBar, 2)), [] !== array_filter(array_column($perBar, 3)));
    }

    public function isCompact(): bool
    {
        return $this->width < self::WIDTH;
    }

    /** @return list<array{x: float, label: string, rows: list<array{series: string, value: string, name: string}>}> tooltip content per day */
    public function hover(): array
    {
        $kcal = fn (float $value) => number_format($value, 0, '.', ' ').' kcal';

        return array_map(fn (array $day) => [
            'x' => $day['x'],
            'label' => $day['label'],
            'rows' => array_values(array_filter([
                ['series' => 'eaten-'.($day['status'] ?? 'none'), 'value' => null === $day['eaten'] ? 'not logged' : $kcal($day['eaten']), 'name' => 'Eaten'],
                null === $day['alcohol'] ? null : ['series' => 'alcohol', 'value' => $kcal($day['alcohol']), 'name' => 'of it alcohol'],
                null === $day['snacks'] ? null : ['series' => 'snacks', 'value' => $kcal($day['snacks']), 'name' => 'of it snacks'],
                null === $day['burned'] ? null : ['series' => 'burned', 'value' => $kcal($day['burned']), 'name' => 'Burned'],
                match ($day['status']) {
                    self::STATUS_OVER => ['series' => 'balance', 'value' => $kcal($day['overBy']), 'name' => 'more than burned'],
                    self::STATUS_UNDER => ['series' => 'balance', 'value' => $kcal($day['burned'] - $day['eaten']), 'name' => 'less than burned'.($day['today'] ? ' so far' : '')],
                    default => null,
                },
            ])),
        ], $this->days);
    }

    /**
     * "Mon, 5 Oct" for a day; "5 – 11 Oct" or "29 Sep – 5 Oct" for a week.
     *
     * @param list<string> $bucket
     */
    private static function label(array $bucket): string
    {
        $first = new \DateTimeImmutable($bucket[0]);
        if (1 === count($bucket)) {
            return $first->format('D, j M');
        }
        $last = new \DateTimeImmutable(end($bucket));

        return ($first->format('M') === $last->format('M') ? $first->format('j') : $first->format('j M')).' – '.$last->format('j M');
    }

    /**
     * Axis from 0 (bars must start at zero) up to a clean value above everything, at most 5 steps.
     *
     * @param list<float> $values
     *
     * @return array{float, float} max, step
     */
    private static function yDomain(array $values): array
    {
        $high = ([] === $values ? 2900 : max($values)) + 100;
        foreach (self::Y_STEPS as $step) {
            $max = ceil($high / $step) * $step;
            if ($max / $step <= 5) {
                return [$max, (float) $step];
            }
        }
        $step = (float) self::Y_STEPS[array_key_last(self::Y_STEPS)];

        return [ceil($high / $step) * $step, $step];
    }
}
