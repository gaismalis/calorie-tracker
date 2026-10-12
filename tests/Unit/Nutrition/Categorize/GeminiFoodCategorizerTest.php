<?php

namespace App\Tests\Unit\Nutrition\Categorize;

use App\Ai\GeminiClient;
use App\Nutrition\Categorize\GeminiFoodCategorizer;
use App\Nutrition\FoodCategory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

class GeminiFoodCategorizerTest extends TestCase
{
    public function testMapsAnswersBackToNamesAndGuessesSkippedOnes(): void
    {
        $response = self::reply(['items' => [
            ['i' => 0, 'category' => 'alcohol'],
            ['i' => 1, 'category' => 'snack'],
            ['i' => 7, 'category' => 'food'],        // no such name: ignored
            ['i' => 2, 'category' => 'beverage'],    // not a category: guessed instead
        ]]);
        $categorizer = new GeminiFoodCategorizer(new GeminiClient(new MockHttpClient([$response]), 'test-key', 'gemini-test'));

        $categories = $categorizer->categorize(['Aldaris alus', 'Kārums', 'Red wine', 'Cheeseburger']);

        self::assertSame(FoodCategory::Alcohol, $categories['Aldaris alus']);
        self::assertSame(FoodCategory::Snack, $categories['Kārums']);
        self::assertSame(FoodCategory::Alcohol, $categories['Red wine'], 'keyword guess');
        self::assertSame(FoodCategory::Food, $categories['Cheeseburger'], 'skipped by the AI: keyword guess');
        $body = json_decode($response->getRequestOptions()['body'], true);
        self::assertSame('["Aldaris alus","Kārums","Red wine","Cheeseburger"]', $body['contents'][0]['parts'][0]['text']);
    }

    public function testFailureIsReportedSoTheItemsAreTriedAgainLater(): void
    {
        $categorizer = new GeminiFoodCategorizer(new GeminiClient(new MockHttpClient(), '', 'gemini-test'));

        $this->expectException(\RuntimeException::class);
        $categorizer->categorize(['Beer']);
    }

    /** @param array<mixed> $data */
    private static function reply(array $data): JsonMockResponse
    {
        return new JsonMockResponse([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($data)]]], 'finishReason' => 'STOP']],
            'modelVersion' => 'gemini-test-001',
        ]);
    }
}
