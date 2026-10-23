<?php

namespace App\Tests\Unit\Review;

use App\Review\FoodReview;
use PHPUnit\Framework\TestCase;

class FoodReviewTest extends TestCase
{
    public function testAnswersAreCleanedUpAndCapped(): void
    {
        $review = FoodReview::fromArray([
            'rating' => 'excellent',
            'summary' => "  Lots of   bread,\nfew vegetables. ",
            'strengths' => ['a', 'b', 'c', 'd', '', 5],
            'suggestions' => ['Add salad to lunch', '  ', 'More fish', 'Fruit as a snack', 'Water'],
            'nutrients' => [
                ['name' => 'Fibre', 'status' => 'low', 'note' => 'little veg'],
                ['name' => 'Salt', 'status' => 'high', 'note' => 'sausages'],
                ['name' => 'Vitamin D', 'status' => 'ok', 'note' => ''],
                ['name' => '', 'status' => 'low'],
                ['name' => 'Iron', 'status' => 'low'],
                ['name' => 'Calcium', 'status' => 'low', 'note' => ''],
                ['name' => 'Sugar', 'status' => 'high', 'note' => ''],
            ],
            'extra' => 'ignored',
        ]);

        self::assertSame('mixed', $review->rating, 'unknown ratings become mixed');
        self::assertSame('Lots of bread, few vegetables.', $review->summary);
        self::assertSame(['a', 'b', 'c'], $review->strengths);
        self::assertSame(['Add salad to lunch', 'More fish', 'Fruit as a snack'], $review->suggestions);
        self::assertSame(['Fibre', 'Salt', 'Iron', 'Calcium'], array_column($review->nutrients, 'name'), 'only low/high, at most 4');
        self::assertSame('', $review->nutrients[2]['note']);
        self::assertEquals($review, FoodReview::fromArray($review->toArray()), 'stored and loaded unchanged');
    }

    public function testLongTextsAreCut(): void
    {
        self::assertSame(300, mb_strlen(FoodReview::fromArray(['summary' => str_repeat('x', 1000)])->summary));
    }
}
