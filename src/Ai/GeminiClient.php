<?php

namespace App\Ai;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Structured-JSON calls to Google Gemini with model fallback, retries and an optional time limit.
 *
 * GEMINI_MODEL is a comma-separated list tried in order: overload / rate limits retry the same model
 * once, timeouts, retired models and unusable answers go to the next model, and key/request errors
 * fail at once.
 */
final class GeminiClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    /** Attempts per model when Gemini reports overload (503) or rate limiting (429). */
    private const ATTEMPTS_PER_MODEL = 2;
    private const RETRY_DELAY_SECONDS = 1;
    private const ATTEMPT_IDLE_TIMEOUT = 20;
    private const ATTEMPT_MAX_DURATION = 25;
    /** Don't start an attempt with less time left than this. */
    private const MIN_ATTEMPT_SECONDS = 0.5;
    private const RETRY_SAME_MODEL_STATUSES = [429, 500, 503, 504];
    /** These mean the key or request is wrong; another model won't help. */
    private const FATAL_STATUSES = [400, 401, 403];

    /** @var list<string> */
    private readonly array $models;

    /**
     * @param string $models comma-separated, e.g. "gemini-3.5-flash-lite,gemini-3.5-flash"
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_MODEL')]
        string $models,
        private readonly ClockInterface $clock = new Clock(),
    ) {
        $this->models = array_values(array_filter(array_map('trim', explode(',', $models))));
    }

    /**
     * @param array<mixed>                $schema    Gemini responseSchema the answer must follow
     * @param callable(array<mixed>): bool $isUsable  false makes the client try the next model
     * @param float|null                  $timeLimit seconds for the whole call including retries; null = no limit
     *
     * @throws AiEstimationException
     */
    public function generateJson(string $instructions, string $text, array $schema, callable $isUsable, ?float $timeLimit = null): GeminiResult
    {
        if ('' === $this->apiKey) {
            throw new AiEstimationException('GEMINI_API_KEY is not set. Add it to .env.local.');
        }
        if ([] === $this->models) {
            throw new AiEstimationException('GEMINI_MODEL is empty.');
        }

        $deadline = null === $timeLimit ? null : $this->now() + $timeLimit;
        $failures = [];
        foreach ($this->models as $model) {
            for ($attempt = 1; $attempt <= self::ATTEMPTS_PER_MODEL; ++$attempt) {
                $remaining = null === $deadline ? null : $deadline - $this->now();
                if (null !== $remaining && $remaining < self::MIN_ATTEMPT_SECONDS) {
                    throw new AiEstimationException(self::failureMessage(sprintf('Time limit of %ss reached.', $timeLimit), $failures));
                }

                try {
                    return $this->call($model, $instructions, $text, $schema, $isUsable, $remaining);
                } catch (GeminiAttemptFailed $e) {
                    $failures[] = $model.': '.$e->getMessage();
                    if (!$e->retrySameModel || $attempt === self::ATTEMPTS_PER_MODEL) {
                        break;
                    }
                    $delay = self::RETRY_DELAY_SECONDS * $attempt;
                    if (null !== $deadline && $this->now() + $delay + self::MIN_ATTEMPT_SECONDS > $deadline) {
                        break; // no time to wait for this model; try the next one if time allows
                    }
                    $this->clock->sleep($delay);
                }
            }
        }

        throw new AiEstimationException(self::failureMessage('All Gemini models failed.', $failures));
    }

    /**
     * @throws GeminiAttemptFailed   when retrying or another model may help
     * @throws AiEstimationException when nothing will help (bad key, invalid request)
     */
    private function call(string $model, string $instructions, string $text, array $schema, callable $isUsable, ?float $remaining): GeminiResult
    {
        try {
            $response = $this->httpClient->request('POST', sprintf(self::ENDPOINT, rawurlencode($model)), [
                'headers' => ['x-goog-api-key' => $this->apiKey],
                'json' => [
                    'systemInstruction' => ['parts' => [['text' => $instructions]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $text]]]],
                    // No temperature/topP/topK or thinkingBudget: newer models reject them (400); defaults are used.
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $schema,
                    ],
                ],
                'timeout' => min(self::ATTEMPT_IDLE_TIMEOUT, $remaining ?? INF),
                'max_duration' => min(self::ATTEMPT_MAX_DURATION, $remaining ?? INF),
            ]);

            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (HttpException $e) {
            throw new GeminiAttemptFailed('Could not reach Gemini: '.$e->getMessage(), retrySameModel: false, previous: $e);
        }

        if (200 !== $status) {
            $message = sprintf('Gemini returned HTTP %d: %s', $status, $body['error']['message'] ?? 'unknown error');
            if (in_array($status, self::FATAL_STATUSES, true)) {
                throw new AiEstimationException($message);
            }

            throw new GeminiAttemptFailed($message, retrySameModel: in_array($status, self::RETRY_SAME_MODEL_STATUSES, true));
        }

        $answer = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($answer)) {
            $reason = $body['candidates'][0]['finishReason'] ?? $body['promptFeedback']['blockReason'] ?? 'no content';
            throw new GeminiAttemptFailed('Gemini returned no estimate ('.$reason.').', retrySameModel: false);
        }

        try {
            $data = json_decode($answer, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GeminiAttemptFailed('Gemini returned invalid JSON.', retrySameModel: false, previous: $e);
        }

        if (!is_array($data) || !$isUsable($data)) {
            throw new GeminiAttemptFailed('Gemini response is missing "items".', retrySameModel: false);
        }

        return new GeminiResult($data, $body['modelVersion'] ?? $model);
    }

    /** @param list<string> $failures */
    private static function failureMessage(string $summary, array $failures): string
    {
        return [] === $failures ? $summary : $summary.' '.implode(' | ', $failures);
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
