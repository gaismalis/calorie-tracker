<?php

namespace App\Review;

use App\Ai\AiEstimationException;
use App\Ai\GeminiClient;

final class GeminiFoodReviewer implements FoodReviewer
{
    private const INSTRUCTIONS = <<<'TXT'
        You are a friendly nutrition coach. You get one week of a person's food log from a calorie tracking
        app: every item with its time, grams, kcal and macros (estimates), and whether it was a snack or alcohol.
        Judge the QUALITY of what they eat, not the amount of calories (the app tracks those separately):
        variety, vegetables and fruit, fibre, protein, whole versus processed foods, healthy fats, added
        sugar, salt, alcohol, and which vitamins and minerals are likely short or high, estimated from the
        foods. Use the person's sex, age and goal when they're given. The log may be incomplete and portions
        are estimates. Days that aren't listed weren't logged; never treat them as fasting.

        Answer in English, in plain words, speaking to the person ("you"). Be kind, specific and practical;
        no lecturing and no medical diagnoses. Item names may be in any language.
        - rating: "great", "good", "mixed" or "poor" for the overall quality of the food.
        - summary: 1-2 sentences: the overall picture.
        - strengths: up to 3 short things that are going well, referring to their foods.
        - suggestions: up to 3 concrete changes with example foods and when to eat them (e.g. "Add a
          handful of salad or vegetables to lunch on most days"), most helpful first.
        - nutrients: up to 4 nutrients (vitamins, minerals, fibre, protein, sugar, salt, …) that are
          likely too low or too high, each with status "low" or "high" and a short reason that names foods
          from the log. Leave it empty if nothing stands out.
        TXT;

    private const RESPONSE_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'rating' => ['type' => 'STRING', 'enum' => FoodReview::RATINGS],
            'summary' => ['type' => 'STRING'],
            'strengths' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'suggestions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'nutrients' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'status' => ['type' => 'STRING', 'enum' => ['low', 'high']],
                        'note' => ['type' => 'STRING'],
                    ],
                    'required' => ['name', 'status', 'note'],
                ],
            ],
        ],
        'required' => ['rating', 'summary', 'strengths', 'suggestions', 'nutrients'],
    ];

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function review(FoodLog $log, ?float $timeLimit = null): ReviewResult
    {
        try {
            $result = $this->gemini->generateJson(
                self::INSTRUCTIONS,
                $log->toText(),
                self::RESPONSE_SCHEMA,
                fn (array $data) => is_string($data['summary'] ?? null) && '' !== trim($data['summary']),
                $timeLimit,
            );
        } catch (AiEstimationException $e) {
            throw new FoodReviewException($e->getMessage(), previous: $e);
        }

        return new ReviewResult(FoodReview::fromArray($result->data), 'gemini:'.$result->model);
    }
}
