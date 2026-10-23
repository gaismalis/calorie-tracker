<?php

namespace App\Tests\Unit\Review;

use App\Ai\GeminiClient;
use App\Review\FoodLog;
use App\Review\FoodReviewException;
use App\Review\GeminiFoodReviewer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

class GeminiFoodReviewerTest extends TestCase
{
    public function testSendsTheFoodLogAndReadsTheReview(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent) {
            $sent = json_decode($options['body'], true);

            return new JsonMockResponse([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'rating' => 'good',
                    'summary' => 'Plenty of protein, but few vegetables.',
                    'strengths' => ['Protein at every meal'],
                    'suggestions' => ['Add a side salad to dinner'],
                    'nutrients' => [['name' => 'Fibre', 'status' => 'low', 'note' => 'Mostly white bread and pasta.']],
                ])]]], 'finishReason' => 'STOP']],
                'modelVersion' => 'gemini-test-001',
            ]);
        });
        $log = new FoodLog('2026-10-14', '2026-10-20', ['2026-10-14' => [
            ['time' => '12:00', 'name' => 'Chicken and pasta', 'grams' => 400.0, 'kcal' => 640.0, 'protein' => 45.0, 'carbs' => 70.0, 'fat' => 15.0, 'category' => 'food'],
        ]], -0.5);

        $result = (new GeminiFoodReviewer(new GeminiClient($http, 'key', 'm', new MockClock())))->review($log, 20.0);

        self::assertSame($log->toText(), $sent['contents'][0]['parts'][0]['text']);
        self::assertStringContainsString('QUALITY of what they eat, not the amount of calories', $sent['systemInstruction']['parts'][0]['text']);
        self::assertSame(['great', 'good', 'mixed', 'poor'], $sent['generationConfig']['responseSchema']['properties']['rating']['enum']);
        self::assertSame('good', $result->review->rating);
        self::assertSame(['Add a side salad to dinner'], $result->review->suggestions);
        self::assertSame('Fibre', $result->review->nutrients[0]['name']);
        self::assertSame('gemini:gemini-test-001', $result->reviewedBy);
    }

    public function testFailuresBecomeFoodReviewExceptions(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['error' => ['message' => 'API key not valid']], ['http_code' => 400]));

        $this->expectException(FoodReviewException::class);
        (new GeminiFoodReviewer(new GeminiClient($http, 'key', 'm', new MockClock())))->review(new FoodLog('2026-10-14', '2026-10-20', [], 0.0));
    }
}
