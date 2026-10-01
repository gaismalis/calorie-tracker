<?php

namespace App\Energy;

final readonly class EnergyEstimate
{
    public const SOURCE_ADAPTIVE = 'adaptive';
    public const SOURCE_FORMULA = 'formula';
    public const KCAL_PER_KG = 7700;
    /** Daily targets are never set below this, whatever the goal. */
    public const MIN_TARGET = 1200;

    /** @param list<string> $missing profile data the formula still needs, e.g. ['height', 'weight'] */
    public function __construct(
        public ?float $bmr,
        /** Formula estimate of daily burn without logged exercise (BMR × everyday activity level). */
        public ?float $formulaTdee,
        public array $missing = [],
        public ?AdaptiveTdee $adaptive = null,
    ) {
    }

    public function withAdaptive(AdaptiveTdee $adaptive): self
    {
        return new self($this->bmr, $this->formulaTdee, $this->missing, $adaptive);
    }

    /**
     * Best available estimate of daily burn WITHOUT logged exercise: from the user's own data when
     * there's enough, else the formula. Each day's logged exercise comes on top.
     */
    public function getBaseline(): ?float
    {
        return $this->adaptive?->baseline ?? $this->formulaTdee;
    }

    /** Total burn on a day with this much logged exercise. */
    public function burnedWith(float $exerciseKcal): ?float
    {
        $baseline = $this->getBaseline();

        return null === $baseline ? null : $baseline + $exerciseKcal;
    }

    public function getSource(): ?string
    {
        return match (true) {
            null !== $this->adaptive?->baseline => self::SOURCE_ADAPTIVE,
            null !== $this->formulaTdee => self::SOURCE_FORMULA,
            default => null,
        };
    }

    /**
     * Daily intake target: what you burn that day (baseline + logged exercise) adjusted for the weekly
     * goal (kg/week, negative = lose), never below {@see MIN_TARGET}.
     */
    public function targetFor(float $weeklyGoalKg, float $exerciseKcal = 0.0): ?float
    {
        $burned = $this->burnedWith($exerciseKcal);

        return null === $burned ? null : round(max(self::MIN_TARGET, $burned + self::dailyAdjustment($weeklyGoalKg)));
    }

    /** kcal per day above (gain) or below (lose) maintenance for a weekly goal. */
    public static function dailyAdjustment(float $weeklyGoalKg): float
    {
        return round($weeklyGoalKg * self::KCAL_PER_KG / 7);
    }

    public function isComplete(): bool
    {
        return null !== $this->getBaseline();
    }
}
