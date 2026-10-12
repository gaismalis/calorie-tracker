<?php

namespace App\Nutrition;

final readonly class MealEstimate
{
    /** @param list<EstimatedItem> $items */
    public function __construct(
        public array $items,
        public string $estimatedBy,
        /** Short name for the whole meal, e.g. "Spaghetti bolognese with salad"; null = made from the items. */
        public ?string $mealName = null,
    ) {
    }
}
