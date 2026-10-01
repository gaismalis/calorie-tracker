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

    /** Attempts per model when Gemini reports overload (503) or rate limiting (429). */
    private const ATTEMPTS_PER_MODEL = 2;
    private const RETRY_DELAY_MS = 1000;
    private const RETRY_SAME_MODEL_STATUSES = [429, 500, 503, 504];
    /** These mean the key or request is wrong; another model won't help. */
    private const FATAL_STATUSES = [400, 401, 403];

    /** @var list<string> */
    private readonly array $models;
    private readonly \Closure $sleep;

    /**
     * @param string $models comma-separated, tried in order: on overload, timeouts or an unusable answer
     *                       the next model is used, e.g. "gemini-3.5-flash-lite,gemini-3.5-flash"
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_MODEL')]
        string $models,
        ?\Closure $sleep = null,
    ) {
        $this->models = array_values(array_filter(array_map('trim', explode(',', $models))));
        $this->sleep = $sleep ?? static fn (int $milliseconds) => usleep($milliseconds * 1000);
    }

    public function estimate(string $mealDescription): MealEstimate
    {
        if ('' === $this->apiKey) {
            throw new NutritionEstimationException('GEMINI_API_KEY is not set. Add it to .env.local.');
        }
        if ([] === $this->models) {
            throw new NutritionEstimationException('GEMINI_MODEL is empty.');
        }

        $failures = [];
        foreach ($this->models as $model) {
            for ($attempt = 1; $attempt <= self::ATTEMPTS_PER_MODEL; ++$attempt) {
                try {
                    return $this->estimateWith($model, $mealDescription);
                } catch (GeminiAttemptFailed $e) {
                    $failures[] = $model.': '.$e->getMessage();
                    if (!$e->retrySameModel || $attempt === self::ATTEMPTS_PER_MODEL) {
                        break;
                    }
                    ($this->sleep)(self::RETRY_DELAY_MS * $attempt);
                }
            }
        }

        throw new NutritionEstimationException('All Gemini models failed. '.implode(' | ', $failures));
    }

    /**
     * @throws GeminiAttemptFailed          when retrying or another model may help
     * @throws NutritionEstimationException when nothing will help (bad key, invalid request)
     */
    private function estimateWith(string $model, string $mealDescription): MealEstimate
    {
        try {
            $response = $this->httpClient->request('POST', sprintf(self::ENDPOINT, rawurlencode($model)), [
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
                'timeout' => 20,
                'max_duration' => 25,
            ]);

            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (HttpException $e) {
            throw new GeminiAttemptFailed('Could not reach Gemini: '.$e->getMessage(), retrySameModel: false, previous: $e);
        }

        if (200 !== $status) {
            $message = sprintf('Gemini returned HTTP %d: %s', $status, $body['error']['message'] ?? 'unknown error');
            if (in_array($status, self::FATAL_STATUSES, true)) {
                throw new NutritionEstimationException($message);
            }

            throw new GeminiAttemptFailed($message, retrySameModel: in_array($status, self::RETRY_SAME_MODEL_STATUSES, true));
        }

        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text)) {
            $reason = $body['candidates'][0]['finishReason'] ?? $body['promptFeedback']['blockReason'] ?? 'no content';
            throw new GeminiAttemptFailed('Gemini returned no estimate ('.$reason.').', retrySameModel: false);
        }

        try {
            $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GeminiAttemptFailed('Gemini returned invalid JSON.', retrySameModel: false, previous: $e);
        }

        if (!is_array($data['items'] ?? null)) {
            throw new GeminiAttemptFailed('Gemini response is missing "items".', retrySameModel: false);
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

        return new MealEstimate($items, 'gemini:'.($body['modelVersion'] ?? $model));
    }

    private static function nonNegative(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, round((float) $value, 1)) : 0.0;
    }
}
