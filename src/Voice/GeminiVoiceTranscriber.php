<?php

namespace App\Voice;

use App\Ai\AiEstimationException;
use App\Ai\GeminiClient;
use App\Ai\Recording;

final class GeminiVoiceTranscriber implements VoiceTranscriber
{
    private const INSTRUCTIONS = <<<'TXT'
        You hear a short voice message in which a person tells a calorie tracking app what they ate or drank.
        They may speak any language (often English or Latvian).
        - transcript: what they said, word for word, in the language they spoke.
        - foods: every food or drink they mention as a separate item, with the amount or portion as they said
          it, in the language they spoke (e.g. "2 eggs", "200 g Greek yogurt", "a big spoon of peanut butter",
          "kafija ar pienu"). Keep things they describe as one dish together ("spaghetti bolognese"). Fix
          obvious mishearings of food names. Never add foods or amounts they didn't say.
        If they don't mention any food or drink, return an empty foods list.
        TXT;

    private const RESPONSE_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'transcript' => ['type' => 'STRING'],
            'foods' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        ],
        'required' => ['transcript', 'foods'],
    ];

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function foods(Recording $recording, ?float $timeLimit = null): HeardFoods
    {
        try {
            $result = $this->gemini->generateJson(
                self::INSTRUCTIONS,
                'The voice message is attached.',
                self::RESPONSE_SCHEMA,
                fn (array $data) => is_array($data['foods'] ?? null),
                $timeLimit,
                [$recording],
            );
        } catch (AiEstimationException $e) {
            throw new VoiceException($e->getMessage(), previous: $e);
        }

        $foods = array_values(array_filter(array_map(
            fn ($food) => is_string($food) ? mb_substr(trim($food), 0, 200) : '',
            $result->data['foods'],
        ), fn (string $food) => '' !== $food));

        return new HeardFoods(array_slice($foods, 0, 30), trim((string) ($result->data['transcript'] ?? '')));
    }
}
