<?php

namespace App\Exercise;

final readonly class EstimatedActivity
{
    public function __construct(
        public string $name,
        public ?float $minutes,
        /** Burned on top of resting metabolism (net). */
        public float $kcal,
        public ?string $assumption = null,
    ) {
    }
}
