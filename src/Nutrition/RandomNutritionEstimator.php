<?php

namespace App\Nutrition;

use App\Ai\Photo;
use Random\Randomizer;

/**
 * Fake provider for local development: no API calls, no tokens spent.
 * Splits the description into items like a real provider would, uses grams the user typed,
 * and makes up plausible macros. kcal is derived from the macros (4/4/9) so the numbers add up.
 */
final class RandomNutritionEstimator implements NutritionEstimator
{
    public const NAME = 'random';

    private const SEPARATORS = '/\s*(?:(?<!\d),|,(?!\d)|;|\+|&|\R|\band\b|\bun\b)\s*/iu';
    private const GRAMS = '/(\d+(?:[.,]\d+)?)\s*(?:g|gr|grams?)\b/iu';

    private readonly Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    public function estimate(string $mealDescription, ?float $timeLimit = null, ?Photo $photo = null): MealEstimate
    {
        if (null !== $photo && '' === trim($mealDescription)) {
            $mealDescription = 'Food from the photo'; // the fake provider can't look at photos
        }

        $items = [];
        foreach (preg_split(self::SEPARATORS, $mealDescription, flags: PREG_SPLIT_NO_EMPTY) as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }

            $assumption = null;
            if (preg_match(self::GRAMS, $part, $match)) {
                $grams = (float) str_replace(',', '.', $match[1]);
            } else {
                $grams = (float) $this->randomizer->getInt(30, 300);
                $assumption = 'random portion (fake provider)';
            }

            // Per-100 g values in realistic ranges; totals follow from the portion.
            $protein = round($this->randomizer->getFloat(0, 25), 1);
            $carbs = round($this->randomizer->getFloat(0, 60), 1);
            $fat = round($this->randomizer->getFloat(0, 30), 1);

            $items[] = EstimatedItem::fromPer100g(
                name: $part,
                grams: $grams,
                per100g: new Per100g(round(4 * $protein + 4 * $carbs + 9 * $fat, 1), $protein, $carbs, $fat),
                assumption: $assumption,
                category: FoodCategoryGuesser::guess($part),
            );
        }

        return new MealEstimate($items, self::NAME);
    }
}
