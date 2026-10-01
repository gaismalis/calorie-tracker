<?php

namespace App\Energy;

final readonly class EnergyEstimate
{
    /** @param list<string> $missing profile data still needed, e.g. ['height', 'weight'] */
    public function __construct(
        public ?float $bmr,
        public ?float $tdee,
        public array $missing = [],
    ) {
    }

    public function isComplete(): bool
    {
        return null !== $this->tdee;
    }
}
