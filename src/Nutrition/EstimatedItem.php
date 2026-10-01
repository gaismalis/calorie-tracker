<?php

namespace App\Nutrition;

final readonly class EstimatedItem
{
    public function __construct(
        public string $name,
        public float $grams,
        public float $kcal,
        public float $protein,
        public float $carbs,
        public float $fat,
        public ?string $assumption = null,
    ) {
    }
}
