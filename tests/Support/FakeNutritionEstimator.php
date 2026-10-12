<?php

namespace App\Tests\Support;

use App\Ai\Photo;
use App\Nutrition\MealEstimate;
use App\Nutrition\NutritionEstimationException;
use App\Nutrition\NutritionEstimator;

/** Stands in for Gemini in the test environment. Queue up what the next estimate() call should return. */
final class FakeNutritionEstimator implements NutritionEstimator
{
    private MealEstimate|NutritionEstimationException|null $next = null;

    /** @var list<string> */
    public array $received = [];

    /** @var list<float|null> time limit passed with each call */
    public array $timeLimits = [];

    /** @var list<Photo|null> photo passed with each call */
    public array $photos = [];

    public function willReturn(MealEstimate $estimate): void
    {
        $this->next = $estimate;
    }

    public function willFail(string $message = 'Provider is down'): void
    {
        $this->next = new NutritionEstimationException($message);
    }

    public function estimate(string $mealDescription, ?float $timeLimit = null, ?Photo $photo = null): MealEstimate
    {
        $this->received[] = $mealDescription;
        $this->timeLimits[] = $timeLimit;
        $this->photos[] = $photo;
        $next = $this->next ?? new MealEstimate([], 'fake');

        if ($next instanceof NutritionEstimationException) {
            throw $next;
        }

        return $next;
    }
}
