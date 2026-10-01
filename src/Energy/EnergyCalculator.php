<?php

namespace App\Energy;

use App\Entity\User;
use App\Profile\Sex;
use App\Repository\WeightEntryRepository;

/**
 * Formula-based estimate of daily energy expenditure, used until there is enough weight
 * history to compute it from the user's real intake and weight trend.
 *
 * BMR: Mifflin-St Jeor (1990), TDEE = BMR × activity multiplier.
 */
final class EnergyCalculator
{
    public function __construct(private readonly WeightEntryRepository $weights)
    {
    }

    public function estimate(User $user): EnergyEstimate
    {
        return self::calculate($user, $this->weights->findLatest($user)?->getWeightKg());
    }

    public static function calculate(User $user, ?float $weightKg, ?\DateTimeImmutable $on = null): EnergyEstimate
    {
        $missing = array_keys(array_filter([
            'sex' => null === $user->getSex(),
            'birth date' => null === $user->getBirthDate(),
            'height' => null === $user->getHeightCm(),
            'activity level' => null === $user->getActivityLevel(),
            'weight' => null === $weightKg,
        ]));

        if (array_diff($missing, ['activity level'])) {
            return new EnergyEstimate(null, null, $missing);
        }

        $bmr = 10 * $weightKg
            + 6.25 * $user->getHeightCm()
            - 5 * $user->getAge($on)
            + (Sex::Male === $user->getSex() ? 5 : -161);
        $bmr = round($bmr);

        $tdee = $user->getActivityLevel() ? round($bmr * $user->getActivityLevel()->multiplier()) : null;

        return new EnergyEstimate($bmr, $tdee, $missing);
    }
}
