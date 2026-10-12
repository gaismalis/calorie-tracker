<?php

namespace App\Nutrition\Categorize;

use App\Ai\AiEstimationException;
use App\Ai\GeminiClient;
use App\Nutrition\FoodCategory;
use App\Nutrition\FoodCategoryGuesser;

/** Asks Gemini for the category of many item names in one request. Names it skips get a keyword guess. */
final class GeminiFoodCategorizer implements FoodCategorizer
{
    private const INSTRUCTIONS = <<<'TXT'
        You categorize food and drink items for a calorie tracking app. You get a JSON list of item names, in
        any language. For each, return its index "i" and a "category":
        - "alcohol": any alcoholic drink (beer, wine, spirits, cider, cocktails). Alcohol-free beer is not alcohol.
        - "snack": sweets, desserts and snack foods (chocolate, candy, cookies, cake, ice cream, tiramisu, chips).
        - "food": everything else, including meals, fast food, bread, fruit and non-alcoholic drinks.
        TXT;

    private const RESPONSE_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'items' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'i' => ['type' => 'INTEGER'],
                        'category' => ['type' => 'STRING', 'enum' => ['food', 'snack', 'alcohol']],
                    ],
                    'required' => ['i', 'category'],
                ],
            ],
        ],
        'required' => ['items'],
    ];

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function categorize(array $names): array
    {
        $names = array_values($names);
        if ([] === $names) {
            return [];
        }

        try {
            $result = $this->gemini->generateJson(
                self::INSTRUCTIONS,
                json_encode($names, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                self::RESPONSE_SCHEMA,
                fn (array $data) => is_array($data['items'] ?? null),
            );
        } catch (AiEstimationException $e) {
            throw new \RuntimeException($e->getMessage(), previous: $e);
        }

        $categories = [];
        foreach ($result->data['items'] as $item) {
            $index = $item['i'] ?? null;
            $category = FoodCategory::tryFrom((string) ($item['category'] ?? ''));
            if (is_int($index) && isset($names[$index]) && null !== $category) {
                $categories[$names[$index]] = $category;
            }
        }
        foreach ($names as $name) {
            $categories[$name] ??= FoodCategoryGuesser::guess($name);
        }

        return $categories;
    }
}
