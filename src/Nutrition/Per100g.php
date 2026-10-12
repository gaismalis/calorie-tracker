<?php

namespace App\Nutrition;

/** Nutrition values per 100 g of a food (per 100 ml for drinks), as on a product label. */
final readonly class Per100g
{
    /** Pure fat is ~900 kcal/100 g; nothing edible has more. */
    public const MAX_KCAL = 900.0;
    public const MAX_MACRO_GRAMS = 100.0;

    public function __construct(
        public float $kcal,
        public float $protein,
        public float $carbs,
        public float $fat,
    ) {
    }

    /** Clamps to what's physically possible (an AI can return 1200 kcal or 130 g protein per 100 g). */
    public static function plausible(float $kcal, float $protein, float $carbs, float $fat): self
    {
        $clamp = fn (float $v, float $max) => round(min($max, max(0.0, $v)), 1);

        return new self($clamp($kcal, self::MAX_KCAL), $clamp($protein, self::MAX_MACRO_GRAMS), $clamp($carbs, self::MAX_MACRO_GRAMS), $clamp($fat, self::MAX_MACRO_GRAMS));
    }

    /** @return array{kcal: float, protein: float, carbs: float, fat: float} totals for a portion */
    public function forGrams(float $grams): array
    {
        $scale = $grams / 100;

        return [
            'kcal' => round($this->kcal * $scale, 1),
            'protein' => round($this->protein * $scale, 1),
            'carbs' => round($this->carbs * $scale, 1),
            'fat' => round($this->fat * $scale, 1),
        ];
    }
}
