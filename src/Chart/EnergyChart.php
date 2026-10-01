<?php

namespace App\Chart;

/**
 * Geometry for the "eaten vs burned" chart on the dashboard: one point per day, two lines.
 * Days without logged meals have no "eaten" point (unknown, not zero), so that line has gaps.
 */
final readonly class EnergyChart
{
    public const WIDTH = 680;
    public const HEIGHT = 220;
    private const MARGIN_LEFT = 48;
    private const MARGIN_RIGHT = 16;
    private const MARGIN_TOP = 12;
    private const MARGIN_BOTTOM = 28;
    private const Y_STEPS = [250, 500, 1000, 2000];

    /**
     * @param list<string>                                                                          $eatenPaths  one SVG path per run of consecutive days
     * @param list<array{x: float, y: float}>                                                       $eatenDots
     * @param list<array{value: int, y: float}>                                                     $yTicks
     * @param list<array{label: string, x: float}>                                                  $xTicks
     * @param list<array{x: float, label: string, eaten: float|null, burned: float|null, today: bool}> $days
     */
    private function __construct(
        public array $eatenPaths,
        public array $eatenDots,
        public string $burnedPath,
        public array $yTicks,
        public array $xTicks,
        public array $days,
        public bool $empty,
        public float $plotLeft,
        public float $plotRight,
        public float $plotTop,
        public float $plotBottom,
    ) {
    }

    /**
     * @param array<string, float> $eaten  kcal eaten by date ('Y-m-d'); days without meals absent
     * @param array<string, float> $burned kcal burned by date (baseline + exercise); empty when unknown
     * @param string               $lastDay last date shown ('Y-m-d')
     * @param string               $today   today's date, marked as "so far"
     */
    public static function build(array $eaten, array $burned, string $lastDay, string $today, int $days = 14): self
    {
        $last = new \DateTimeImmutable($lastDay);
        $dates = [];
        for ($i = $days - 1; $i >= 0; --$i) {
            $dates[] = $last->modify("-$i days")->format('Y-m-d');
        }

        $left = self::MARGIN_LEFT;
        $right = self::WIDTH - self::MARGIN_RIGHT;
        $top = self::MARGIN_TOP;
        $bottom = self::HEIGHT - self::MARGIN_BOTTOM;
        $x = fn (int $i): float => round($left + ($right - $left) * $i / max(1, $days - 1), 1);

        $values = [];
        foreach ($dates as $date) {
            foreach ([$eaten[$date] ?? null, $burned[$date] ?? null] as $value) {
                if (null !== $value) {
                    $values[] = $value;
                }
            }
        }
        [$min, $max, $step] = self::yDomain($values);
        $y = fn (float $kcal): float => round($bottom - ($bottom - $top) * ($kcal - $min) / ($max - $min), 1);

        $eatenPaths = [];
        $eatenDots = [];
        $current = '';
        $burnedPath = '';
        $hover = [];
        foreach ($dates as $i => $date) {
            $e = $eaten[$date] ?? null;
            $b = $burned[$date] ?? null;
            if (null !== $e) {
                $current .= ('' === $current ? 'M' : ' L').$x($i).' '.$y($e);
                $eatenDots[] = ['x' => $x($i), 'y' => $y($e)];
            } elseif ('' !== $current) {
                $eatenPaths[] = $current;
                $current = '';
            }
            if (null !== $b) {
                $burnedPath .= ('' === $burnedPath ? 'M' : ' L').$x($i).' '.$y($b);
            }
            $hover[] = [
                'x' => $x($i),
                'label' => (new \DateTimeImmutable($date))->format('D, j M').($date === $today ? ' (so far)' : ''),
                'eaten' => null === $e ? null : round($e),
                'burned' => null === $b ? null : round($b),
                'today' => $date === $today,
            ];
        }
        if ('' !== $current) {
            $eatenPaths[] = $current;
        }

        $yTicks = [];
        for ($value = $min; $value <= $max + 1e-9; $value += $step) {
            $yTicks[] = ['value' => (int) $value, 'y' => $y($value)];
        }

        $xTicks = [];
        for ($i = $days - 1; $i >= 0; $i -= 3) { // anchored on the last day
            array_unshift($xTicks, ['label' => (new \DateTimeImmutable($dates[$i]))->format('j M'), 'x' => $x($i)]);
        }

        return new self($eatenPaths, $eatenDots, $burnedPath, $yTicks, $xTicks, $hover, [] === $values, $left, $right, $top, $bottom);
    }

    /** @return list<array{x: float, label: string, rows: list<array{series: string, value: string, name: string}>}> tooltip content per day */
    public function hover(): array
    {
        return array_map(fn (array $day) => [
            'x' => $day['x'],
            'label' => $day['label'],
            'rows' => array_values(array_filter([
                ['series' => 'eaten', 'value' => null === $day['eaten'] ? 'not logged' : number_format($day['eaten'], 0, '.', ' ').' kcal', 'name' => 'Eaten'],
                null === $day['burned'] ? null : ['series' => 'burned', 'value' => number_format($day['burned'], 0, '.', ' ').' kcal', 'name' => 'Burned'],
            ])),
        ], $this->days);
    }

    /**
     * @param list<float> $values
     *
     * @return array{float, float, float}
     */
    private static function yDomain(array $values): array
    {
        if ([] === $values) {
            return [1000.0, 3000.0, 500.0];
        }
        $low = min($values) - 100;
        $high = max($values) + 100;
        foreach (self::Y_STEPS as $step) {
            $min = max(0, floor($low / $step) * $step);
            $max = ceil($high / $step) * $step;
            if (($max - $min) / $step <= 5) {
                return [$min, $max, (float) $step];
            }
        }
        $step = (float) end(self::Y_STEPS);

        return [max(0, floor($low / $step) * $step), ceil($high / $step) * $step, $step];
    }
}
