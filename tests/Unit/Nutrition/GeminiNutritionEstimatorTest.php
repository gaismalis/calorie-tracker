<?php

namespace App\Tests\Unit\Nutrition;

use App\Nutrition\GeminiNutritionEstimator;
use App\Nutrition\NutritionEstimationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

class GeminiNutritionEstimatorTest extends TestCase
{
    public function testParsesItemsFromStructuredResponse(): void
    {
        $response = self::geminiReply([
            'items' => [
                ['name' => 'Greek yogurt', 'grams' => 400, 'kcal' => 380, 'protein' => 40, 'carbs' => 16, 'fat' => 16, 'assumption' => ''],
                ['name' => 'Peanut butter', 'grams' => 20, 'kcal' => 118.44, 'protein' => 5, 'carbs' => 4, 'fat' => 10, 'assumption' => 'big tablespoon ≈ 20 g'],
            ],
        ]);
        $http = new MockHttpClient($response);

        $estimate = (new GeminiNutritionEstimator($http, 'test-key', 'gemini-flash-latest'))
            ->estimate('400 g yogurt and a big tablespoon of peanut butter');

        self::assertCount(2, $estimate->items);
        self::assertSame('Greek yogurt', $estimate->items[0]->name);
        self::assertSame(380.0, $estimate->items[0]->kcal);
        self::assertNull($estimate->items[0]->assumption, 'empty assumption becomes null');
        self::assertSame(118.4, $estimate->items[1]->kcal, 'values are rounded to 1 decimal');
        self::assertSame('big tablespoon ≈ 20 g', $estimate->items[1]->assumption);
        self::assertSame('gemini:gemini-2.5-flash-001', $estimate->estimatedBy);
    }

    public function testSendsKeyModelAndSchema(): void
    {
        $response = self::geminiReply(['items' => []]);
        $http = new MockHttpClient($response);

        (new GeminiNutritionEstimator($http, 'test-key', 'gemini-flash-latest'))->estimate('an apple');

        self::assertSame('POST', $response->getRequestMethod());
        self::assertStringEndsWith('/models/gemini-flash-latest:generateContent', $response->getRequestUrl());
        self::assertContains('x-goog-api-key: test-key', $response->getRequestOptions()['headers']);

        $body = json_decode($response->getRequestOptions()['body'], true);
        self::assertSame('an apple', $body['contents'][0]['parts'][0]['text']);
        self::assertSame('application/json', $body['generationConfig']['responseMimeType']);
        self::assertArrayHasKey('items', $body['generationConfig']['responseSchema']['properties']);
    }

    public function testClampsNegativeAndNonNumericValuesAndSkipsNamelessItems(): void
    {
        $http = new MockHttpClient(self::geminiReply([
            'items' => [
                ['name' => 'Water', 'grams' => 500, 'kcal' => -3, 'protein' => 'n/a', 'carbs' => 0, 'fat' => 0],
                ['name' => '  ', 'grams' => 10, 'kcal' => 10, 'protein' => 1, 'carbs' => 1, 'fat' => 1],
                'garbage',
            ],
        ]));

        $estimate = (new GeminiNutritionEstimator($http, 'test-key', 'm'))->estimate('water');

        self::assertCount(1, $estimate->items);
        self::assertSame(0.0, $estimate->items[0]->kcal);
        self::assertSame(0.0, $estimate->items[0]->protein);
    }

    public function testFailsWithoutApiKey(): void
    {
        $this->expectException(NutritionEstimationException::class);
        $this->expectExceptionMessage('GEMINI_API_KEY');

        (new GeminiNutritionEstimator(new MockHttpClient(), '', 'm'))->estimate('an apple');
    }

    public function testFailsOnHttpError(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(
            ['error' => ['message' => 'Quota exceeded']],
            ['http_code' => 429],
        ));

        $this->expectException(NutritionEstimationException::class);
        $this->expectExceptionMessage('HTTP 429: Quota exceeded');

        (new GeminiNutritionEstimator($http, 'test-key', 'm'))->estimate('an apple');
    }

    public function testFailsWhenNoCandidateIsReturned(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['promptFeedback' => ['blockReason' => 'SAFETY']]));

        $this->expectException(NutritionEstimationException::class);
        $this->expectExceptionMessage('SAFETY');

        (new GeminiNutritionEstimator($http, 'test-key', 'm'))->estimate('an apple');
    }

    public function testFailsOnInvalidJson(): void
    {
        $http = new MockHttpClient(new JsonMockResponse([
            'candidates' => [['content' => ['parts' => [['text' => '{not json']]]]],
        ]));

        $this->expectException(NutritionEstimationException::class);
        $this->expectExceptionMessage('invalid JSON');

        (new GeminiNutritionEstimator($http, 'test-key', 'm'))->estimate('an apple');
    }

    public function testFailsOnNetworkError(): void
    {
        $http = new MockHttpClient(new MockResponse(info: ['error' => 'Connection refused']));

        $this->expectException(NutritionEstimationException::class);
        $this->expectExceptionMessage('Could not reach Gemini');

        (new GeminiNutritionEstimator($http, 'test-key', 'm'))->estimate('an apple');
    }

    /** @param array<string, mixed> $payload */
    private static function geminiReply(array $payload): JsonMockResponse
    {
        return new JsonMockResponse([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => json_encode($payload)]]],
                'finishReason' => 'STOP',
            ]],
            'modelVersion' => 'gemini-2.5-flash-001',
        ]);
    }
}
