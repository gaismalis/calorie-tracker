<?php

namespace App\Nutrition;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GeminiNutritionEstimator implements NutritionEstimator
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

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

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_MODEL')]
        private readonly string $model,
    ) {
    }

    public function estimate(string $mealDescription): MealEstimate
    {
        if ('' === $this->apiKey) {
            throw new NutritionEstimationException('GEMINI_API_KEY is not set. Add it to .env.local.');
        }

        try {
            $response = $this->httpClient->request('POST', sprintf(self::ENDPOINT, rawurlencode($this->model)), [
                'headers' => ['x-goog-api-key' => $this->apiKey],
                'json' => [
                    'systemInstruction' => ['parts' => [['text' => self::INSTRUCTIONS]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $mealDescription]]]],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => self::RESPONSE_SCHEMA,
                    ],
                ],
                'timeout' => 30,
            ]);

            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (HttpException $e) {
            throw new NutritionEstimationException('Could not reach Gemini: '.$e->getMessage(), previous: $e);
        }

        if (200 !== $status) {
            $message = $body['error']['message'] ?? 'unknown error';
            throw new NutritionEstimationException(sprintf('Gemini returned HTTP %d: %s', $status, $message));
        }

        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text)) {
            $reason = $body['candidates'][0]['finishReason'] ?? $body['promptFeedback']['blockReason'] ?? 'no content';
            throw new NutritionEstimationException('Gemini returned no estimate ('.$reason.').');
        }

        try {
            $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new NutritionEstimationException('Gemini returned invalid JSON.', previous: $e);
        }

        if (!is_array($data['items'] ?? null)) {
            throw new NutritionEstimationException('Gemini response is missing "items".');
        }

        $items = [];
        foreach ($data['items'] as $item) {
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

        return new MealEstimate($items, 'gemini:'.($body['modelVersion'] ?? $this->model));
    }

    private static function nonNegative(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, round((float) $value, 1)) : 0.0;
    }
}
