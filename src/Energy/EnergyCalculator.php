<?php

namespace App\Energy;

use App\Entity\User;
use App\Profile\Sex;
use App\Repository\WeightEntryRepository;

/**
 * Daily energy expenditure: from the user's own intake and weight trend when there's enough data
 * ({@see AdaptiveTdeeCalculator}), otherwise a formula estimate.
 *
 * Formula: BMR by Mifflin-St Jeor (1990), TDEE = BMR × activity multiplier.
 */
final class EnergyCalculator
{
    public function __construct(
        private readonly WeightEntryRepository $weights,
        private readonly AdaptiveTdeeCalculator $adaptive,
    ) {
    }

    public function estimate(User $user): EnergyEstimate
    {
        return self::calculate($user, $this->weights->findLatest($user)?->getWeightKg())
            ->withAdaptive($this->adaptive->estimate($user));
    }

    /**
     * Mifflin-St Jeor BMR × everyday activity. Needs real data for all of it (no defaults, no guessing);
     * whatever is missing is listed in {@see EnergyEstimate::$missing} so the page can ask for it.
     */
    public static function calculate(User $user, ?float $weightKg, ?\DateTimeImmutable $on = null): EnergyEstimate
    {
        $missing = array_keys(array_filter([
            'sex' => null === $user->getSex(),
            'birth date' => null === $user->getBirthDate(),
            'height' => null === $user->getHeightCm(),
            'activity level' => null === $user->getActivityLevel(),
            'weight' => null === $weightKg,
        ]));

        if ([] !== $missing) {
            return new EnergyEstimate(null, null, $missing);
        }

        $bmr = round(10 * $weightKg
            + 6.25 * $user->getHeightCm()
            - 5 * $user->getAge($on)
            + (Sex::Male === $user->getSex() ? 5 : -161));

        return new EnergyEstimate($bmr, round($bmr * $user->getActivityLevel()->multiplier()), $missing);
    }
}
