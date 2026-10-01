<?php

namespace App\Chart;

/**
 * Geometry for the weight chart (weigh-in dots + trend line), rendered as inline SVG by Twig.
 * Pure calculation, no I/O: give it the data, get coordinates, ticks and labels back.
 */
final readonly class WeightChart
{
    public const WIDTH = 680;
    public const HEIGHT = 240;
    private const MARGIN_LEFT = 44;
    private const MARGIN_RIGHT = 86; // room for the direct label at the end of the trend
    private const MARGIN_TOP = 12;
    private const MARGIN_BOTTOM = 28;
    /** Centre of the y-axis when there's no weight at all yet. */
    private const DEFAULT_KG = 75.0;
    /** Candidate y-tick steps in kg; the first that gives at most 6 ticks wins. */
    private const Y_STEPS = [0.5, 1, 2, 5, 10, 20];

    /**
     * @param list<array{x: float, y: float, date: string, kg: float}>                         $dots
     * @param list<array{value: float, y: float}>                                              $yTicks
     * @param list<array{label: string, x: float}>                                             $xTicks
     * @param list<array{x: float, label: string, kg: float|null, trend: float|null}>          $days   hover data, one per day with data
     * @param array{x: float, y: float, kg: float}|null                                        $trendEnd
     */
    private function __construct(
        public string $trendPath,
        public array $dots,
        public array $yTicks,
        public array $xTicks,
        public array $days,
        public ?array $trendEnd,
        public float $plotLeft,
        public float $plotRight,
        public float $plotTop,
        public float $plotBottom,
    ) {
    }

    /**
     * @param array<string, float> $weights kg by date ('Y-m-d') within the range
     * @param array<string, float> $trend   trend kg by date ('Y-m-d') within the range
     * @param string               $lastDay  last date shown ('Y-m-d', usually today)
     * @param float|null           $fallback weight to centre an empty chart on (e.g. the last known weigh-in)
     */
    public static function build(array $weights, array $trend, string $lastDay, int $days, ?float $fallback = null): self
    {
        ksort($weights);
        ksort($trend);

        $last = new \DateTimeImmutable($lastDay);
        $first = $last->modify(sprintf('-%d days', $days - 1));
        $left = self::MARGIN_LEFT;
        $right = self::WIDTH - self::MARGIN_RIGHT;
        $top = self::MARGIN_TOP;
        $bottom = self::HEIGHT - self::MARGIN_BOTTOM;

        $x = fn (string $date): float => round($left + ($right - $left) * self::daysBetween($first, $date) / max(1, $days - 1), 1);

        $values = [...array_values($weights), ...array_values($trend)];
        if ([] === $values) {
            $values = [($fallback ?? self::DEFAULT_KG) - 1.5, ($fallback ?? self::DEFAULT_KG) + 1.5];
        }
        [$min, $max, $step] = self::yDomain($values);
        $y = fn (float $kg): float => round($bottom - ($bottom - $top) * ($kg - $min) / ($max - $min), 1);

        $path = '';
        foreach ($trend as $date => $kg) {
            $path .= ('' === $path ? 'M' : ' L').$x($date).' '.$y($kg);
        }

        $dots = [];
        foreach ($weights as $date => $kg) {
            $dots[] = ['x' => $x($date), 'y' => $y($kg), 'date' => $date, 'kg' => $kg];
        }

        $yTicks = [];
        for ($value = $min; $value <= $max + 1e-9; $value += $step) {
            $yTicks[] = ['value' => round($value, 1), 'y' => $y($value)];
        }

        $xTicks = [];
        $tickEvery = (int) max(1, ceil($days / 6));
        for ($offset = $days - 1; $offset >= 0; $offset -= $tickEvery) { // anchored on the last day
            $date = $first->modify("+$offset days")->format('Y-m-d');
            array_unshift($xTicks, ['label' => (new \DateTimeImmutable($date))->format('j M'), 'x' => $x($date)]);
        }

        $hover = [];
        foreach (array_unique([...array_keys($weights), ...array_keys($trend)]) as $date) {
            $hover[$date] = [
                'x' => $x($date),
                'label' => (new \DateTimeImmutable($date))->format('D, j M'),
                'kg' => $weights[$date] ?? null,
                'trend' => $trend[$date] ?? null,
            ];
        }
        ksort($hover);

        $lastTrendDate = array_key_last($trend);
        $trendEnd = null === $lastTrendDate ? null : ['x' => $x($lastTrendDate), 'y' => $y($trend[$lastTrendDate]), 'kg' => $trend[$lastTrendDate]];

        return new self($path, $dots, $yTicks, $xTicks, array_values($hover), $trendEnd, $left, $right, $top, $bottom);
    }

    public function isEmpty(): bool
    {
        return [] === $this->dots;
    }

    /**
     * Rounded axis range that contains all values, with at most 6 clean ticks.
     *
     * @param list<float> $values
     *
     * @return array{float, float, float} min, max, step
     */
    private static function yDomain(array $values): array
    {
        $low = min($values) - 0.3;
        $high = max($values) + 0.3;
        foreach (self::Y_STEPS as $step) {
            $min = floor($low / $step) * $step;
            $max = ceil($high / $step) * $step;
            if (($max - $min) / $step <= 5) {
                return [$min, $max, $step];
            }
        }

        $step = end(self::Y_STEPS);

        return [floor($low / $step) * $step, ceil($high / $step) * $step, $step];
    }

    private static function daysBetween(\DateTimeImmutable $from, string $date): int
    {
        $diff = $from->diff(new \DateTimeImmutable($date));

        return $diff->invert ? -$diff->days : $diff->days;
    }
}
