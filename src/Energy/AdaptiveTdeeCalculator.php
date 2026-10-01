<?php

namespace App\Energy;

use App\Entity\User;
use App\Repository\ExerciseEntryRepository;
use App\Repository\MealEntryRepository;
use App\Repository\WeightEntryRepository;
use Psr\Clock\ClockInterface;

/**
 * Real daily expenditure from energy balance:
 *
 *     TDEE ≈ average daily intake − (trend weight change in kg × 7700 kcal/kg) / days
 *
 * over the last {@see WINDOW_DAYS} complete days (today is excluded because it isn't over yet).
 * Logged exercise is averaged over the same days and subtracted to get the baseline
 * ({@see AdaptiveTdee::$baseline}); each day's own exercise is then added back on top of it.
 * Consistent logging errors cancel out: if someone always under-reports by 10 %, the result is
 * 10 % low too, which is exactly the intake that keeps their weight stable *as they log it*.
 */
final class AdaptiveTdeeCalculator
{
    public const WINDOW_DAYS = 28;
    public const MIN_DAYS = 14;
    /** Share of days in the window that must have logged meals. */
    public const MIN_LOGGED_SHARE = 0.6;
    public const KCAL_PER_KG = 7700;
    /** Results outside this range point to incomplete logging rather than a real metabolism. */
    public const PLAUSIBLE_MIN = 1000;
    public const PLAUSIBLE_MAX = 6000;
    /** Weigh-ins this far back are used to warm up the trend. */
    private const HISTORY_DAYS = 120;

    public function __construct(
        private readonly MealEntryRepository $meals,
        private readonly WeightEntryRepository $weights,
        private readonly ExerciseEntryRepository $exercises,
        private readonly ClockInterface $clock,
    ) {
    }

    public function estimate(User $user): AdaptiveTdee
    {
        $today = $user->today($this->clock->now())->format('Y-m-d');
        $windowStart = (new \DateTimeImmutable($today))->modify('-'.self::WINDOW_DAYS.' days')->format('Y-m-d');
        $historyStart = (new \DateTimeImmutable($today))->modify('-'.self::HISTORY_DAYS.' days')->format('Y-m-d');

        return self::calculate(
            $this->meals->dailyIntake($user, new \DateTimeImmutable($windowStart), new \DateTimeImmutable($today)),
            $this->weights->weightsByDate($user, new \DateTimeImmutable($historyStart)),
            $today,
            $this->exercises->dailyExercise($user, new \DateTimeImmutable($windowStart), new \DateTimeImmutable($today)),
        );
    }

    /**
     * @param array<string, float> $intakeByDay kcal by local date ('Y-m-d'); days without meals are absent
     * @param array<string, float> $weightsByDay kg by date ('Y-m-d')
     * @param string               $today        local date; this day is not included
     * @param array<string, float> $exerciseByDay kcal of logged exercise by local date
     */
    public static function calculate(array $intakeByDay, array $weightsByDay, string $today, array $exerciseByDay = []): AdaptiveTdee
    {
        $lastCompleteDay = self::shift($today, -1);
        $trend = WeightTrend::daily(array_filter($weightsByDay, fn (string $date) => $date <= $lastCompleteDay, ARRAY_FILTER_USE_KEY));
        if ([] === $trend) {
            return new AdaptiveTdee(null, missing: 'weight', daysNeeded: self::MIN_DAYS);
        }

        $start = max(self::shift($today, -self::WINDOW_DAYS), array_key_first($trend));
        $end = array_key_last($trend); // last weigh-in, at most yesterday
        $days = $start <= $end ? (int) (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days : 0;
        if ($days < self::MIN_DAYS) {
            // Count from the first weigh-in to yesterday: keep weighing in and it's ready in this many days.
            $sinceStart = (int) (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($lastCompleteDay))->days;

            return new AdaptiveTdee(null, $days, missing: 'weight', daysNeeded: max(1, self::MIN_DAYS - $sinceStart));
        }

        // Intake on the days whose food moved the weight from $start to $end: [start, end).
        $logged = array_filter($intakeByDay, fn (float $kcal, string $date) => $kcal > 0 && $date >= $start && $date < $end, ARRAY_FILTER_USE_BOTH);
        $loggedDays = count($logged);
        $neededLoggedDays = (int) ceil($days * self::MIN_LOGGED_SHARE);
        if ($loggedDays < $neededLoggedDays) {
            return new AdaptiveTdee(null, $days, $loggedDays, missing: 'meals', daysNeeded: $neededLoggedDays - $loggedDays);
        }

        $averageIntake = array_sum($logged) / $loggedDays;
        $trendChange = $trend[$end] - $trend[$start];
        $tdee = round($averageIntake - $trendChange * self::KCAL_PER_KG / $days);

        if ($tdee < self::PLAUSIBLE_MIN || $tdee > self::PLAUSIBLE_MAX) {
            return new AdaptiveTdee(null, $days, $loggedDays, round($averageIntake), round($trendChange, 2), missing: 'implausible');
        }

        $exercise = array_filter($exerciseByDay, fn (string $date) => $date >= $start && $date < $end, ARRAY_FILTER_USE_KEY);
        $averageExercise = round(array_sum($exercise) / $days);

        return new AdaptiveTdee($tdee, $days, $loggedDays, round($averageIntake), round($trendChange, 2), averageExercise: $averageExercise);
    }

    private static function shift(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }
}
