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
        /** Values per 100 g the totals were calculated from; null when only totals are known. */
        public ?Per100g $per100g = null,
        public FoodCategory $category = FoodCategory::Food,
    ) {
    }

    /** Totals calculated from per-100 g values and the portion, so they always agree. */
    public static function fromPer100g(string $name, float $grams, Per100g $per100g, ?string $assumption = null, FoodCategory $category = FoodCategory::Food): self
    {
        $totals = $per100g->forGrams($grams);

        return new self($name, $grams, $totals['kcal'], $totals['protein'], $totals['carbs'], $totals['fat'], $assumption, $per100g, $category);
    }
}
