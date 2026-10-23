<?php

namespace App\Review;

/** Reviews the quality of a week's food. Picked by NUTRITION_PROVIDER, like the meal estimator. */
interface FoodReviewer
{
    /** @throws FoodReviewException */
    public function review(FoodLog $log, ?float $timeLimit = null): ReviewResult;
}
