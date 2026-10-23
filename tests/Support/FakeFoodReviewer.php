<?php

namespace App\Tests\Support;

use App\Review\FoodLog;
use App\Review\FoodReview;
use App\Review\FoodReviewer;
use App\Review\FoodReviewException;
use App\Review\ReviewResult;

/** Stands in for Gemini's food review in the test environment. Records the logs it gets. */
final class FakeFoodReviewer implements FoodReviewer
{
    private FoodReview|FoodReviewException|null $next = null;

    /** @var list<FoodLog> */
    public array $received = [];

    public function willReturn(FoodReview $review): void
    {
        $this->next = $review;
    }

    public function willFail(string $message = 'Provider is down'): void
    {
        $this->next = new FoodReviewException($message);
    }

    public function review(FoodLog $log, ?float $timeLimit = null): ReviewResult
    {
        $this->received[] = $log;
        if ($this->next instanceof FoodReviewException) {
            throw $this->next;
        }

        return new ReviewResult($this->next ?? new FoodReview('good', 'Fake review.', [], [], []), 'fake');
    }
}
