<?php

namespace App\Review;

/** NUTRITION_PROVIDER=random: a fixed sample review, no API calls (local development). */
final class RandomFoodReviewer implements FoodReviewer
{
    public const NAME = 'random';

    public function review(FoodLog $log, ?float $timeLimit = null): ReviewResult
    {
        return new ReviewResult(new FoodReview(
            'mixed',
            sprintf('Sample review of %d logged days (NUTRITION_PROVIDER=random, so no AI was asked).', $log->loggedDays()),
            ['You logged your meals'],
            ['Add a portion of vegetables to lunch and dinner', 'Have fruit instead of one sweet snack'],
            [['name' => 'Fibre', 'status' => 'low', 'note' => 'Sample: few vegetables, fruit or whole grains.']],
        ), self::NAME);
    }
}
