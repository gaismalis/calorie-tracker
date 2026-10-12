<?php

namespace App\Nutrition;

/**
 * Guesses a category from an item's name with keyword lists (English and Latvian). Used by the fake
 * provider and as a fallback for categorizing older items without an AI; the AI does better.
 */
final class FoodCategoryGuesser
{
    /** Alcohol-free variants are ordinary drinks. */
    private const NON_ALCOHOLIC = '/\b(alcohol[- ]?free|non[- ]?alcoholic|0[.,]0\s*%|bezalkohol)/iu';
    /** Word starts (any ending, e.g. Latvian cases: alus/alū, vīns/vīnu) … */
    private const ALCOHOL = '/\b(beer|lager|alus|wine|vīn|prosecco|champagne|šampan|vodka|degvīn|whisk|viskij|tequila|cognac|konjak|brandy|liqueur|liķier|balzam|cider|sidr|cocktail|kokteil|martini|aperol|sangria|medalus)\w*/iu';
    /** … and short words that must stand alone ("gin", not "ginger"). */
    private const ALCOHOL_WORDS = '/\b(gin|džins|rum|rums|ale|ipa|stout|sake|mead|shots?)\b/iu';
    private const SNACK = '/\b(chocolate|šokolād|candy|candies|konfekt|sweets|cookie|cepum|biscuit|cake|kūk|torte|ice ?cream|saldējum|tiramis|dessert|desert|donut|doughnut|virtul|brownie|muffin|cupcake|waffle|vafel|chips|čips|crisps|popcorn|pretzel|marshmallow|gumm|halva|macaron|snickers|twix|kit ?kat|bounty|oreo|nutella|sieriņ|kārum)\w*/iu';

    public static function guess(string $name): FoodCategory
    {
        if ((preg_match(self::ALCOHOL, $name) || preg_match(self::ALCOHOL_WORDS, $name)) && !preg_match(self::NON_ALCOHOLIC, $name)) {
            return FoodCategory::Alcohol;
        }

        return preg_match(self::SNACK, $name) ? FoodCategory::Snack : FoodCategory::Food;
    }
}
