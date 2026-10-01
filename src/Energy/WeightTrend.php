<?php

namespace App\Energy;

/**
 * Smoothed weight ("trend"), so day-to-day water and food swings don't look like fat gain or loss.
 *
 * Exponential moving average with 10 % weight per day (as in The Hacker's Diet). Days without a
 * weigh-in are filled by linear interpolation between the surrounding weigh-ins; nothing is
 * extrapolated past the last weigh-in.
 */
final class WeightTrend
{
    public const SMOOTHING = 0.1;

    /**
     * @param array<string, float> $weights kg by date ('Y-m-d'), any order
     *
     * @return array<string, float> trend kg for every calendar day from the first to the last weigh-in, ascending
     */
    public static function daily(array $weights): array
    {
        if ([] === $weights) {
            return [];
        }
        ksort($weights);

        $trend = [];
        $previousDate = null;
        $previousKg = null;
        $current = null;
        foreach ($weights as $date => $kg) {
            $day = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
            if (null === $previousDate) {
                $current = $kg;
                $trend[$date] = round($current, 2);
            } else {
                $gap = (int) $previousDate->diff($day)->days;
                for ($i = 1; $i <= $gap; ++$i) {
                    $interpolated = $previousKg + ($kg - $previousKg) * $i / $gap;
                    $current += self::SMOOTHING * ($interpolated - $current);
                    $trend[$previousDate->modify("+$i days")->format('Y-m-d')] = round($current, 2);
                }
            }
            $previousDate = $day;
            $previousKg = $kg;
        }

        return $trend;
    }
}
