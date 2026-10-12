<?php

namespace App\Tests\Unit\Nutrition;

use App\Ai\GeminiClient;
use App\Nutrition\FoodCategory;
use App\Nutrition\GeminiNutritionEstimator;
use App\Nutrition\NutritionEstimationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

class GeminiNutritionEstimatorTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-01 12:00:00');
    }

    public function testTotalsAreCalculatedFromPer100gValues(): void
    {
        $estimate = $this->estimator([self::geminiReply([
            'items' => [
                ['name' => 'Greek yogurt', 'grams' => 400, 'kcal_per_100g' => 95, 'protein_per_100g' => 10, 'carbs_per_100g' => 4, 'fat_per_100g' => 4, 'assumption' => ''],
                ['name' => 'Peanut butter', 'grams' => 20, 'kcal_per_100g' => 592.2, 'protein_per_100g' => 25, 'carbs_per_100g' => 20, 'fat_per_100g' => 50, 'assumption' => 'big tablespoon ≈ 20 g'],
            ],
        ])])->estimate('400 g yogurt and a big tablespoon of peanut butter');

        self::assertCount(2, $estimate->items);
        $yogurt = $estimate->items[0];
        self::assertSame('Greek yogurt', $yogurt->name);
        self::assertSame([380.0, 40.0, 16.0, 16.0], [$yogurt->kcal, $yogurt->protein, $yogurt->carbs, $yogurt->fat]);
        self::assertSame(95.0, $yogurt->per100g->kcal);
        self::assertSame(10.0, $yogurt->per100g->protein);
        self::assertNull($yogurt->assumption, 'empty assumption becomes null');
        self::assertSame(118.4, $estimate->items[1]->kcal, '592.2 × 0.2, rounded to 1 decimal');
        self::assertSame('big tablespoon ≈ 20 g', $estimate->items[1]->assumption);
        self::assertSame('gemini:gemini-test-001', $estimate->estimatedBy);
    }

    public function testEachItemGetsACategoryAndUnknownOnesAreGuessedFromTheName(): void
    {
        $item = fn (string $name, ?string $category) => ['name' => $name, 'grams' => 100, 'kcal_per_100g' => 100, 'protein_per_100g' => 1, 'carbs_per_100g' => 1, 'fat_per_100g' => 1] + (null === $category ? [] : ['category' => $category]);
        $estimate = $this->estimator([self::geminiReply(['items' => [
            $item('Burger', 'food'), $item('Beer', 'alcohol'), $item('Brownie', 'snack'), $item('Glass of wine', 'drink'), $item('Cookie', null),
        ]])])->estimate('burger, beer, brownie, wine and a cookie');

        self::assertSame(
            [FoodCategory::Food, FoodCategory::Alcohol, FoodCategory::Snack, FoodCategory::Alcohol, FoodCategory::Snack],
            array_map(fn ($i) => $i->category, $estimate->items),
        );
    }

    public function testImpossiblePer100gValuesAreCapped(): void
    {
        $estimate = $this->estimator([self::geminiReply([
            'items' => [['name' => 'Oil', 'grams' => 10, 'kcal_per_100g' => 1500, 'protein_per_100g' => 0, 'carbs_per_100g' => 0, 'fat_per_100g' => 140]],
        ])])->estimate('oil');

        self::assertSame(900.0, $estimate->items[0]->per100g->kcal);
        self::assertSame(100.0, $estimate->items[0]->per100g->fat);
        self::assertSame(90.0, $estimate->items[0]->kcal);
    }

    public function testClampsNegativeAndNonNumericValuesAndSkipsNamelessItems(): void
    {
        $estimate = $this->estimator([self::geminiReply([
            'items' => [
                ['name' => 'Water', 'grams' => 500, 'kcal_per_100g' => -3, 'protein_per_100g' => 'n/a', 'carbs_per_100g' => 0, 'fat_per_100g' => 0],
                ['name' => '  ', 'grams' => 10, 'kcal_per_100g' => 10, 'protein_per_100g' => 1, 'carbs_per_100g' => 1, 'fat_per_100g' => 1],
                'garbage',
            ],
        ])])->estimate('water');

        self::assertCount(1, $estimate->items);
        self::assertSame(0.0, $estimate->items[0]->kcal);
        self::assertSame(0.0, $estimate->items[0]->protein);
    }

    public function testFailsWithoutApiKey(): void
    {
        $this->expectException(NutritionEstimationException::class);
        $this->expectExceptionMessage('GEMINI_API_KEY');

        (new GeminiNutritionEstimator(new GeminiClient(new MockHttpClient(), '', 'm')))->estimate('an apple');
    }

    public function testFailsWithoutModels(): void
    {
        $this->expectException(NutritionEstimationException::class);
        $this->expectExceptionMessage('GEMINI_MODEL is empty');

        $this->estimator([], ' , ')->estimate('an apple');
    }

    public function testModelListIsTrimmedAndEmptyEntriesIgnored(): void
    {
        $first = self::overloaded();
        $second = self::overloaded();
        $third = self::geminiReply(['items' => []], modelVersion: null);

        $estimate = $this->estimator([$first, $second, $third], ' model-a , ,model-b ')->estimate('an apple');

        self::assertStringEndsWith('/models/model-a:generateContent', $first->getRequestUrl());
        self::assertStringEndsWith('/models/model-b:generateContent', $third->getRequestUrl());
        self::assertSame('gemini:model-b', $estimate->estimatedBy, 'falls back to the requested model name');
    }

    public function testRetriesSameModelOnceAfterOverload(): void
    {
        $retry = self::geminiReply(['items' => []]);

        $this->estimator([self::overloaded(), $retry], 'model-a,model-b')->estimate('an apple');

        self::assertStringEndsWith('/models/model-a:generateContent', $retry->getRequestUrl());
        self::assertSame(1.0, $this->waited());
    }

    public function testFallsBackToNextModelWhenFirstStaysOverloaded(): void
    {
        $fallback = self::geminiReply(['items' => []], modelVersion: 'model-b-001');
        $http = new MockHttpClient([self::overloaded(), self::overloaded(429), $fallback]);

        $estimate = $this->make($http, 'model-a,model-b')->estimate('an apple');

        self::assertSame(3, $http->getRequestsCount());
        self::assertStringEndsWith('/models/model-b:generateContent', $fallback->getRequestUrl());
        self::assertSame('gemini:model-b-001', $estimate->estimatedBy);
        self::assertSame(1.0, $this->waited(), 'only the retry waits, not the switch to the next model');
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function nextModelWithoutRetry(): iterable
    {
        yield 'model not found' => [new JsonMockResponse(['error' => ['message' => 'no longer available']], ['http_code' => 404])];
        yield 'timeout / network error' => [new MockResponse(info: ['error' => 'Idle timeout reached'])];
        yield 'no candidate' => [new JsonMockResponse(['promptFeedback' => ['blockReason' => 'SAFETY']])];
        yield 'invalid JSON' => [new JsonMockResponse(['candidates' => [['content' => ['parts' => [['text' => '{not json']]]]]])];
        yield 'missing items' => [self::geminiReply(['foods' => []])];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nextModelWithoutRetry')]
    public function testSwitchesToNextModelWithoutRetryingWhen(MockResponse $failure): void
    {
        $fallback = self::geminiReply(['items' => []]);
        $http = new MockHttpClient([$failure, $fallback]);

        $this->make($http, 'model-a,model-b')->estimate('an apple');

        self::assertSame(2, $http->getRequestsCount());
        self::assertStringEndsWith('/models/model-b:generateContent', $fallback->getRequestUrl());
        self::assertSame(0.0, $this->waited());
    }

    /** @return iterable<string, array{int}> */
    public static function fatalStatuses(): iterable
    {
        yield 'bad request' => [400];
        yield 'unauthorized' => [401];
        yield 'forbidden' => [403];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fatalStatuses')]
    public function testKeyOrRequestErrorsFailImmediatelyWithoutFallback(int $status): void
    {
        $http = new MockHttpClient([
            new JsonMockResponse(['error' => ['message' => 'API key not valid']], ['http_code' => $status]),
            self::geminiReply(['items' => []]),
        ]);

        try {
            $this->make($http, 'model-a,model-b')->estimate('an apple');
            self::fail('Expected exception');
        } catch (NutritionEstimationException $e) {
            self::assertSame(sprintf('Gemini returned HTTP %d: API key not valid', $status), $e->getMessage());
        }
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testReportsEveryFailureWhenAllModelsFail(): void
    {
        $http = new MockHttpClient([
            self::overloaded(),
            self::overloaded(),
            new MockResponse(info: ['error' => 'Connection refused']),
        ]);

        $this->expectException(NutritionEstimationException::class);
        $this->expectExceptionMessageMatches(
            '/^All Gemini models failed\. model-a: Gemini returned HTTP 503: .* \| model-a: Gemini returned HTTP 503: .* \| model-b: Could not reach Gemini: .*Connection refused/'
        );

        $this->make($http, 'model-a,model-b')->estimate('an apple');
    }

    public function testWithoutTimeLimitAttemptsUseDefaultTimeouts(): void
    {
        $response = self::geminiReply(['items' => []]);

        $this->estimator([$response])->estimate('an apple');

        self::assertSame(20.0, (float) $response->getRequestOptions()['timeout']);
        self::assertSame(25.0, (float) $response->getRequestOptions()['max_duration']);
    }

    public function testTimeLimitCapsTheRequestDuration(): void
    {
        $response = self::geminiReply(['items' => []]);

        $this->estimator([$response])->estimate('an apple', timeLimit: 5);

        self::assertEqualsWithDelta(5.0, $response->getRequestOptions()['max_duration'], 0.001);
        self::assertEqualsWithDelta(5.0, $response->getRequestOptions()['timeout'], 0.001);
    }

    public function testTimeLimitSkipsRetryWaitThatWouldNotFitAndFallsBackInstead(): void
    {
        $fallback = self::geminiReply(['items' => []]);
        $http = new MockHttpClient([self::overloaded(), $fallback]);

        $this->make($http, 'model-a,model-b')->estimate('an apple', timeLimit: 1.2);

        self::assertSame(0.0, $this->waited(), 'a 1 s wait + 0.5 s minimum attempt does not fit in 1.2 s');
        self::assertStringEndsWith('/models/model-b:generateContent', $fallback->getRequestUrl());
    }

    public function testStopsWhenTimeLimitIsUsedUp(): void
    {
        $http = new MockHttpClient(function () {
            $this->clock->sleep(4.8); // the overloaded answer took 4.8 s

            return self::overloaded();
        });

        try {
            $this->make($http, 'model-a,model-b')->estimate('an apple', timeLimit: 5);
            self::fail('Expected exception');
        } catch (NutritionEstimationException $e) {
            self::assertStringStartsWith('Time limit of 5s reached. model-a: Gemini returned HTTP 503', $e->getMessage());
        }
        self::assertSame(1, $http->getRequestsCount(), 'model-b is not tried with only 0.2 s left');
    }

    /** @param list<MockResponse> $responses */
    private function estimator(array $responses, string $models = 'm'): GeminiNutritionEstimator
    {
        return $this->make(new MockHttpClient($responses), $models);
    }

    private function make(MockHttpClient $http, string $models): GeminiNutritionEstimator
    {
        return new GeminiNutritionEstimator(new GeminiClient($http, 'test-key', $models, $this->clock));
    }

    private function waited(): float
    {
        return (float) $this->clock->now()->format('U.u') - (float) (new \DateTimeImmutable('2026-10-01 12:00:00'))->format('U.u');
    }

    private static function overloaded(int $status = 503): JsonMockResponse
    {
        return new JsonMockResponse(['error' => ['message' => 'This model is currently experiencing high demand.']], ['http_code' => $status]);
    }

    /** @param array<string, mixed> $payload */
    private static function geminiReply(array $payload, ?string $modelVersion = 'gemini-test-001'): JsonMockResponse
    {
        return new JsonMockResponse(array_filter([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => json_encode($payload)]]],
                'finishReason' => 'STOP',
            ]],
            'modelVersion' => $modelVersion,
        ]));
    }
}
