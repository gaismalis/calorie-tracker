<?php

namespace App\Review;

use App\Entity\WeeklyFoodReview;

final readonly class WeeklyFoodReviewResult
{
    public function __construct(
        /** null when there are too few logged days, or the daily limit is reached with nothing stored. */
        public ?WeeklyFoodReview $review,
        public bool $tooFewDays = false,
        /** Today's AI reviews are used up: $review (if any) is from before the week's latest changes. */
        public bool $limitReached = false,
    ) {
    }
}
