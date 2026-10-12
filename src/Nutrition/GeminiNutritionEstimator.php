<?php

namespace App\Nutrition;

use App\Ai\AiEstimationException;
use App\Ai\GeminiClient;

final class GeminiNutritionEstimator implements NutritionEstimator
{
    private const INSTRUCTIONS = <<<'TXT'
        You are a nutrition estimator for a calorie tracking app. The user describes, in their own words and
        possibly any language, what they ate. Split it into individual food items and give for each item:
        its weight in grams, and its kcal, protein, carbs and fat (grams) PER 100 g.

        Rules:
        - Per-100 g values are what a nutrition label shows. For drinks, give them per 100 ml and use ml as grams.
        - If the user names a specific brand or product (e.g. "Alpro soya yogurt", "Snickers", "Lidl protein
          pudding"), use the nutrition values of that exact product as you know them from its label, and put the
          brand/product in the item name. Only if you don't know that product, use typical values for a generic
          food of that kind and say so in "assumption" (e.g. "brand not known, generic values used").
        - Otherwise use typical values for the most common version of the food. Be realistic; approximate is fine.
        - Use quantities the user gives. If they give none or use vague units ("big tablespoon", "a bowl",
          "handful", "1 pack"), pick a typical portion (for a branded product: its usual package or serving size)
          and state it in "assumption" (e.g. "big tablespoon ≈ 20 g"). Leave "assumption" empty when nothing had
          to be guessed.
        - Name items concisely in the user's language.
        - Give each item a "category": "alcohol" for any alcoholic drink (beer, wine, spirits, cider, cocktails;
          alcohol-free beer is not alcohol); "snack" for sweets, desserts and snack foods (chocolate, candy,
          cookies, cake, ice cream, tiramisu, chips); "food" for everything else, including meals, fast food,
          bread, fruit and non-alcoholic drinks.
        - If the text contains no food or drink at all, return an empty items list.
        TXT;

    private const RESPONSE_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'items' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'grams' => ['type' => 'NUMBER'],
                        'kcal_per_100g' => ['type' => 'NUMBER'],
                        'protein_per_100g' => ['type' => 'NUMBER'],
                        'carbs_per_100g' => ['type' => 'NUMBER'],
                        'fat_per_100g' => ['type' => 'NUMBER'],
                        'assumption' => ['type' => 'STRING'],
                        'category' => ['type' => 'STRING', 'enum' => ['food', 'snack', 'alcohol']],
                    ],
                    'required' => ['name', 'grams', 'kcal_per_100g', 'protein_per_100g', 'carbs_per_100g', 'fat_per_100g', 'category'],
                ],
            ],
        ],
        'required' => ['items'],
    ];

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function estimate(string $mealDescription, ?float $timeLimit = null): MealEstimate
    {
        try {
            $result = $this->gemini->generateJson(
                self::INSTRUCTIONS,
                $mealDescription,
                self::RESPONSE_SCHEMA,
                fn (array $data) => is_array($data['items'] ?? null),
                $timeLimit,
            );
        } catch (AiEstimationException $e) {
            throw new NutritionEstimationException($e->getMessage(), previous: $e);
        }

        $items = [];
        foreach ($result->data['items'] as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || '' === trim($item['name'])) {
                continue;
            }
            $assumption = trim((string) ($item['assumption'] ?? ''));
            // Totals are calculated here from the per-100 g values, so the two always agree.
            $items[] = EstimatedItem::fromPer100g(
                name: trim($item['name']),
                grams: self::nonNegative($item['grams'] ?? 0),
                per100g: Per100g::plausible(
                    self::nonNegative($item['kcal_per_100g'] ?? 0),
                    self::nonNegative($item['protein_per_100g'] ?? 0),
                    self::nonNegative($item['carbs_per_100g'] ?? 0),
                    self::nonNegative($item['fat_per_100g'] ?? 0),
                ),
                assumption: '' === $assumption ? null : $assumption,
                // Unknown or missing: guess from the name rather than calling a beer "food".
                category: FoodCategory::tryFrom((string) ($item['category'] ?? '')) ?? FoodCategoryGuesser::guess($item['name']),
            );
        }

        return new MealEstimate($items, 'gemini:'.$result->model);
    }

    private static function nonNegative(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, round((float) $value, 1)) : 0.0;
    }
}
