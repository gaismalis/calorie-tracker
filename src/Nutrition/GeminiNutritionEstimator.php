<?php

namespace App\Nutrition;

use App\Ai\AiEstimationException;
use App\Ai\GeminiClient;

final class GeminiNutritionEstimator implements NutritionEstimator
{
    private const INSTRUCTIONS = <<<'TXT'
        You are a nutrition estimator for a calorie tracking app. The user describes, in their own words and
        possibly any language, what they ate. Split it into individual food items and estimate for each item:
        weight in grams, kcal, protein, carbs and fat (grams).

        Rules:
        - Approximate is fine; be realistic, using typical values for the most common version of the food.
        - Use quantities the user gives. If they give none or use vague units ("big tablespoon", "a bowl",
          "handful"), pick a typical portion and state it in "assumption" (e.g. "big tablespoon ≈ 20 g").
          Leave "assumption" empty when nothing had to be guessed.
        - Name items concisely in the user's language.
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
                        'kcal' => ['type' => 'NUMBER'],
                        'protein' => ['type' => 'NUMBER'],
                        'carbs' => ['type' => 'NUMBER'],
                        'fat' => ['type' => 'NUMBER'],
                        'assumption' => ['type' => 'STRING'],
                    ],
                    'required' => ['name', 'grams', 'kcal', 'protein', 'carbs', 'fat'],
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
            $items[] = new EstimatedItem(
                name: trim($item['name']),
                grams: self::nonNegative($item['grams'] ?? 0),
                kcal: self::nonNegative($item['kcal'] ?? 0),
                protein: self::nonNegative($item['protein'] ?? 0),
                carbs: self::nonNegative($item['carbs'] ?? 0),
                fat: self::nonNegative($item['fat'] ?? 0),
                assumption: '' === $assumption ? null : $assumption,
            );
        }

        return new MealEstimate($items, 'gemini:'.$result->model);
    }

    private static function nonNegative(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, round((float) $value, 1)) : 0.0;
    }
}
