<?php

namespace App\Tests\Unit\Exercise;

use App\Ai\GeminiClient;
use App\Exercise\ExerciseEstimationException;
use App\Exercise\GeminiExerciseEstimator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

class GeminiExerciseEstimatorTest extends TestCase
{
    public function testParsesActivitiesAndSendsWeight(): void
    {
        $response = self::reply(['items' => [
            ['name' => 'Basketball', 'minutes' => 120, 'kcal' => 980.4, 'assumption' => ''],
            ['name' => 'Workout', 'kcal' => 600, 'user_provided' => true],
            ['name' => 'Walk', 'minutes' => 0, 'kcal' => -5, 'assumption' => 'assumed 20 min'],
        ]]);

        $estimate = $this->estimator($response)->estimate('basketball 2h, workout 600 kcal, a walk', 82.3, 5);

        $body = json_decode($response->getRequestOptions()['body'], true);
        self::assertSame("Body weight: 82.3 kg\n\nWhat I did: basketball 2h, workout 600 kcal, a walk", $body['contents'][0]['parts'][0]['text']);
        self::assertArrayHasKey('user_provided', $body['generationConfig']['responseSchema']['properties']['items']['items']['properties']);

        [$basketball, $workout, $walk] = $estimate->items;
        self::assertSame([120.0, 980.0, null], [$basketball->minutes, $basketball->kcal, $basketball->assumption]);
        self::assertSame([null, 600.0, 'as you entered'], [$workout->minutes, $workout->kcal, $workout->assumption]);
        self::assertSame([null, 0.0, 'assumed 20 min'], [$walk->minutes, $walk->kcal, $walk->assumption]);
        self::assertSame('gemini:gemini-test-001', $estimate->estimatedBy);
    }

    public function testUsesDefaultWeightWhenUnknown(): void
    {
        $response = self::reply(['items' => []]);

        $this->estimator($response)->estimate('yoga', null);

        self::assertStringStartsWith('Body weight: 75 kg', json_decode($response->getRequestOptions()['body'], true)['contents'][0]['parts'][0]['text']);
    }

    public function testFailuresBecomeExerciseEstimationExceptions(): void
    {
        $this->expectException(ExerciseEstimationException::class);
        $this->expectExceptionMessage('HTTP 401');

        $this->estimator(new JsonMockResponse(['error' => ['message' => 'bad key']], ['http_code' => 401]))->estimate('yoga', 70);
    }

    private function estimator(JsonMockResponse $response): GeminiExerciseEstimator
    {
        return new GeminiExerciseEstimator(new GeminiClient(new MockHttpClient($response), 'test-key', 'm'));
    }

    /** @param array<string, mixed> $payload */
    private static function reply(array $payload): JsonMockResponse
    {
        return new JsonMockResponse([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($payload)]]]]],
            'modelVersion' => 'gemini-test-001',
        ]);
    }
}
