<?php

namespace App\Demo;

/**
 * What the landing page shows of the demo: kilos lost, average intake, and a small chart (weekly average kcal as
 * bars, weigh-ins as a line), all from the same DemoPlan that fills the demo account.
 */
final readonly class DemoStory
{
    public const WIDTH = 600;
    public const HEIGHT = 220;
    /** Axis range of the weight line, kg around the actual values. */
    private const KG_MARGIN = 0.8;

    public float $startKg;
    public float $endKg;
    public float $lostKg;
    public int $weeks;
    public int $averageKcal;
    public int $meals;
    /** SVG polyline points of the weigh-ins. */
    public string $weightPoints;
    /** @var list<array{x: float, y: float, width: float, height: float, kcal: int}> weekly average kcal */
    public array $bars;
    /** @var list<array{y: float, kg: float}> */
    public array $kgTicks;

    public function __construct(DemoPlan $plan)
    {
        $weights = $plan->weights();
        $this->startKg = reset($weights);
        $this->endKg = end($weights);
        $this->lostKg = round($this->startKg - $this->endKg, 1);
        $this->weeks = (int) round(DemoPlan::DAYS / 7);
        $this->meals = count($plan->meals());

        $first = new \DateTimeImmutable(array_key_first($weights));
        $dayX = fn (string $day) => (new \DateTimeImmutable($day))->diff($first)->days / (DemoPlan::DAYS - 1) * self::WIDTH;

        $top = ceil(max($weights) + self::KG_MARGIN);
        $bottom = floor(min($weights) - self::KG_MARGIN);
        $kgY = fn (float $kg) => round(($top - $kg) / ($top - $bottom) * self::HEIGHT, 1);
        $this->weightPoints = implode(' ', array_map(fn (string $day, float $kg) => round($dayX($day), 1).','.$kgY($kg), array_keys($weights), $weights));
        $ticks = [];
        for ($kg = $top - 1; $kg > $bottom; $kg -= 2) {
            $ticks[] = ['y' => $kgY($kg), 'kg' => $kg];
        }
        $this->kgTicks = $ticks;

        // Weekly average of the logged days (today is still going, so it's left out).
        $today = array_key_last($weights);
        $byDay = [];
        foreach ($plan->meals() as $meal) {
            $day = $meal['at']->format('Y-m-d');
            if ($day !== $today) {
                $byDay[$day] = ($byDay[$day] ?? 0) + DemoPlan::kcal($meal);
            }
        }
        $this->averageKcal = (int) round(array_sum($byDay) / max(1, count($byDay)), -1);
        $weeks = [];
        foreach ($byDay as $day => $kcal) {
            $weeks[intdiv((new \DateTimeImmutable($day))->diff($first)->days, 7)][] = $kcal;
        }
        $slot = self::WIDTH / ceil(DemoPlan::DAYS / 7);
        $bars = [];
        foreach ($weeks as $week => $days) {
            $kcal = array_sum($days) / count($days);
            $height = $kcal / 3200 * self::HEIGHT;
            $bars[] = ['x' => round($week * $slot + $slot * 0.15, 1), 'y' => round(self::HEIGHT - $height, 1), 'width' => round($slot * 0.7, 1), 'height' => round($height, 1), 'kcal' => (int) round($kcal, -1)];
        }
        $this->bars = $bars;
    }
}
