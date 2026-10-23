<?php

namespace App\Recipe;

use App\Nutrition\EstimatedItem;
use App\Nutrition\FoodCategory;
use App\Nutrition\Per100g;

/** One food in a recipe, with the user's own numbers for that amount (e.g. "Whey protein, 30 g, 120 kcal"). */
final readonly class RecipeItem
{
    public function __construct(
        public string $name,
        public float $grams,
        public float $kcal,
        public float $protein,
        public float $carbs,
        public float $fat,
        public FoodCategory $category = FoodCategory::Food,
    ) {
    }

    /** @param array{name: string, grams: float|int, kcal: float|int, protein: float|int, carbs: float|int, fat: float|int, category?: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['name'], (float) $data['grams'], (float) $data['kcal'],
            (float) $data['protein'], (float) $data['carbs'], (float) $data['fat'],
            FoodCategory::tryFrom($data['category'] ?? '') ?? FoodCategory::Food,
        );
    }

    /** @return array{name: string, grams: float, kcal: float, protein: float, carbs: float, fat: float, category: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'grams' => $this->grams, 'kcal' => $this->kcal,
            'protein' => $this->protein, 'carbs' => $this->carbs, 'fat' => $this->fat,
            'category' => $this->category->value,
        ];
    }

    /** As a meal item: the same totals, plus values per 100 g so the amount can still be adjusted. */
    public function toEstimatedItem(): EstimatedItem
    {
        $per100g = new Per100g(...array_map(
            fn (float $value) => round($value / $this->grams * 100, 1),
            ['kcal' => $this->kcal, 'protein' => $this->protein, 'carbs' => $this->carbs, 'fat' => $this->fat],
        ));

        return new EstimatedItem($this->name, $this->grams, $this->kcal, $this->protein, $this->carbs, $this->fat, per100g: $per100g, category: $this->category);
    }
}
