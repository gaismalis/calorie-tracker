<?php

namespace App\Energy;

use App\Entity\User;
use App\Profile\ActivityLevel;
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

    /** Used for profile details the user hasn't filled in yet, so a weight alone is enough for a rough estimate. */
    public const DEFAULT_AGE = 35;
    public const DEFAULT_HEIGHT_CM = 170;
    /** Halfway between the male (+5) and female (−161) Mifflin-St Jeor constants. */
    public const UNKNOWN_SEX_CONSTANT = -78;
    public const DEFAULT_ACTIVITY = ActivityLevel::Sedentary;

    /**
     * Needs only a weight; missing profile details are replaced by defaults and listed in
     * {@see EnergyEstimate::$missing}, so the page can say the estimate is rough and how to improve it.
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

        if (null === $weightKg) {
            return new EnergyEstimate(null, null, $missing);
        }

        $sexConstant = match ($user->getSex()) {
            Sex::Male => 5,
            Sex::Female => -161,
            null => self::UNKNOWN_SEX_CONSTANT,
        };
        $bmr = round(10 * $weightKg
            + 6.25 * ($user->getHeightCm() ?? self::DEFAULT_HEIGHT_CM)
            - 5 * ($user->getAge($on) ?? self::DEFAULT_AGE)
            + $sexConstant);

        $tdee = round($bmr * ($user->getActivityLevel() ?? self::DEFAULT_ACTIVITY)->multiplier());

        return new EnergyEstimate($bmr, $tdee, $missing);
    }
}
