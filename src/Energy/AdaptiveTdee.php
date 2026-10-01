<?php

namespace App\Energy;

/** Daily energy expenditure computed from the user's own intake and weight trend, or why it isn't available yet. */
final readonly class AdaptiveTdee
{
    public function __construct(
        public ?float $tdee,
        /** Days covered (from window start to end). */
        public int $days = 0,
        /** Days in the window with at least one estimated meal. */
        public int $loggedDays = 0,
        public ?float $averageIntake = null,
        /** Trend change over the window (negative = lost weight). */
        public ?float $trendChangeKg = null,
        /** Why there is no estimate: 'weight' (need more weigh-in history), 'meals' (log meals on more days), 'implausible'. */
        public ?string $missing = null,
        /** For 'weight' and 'meals': how many more days are needed. */
        public int $daysNeeded = 0,
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->tdee;
    }
}
