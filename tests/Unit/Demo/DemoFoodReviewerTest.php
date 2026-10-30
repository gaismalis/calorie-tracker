<?php

namespace App\Tests\Unit\Demo;

use App\Demo\DemoFoodReviewer;
use App\Review\FoodLog;
use PHPUnit\Framework\TestCase;

class DemoFoodReviewerTest extends TestCase
{
    public function testAReviewWrittenFromTheWeeksNumbers(): void
    {
        $item = fn (string $name, float $kcal, float $protein, string $category = 'food') => ['time' => '12:00', 'name' => $name, 'grams' => 100.0, 'kcal' => $kcal, 'protein' => $protein, 'carbs' => 10.0, 'fat' => 5.0, 'category' => $category];
        $days = [];
        foreach (['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22'] as $day) {
            $days[$day] = [$item('Grilled chicken breast', 1200, 90), $item('Mixed salad with olive oil', 700, 20)];
        }
        $days['2026-10-23'] = [$item('Pepperoni pizza', 1100, 40), $item('Lager beer', 430, 5, 'alcohol'), $item('Cheeseburger', 600, 30)];

        $result = (new DemoFoodReviewer())->review(new FoodLog('2026-10-19', '2026-10-25', $days, -0.5, 'male', 35));

        self::assertSame('demo', $result->reviewedBy);
        $review = $result->review;
        self::assertSame('great', $review->rating);
        self::assertStringContainsString('About 1 946 kcal a day on the 5 logged days', $review->summary);
        self::assertStringContainsString('Drinks and sweets added 430 kcal', $review->summary);
        self::assertContains('You logged 5 of 7 days, so the numbers are reliable.', $review->strengths);
        self::assertStringContainsString('The drinks were 430 kcal', $review->suggestions[0]);
        self::assertStringContainsString('Pepperoni pizza, Cheeseburger', $review->suggestions[1]);
        self::assertSame('Salt', $review->nutrients[0]['name']);
    }
}
