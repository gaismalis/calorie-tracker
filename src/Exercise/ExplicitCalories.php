<?php

namespace App\Exercise;

/**
 * Finds a calorie number the user typed themselves ("burned 600 kcal", "workout 450 calories").
 * Those are trusted as-is instead of asking the AI.
 */
final class ExplicitCalories
{
    private const PATTERN = '/(?<![\d.,])(\d{1,5}(?:[.,]\d+)?)\s*(?:kcal|kilocalories?|calories?|cal|kalorijas?)\b/iu';

    /** The single calorie number in the text, or null if there is none or more than one. */
    public static function find(string $text): ?float
    {
        if (1 !== preg_match_all(self::PATTERN, $text, $matches)) {
            return null;
        }

        $number = $matches[1][0];
        // "1.200" / "1,200" with exactly three digits after the separator is a thousands separator.
        if (preg_match('/^\d{1,2}[.,]\d{3}$/', $number)) {
            return (float) preg_replace('/[.,]/', '', $number);
        }

        return (float) str_replace(',', '.', $number);
    }
}
