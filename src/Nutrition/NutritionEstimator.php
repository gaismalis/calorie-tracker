<?php

namespace App\Nutrition;

/**
 * Turns a free-text meal description into an approximate per-item nutrition breakdown.
 * Implementations talk to an AI provider; swap them via config/services.yaml.
 */
interface NutritionEstimator
{
    /**
     * @param float|null $timeLimit seconds the whole estimate (including retries) may take; null = provider default
     *
     * @throws NutritionEstimationException when the provider fails, runs out of time or returns something unusable
     */
    public function estimate(string $mealDescription, ?float $timeLimit = null): MealEstimate;
}
