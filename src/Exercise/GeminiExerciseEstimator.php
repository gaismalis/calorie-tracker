<?php

namespace App\Exercise;

use App\Ai\AiEstimationException;
use App\Ai\GeminiClient;

final class GeminiExerciseEstimator implements ExerciseEstimator
{
    /** Used when the user hasn't logged a weight yet. */
    private const DEFAULT_WEIGHT_KG = 75;

    private const INSTRUCTIONS = <<<'TXT'
        You estimate calories burned by physical activity for a calorie tracking app. The user describes,
        in their own words and possibly any language, what they did. Split it into individual activities and
        estimate for each: duration in minutes and the kcal burned BY THE ACTIVITY on top of resting
        metabolism (net calories), for the body weight given.

        Rules:
        - Approximate is fine; use typical intensities (MET values) for the activity as described.
        - Use durations the user gives. If they give none or are vague, pick a typical duration and state it
          in "assumption" (e.g. "assumed 45 min"). Leave "assumption" empty when nothing had to be guessed.
        - If the user states the calories burned for an activity, use exactly that number and set
          "user_provided" to true.
        - Name activities concisely in the user's language.
        - Everyday tasks that aren't exercise (cooking, sitting, driving) are not activities.
        - If the text contains no physical activity at all, return an empty items list.
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
                        'minutes' => ['type' => 'NUMBER'],
                        'kcal' => ['type' => 'NUMBER'],
                        'assumption' => ['type' => 'STRING'],
                        'user_provided' => ['type' => 'BOOLEAN'],
                    ],
                    'required' => ['name', 'kcal'],
                ],
            ],
        ],
        'required' => ['items'],
    ];

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function estimate(string $description, ?float $weightKg, ?float $timeLimit = null): ExerciseEstimate
    {
        $text = sprintf("Body weight: %s kg\n\nWhat I did: %s", round($weightKg ?? self::DEFAULT_WEIGHT_KG, 1), $description);

        try {
            $result = $this->gemini->generateJson(
                self::INSTRUCTIONS,
                $text,
                self::RESPONSE_SCHEMA,
                fn (array $data) => is_array($data['items'] ?? null),
                $timeLimit,
            );
        } catch (AiEstimationException $e) {
            throw new ExerciseEstimationException($e->getMessage(), previous: $e);
        }

        $items = [];
        foreach ($result->data['items'] as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || '' === trim($item['name'])) {
                continue;
            }
            $assumption = trim((string) ($item['assumption'] ?? ''));
            if (true === ($item['user_provided'] ?? false)) {
                $assumption = trim('as you entered. '.$assumption);
            }
            $assumption = rtrim($assumption, '. ');
            $minutes = is_numeric($item['minutes'] ?? null) && $item['minutes'] > 0 ? round((float) $item['minutes']) : null;
            $items[] = new EstimatedActivity(
                name: trim($item['name']),
                minutes: $minutes,
                kcal: is_numeric($item['kcal'] ?? null) ? max(0.0, round((float) $item['kcal'])) : 0.0,
                assumption: '' === $assumption ? null : $assumption,
            );
        }

        return new ExerciseEstimate($items, 'gemini:'.$result->model);
    }
}
