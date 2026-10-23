<?php

namespace App\Review;

final readonly class ReviewResult
{
    public function __construct(
        public FoodReview $review,
        /** Who reviewed it, e.g. "gemini:gemini-3.5-flash-lite" or "random". */
        public string $reviewedBy,
    ) {
    }
}
