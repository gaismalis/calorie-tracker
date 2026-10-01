<?php

namespace App\Exercise;

final readonly class ExerciseEstimate
{
    /** @param list<EstimatedActivity> $items */
    public function __construct(
        public array $items,
        public string $estimatedBy,
    ) {
    }
}
