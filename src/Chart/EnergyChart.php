<?php

namespace App\Chart;

/**
 * Geometry for the "eaten vs target" chart on the dashboard: one bar per day (what was eaten) with a
 * mark at that day's target. Bars over the target are flagged, so "how did I do" is readable at a glance.
 * Days without logged meals have no bar (unknown, not zero); today is marked as still in progress.
 */
final readonly class EnergyChart
{
    public const WIDTH = 680;
    /** Width of the version shown on phones, close to the card's real width so text keeps its size. */
    public const COMPACT_WIDTH = 340;
    public const HEIGHT = 220;
    public const STATUS_WITHIN = 'within';
    public const STATUS_OVER = 'over';
    /** Eaten, but there's no target to compare with yet (no burn estimate). */
    public const STATUS_UNKNOWN = 'unknown';
    private const MARGIN_LEFT = 48;
    private const MARGIN_RIGHT = 16;
    private const MARGIN_TOP = 18; // room for the "+900" label above the tallest bar
    private const MARGIN_BOTTOM = 28;
    private const BAR_SHARE = 0.6;
    private const TARGET_MARK_SHARE = 0.9;
    private const Y_STEPS = [250, 500, 1000, 2000, 5000, 10000];

    /**
     * @param list<array{value: int, y: float}>  $yTicks
     * @param list<array{label: string, x: float}> $xTicks
     * @param list<array{
     *     x: float, label: string, eaten: float|null, burned: float|null, target: float|null, today: bool,
     *     status: string|null, overBy: float|null, bar: array{x: float, y: float, width: float, height: float}|null,
     *     targetMark: array{x1: float, x2: float, y: float}|null
     * }> $days
     */
    private function __construct(
        public array $yTicks,
        public array $xTicks,
        public array $days,
        public bool $empty,
        public bool $hasTargets,
        public float $plotLeft,
        public float $plotRight,
        public float $plotTop,
        public float $plotBottom,
        public int $width,
    ) {
    }

    /**
     * @param array<string, float> $eaten   kcal eaten by date ('Y-m-d'); days without meals absent
     * @param array<string, float> $burned  kcal burned by date (baseline + exercise); empty when unknown
     * @param array<string, float> $targets daily intake target by date; empty when unknown
     * @param string               $lastDay last date shown ('Y-m-d')
     * @param string               $today   today's date, marked as "so far"
     * @param int                  $width   drawing width (WIDTH, or COMPACT_WIDTH for phones)
     */
    public static function build(array $eaten, array $burned, array $targets, string $lastDay, string $today, int $days = 14, int $width = self::WIDTH): self
    {
        $last = new \DateTimeImmutable($lastDay);
        $dates = [];
        for ($i = $days - 1; $i >= 0; --$i) {
            $dates[] = $last->modify("-$i days")->format('Y-m-d');
        }

        $left = self::MARGIN_LEFT;
        $right = $width - self::MARGIN_RIGHT;
        $top = self::MARGIN_TOP;
        $bottom = self::HEIGHT - self::MARGIN_BOTTOM;
        $slot = ($right - $left) / $days;
        $x = fn (int $i): float => round($left + $slot * ($i + 0.5), 1);

        $values = [];
        foreach ($dates as $date) {
            foreach ([$eaten[$date] ?? null, $targets[$date] ?? null] as $value) {
                if (null !== $value) {
                    $values[] = $value;
                }
            }
        }
        [$max, $step] = self::yDomain($values);
        $y = fn (float $kcal): float => round($bottom - ($bottom - $top) * $kcal / $max, 1);

        $chartDays = [];
        foreach ($dates as $i => $date) {
            $e = $eaten[$date] ?? null;
            $target = $targets[$date] ?? null;
            $status = match (true) {
                null === $e => null,
                null === $target => self::STATUS_UNKNOWN,
                round($e) > round($target) => self::STATUS_OVER,
                default => self::STATUS_WITHIN,
            };
            $chartDays[] = [
                'x' => $x($i),
                'label' => (new \DateTimeImmutable($date))->format('D, j M').($date === $today ? ' (so far)' : ''),
                'eaten' => null === $e ? null : round($e),
                'burned' => isset($burned[$date]) ? round($burned[$date]) : null,
                'target' => null === $target ? null : round($target),
                'today' => $date === $today,
                'status' => $status,
                'overBy' => self::STATUS_OVER === $status ? round($e) - round($target) : null,
                'bar' => null === $e ? null : [
                    'x' => round($x($i) - $slot * self::BAR_SHARE / 2, 1),
                    'y' => $y($e),
                    'width' => round($slot * self::BAR_SHARE, 1),
                    'height' => round($bottom - $y($e), 1),
                ],
                'targetMark' => null === $target ? null : [
                    'x1' => round($x($i) - $slot * self::TARGET_MARK_SHARE / 2, 1),
                    'x2' => round($x($i) + $slot * self::TARGET_MARK_SHARE / 2, 1),
                    'y' => $y($target),
                ],
            ];
        }

        $yTicks = [];
        for ($value = 0; $value <= $max + 1e-9; $value += $step) {
            $yTicks[] = ['value' => (int) $value, 'y' => $y($value)];
        }

        $xTicks = [];
        $tickEvery = $width < self::WIDTH ? 4 : 3; // fewer date labels when narrow, so they don't touch
        for ($i = $days - 1; $i >= 0; $i -= $tickEvery) { // anchored on the last day
            array_unshift($xTicks, ['label' => (new \DateTimeImmutable($dates[$i]))->format('j M'), 'x' => $x($i)]);
        }

        $shown = fn (array $byDate) => array_intersect_key($byDate, array_flip($dates));

        return new self($yTicks, $xTicks, $chartDays, [] === $shown($eaten), [] !== $shown($targets), $left, $right, $top, $bottom, $width);
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
                null === $day['target'] ? null : ['series' => 'target', 'value' => $kcal($day['target']), 'name' => 'Target'],
                null === $day['burned'] ? null : ['series' => 'burned', 'value' => $kcal($day['burned']), 'name' => 'Burned'],
                match ($day['status']) {
                    self::STATUS_OVER => ['series' => 'balance', 'value' => $kcal($day['overBy']), 'name' => 'over target'],
                    self::STATUS_WITHIN => ['series' => 'balance', 'value' => $kcal($day['target'] - $day['eaten']), 'name' => $day['today'] ? 'left' : 'under target'],
                    default => null,
                },
            ])),
        ], $this->days);
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
