<?php

namespace App\Nutrition;

/**
 * Turns a free-text meal description into an approximate per-item nutrition breakdown.
 * Implementations talk to an AI provider; swap them via config/services.yaml.
 */
interface NutritionEstimator
{
    /** @throws NutritionEstimationException when the provider fails or returns something unusable */
    public function estimate(string $mealDescription): MealEstimate;
}
