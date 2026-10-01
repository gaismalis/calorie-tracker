<?php

namespace App\Nutrition;

final readonly class MealEstimate
{
    /** @param list<EstimatedItem> $items */
    public function __construct(
        public array $items,
        public string $estimatedBy,
    ) {
    }
}
