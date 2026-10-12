<?php

namespace App\Nutrition;

use App\Ai\Photo;

/**
 * Turns a free-text meal description and/or a photo of it into an approximate per-item nutrition breakdown.
 * Implementations talk to an AI provider; swap them via config/services.yaml.
 */
interface NutritionEstimator
{
    /**
     * @param string     $mealDescription what the user wrote; may be empty when there's a photo
     * @param float|null $timeLimit       seconds the whole estimate (including retries) may take; null = provider default
     * @param Photo|null $photo           photo of the meal (or its label); the text adds details to it
     *
     * @throws NutritionEstimationException when the provider fails, runs out of time or returns something unusable
     */
    public function estimate(string $mealDescription, ?float $timeLimit = null, ?Photo $photo = null): MealEstimate;
}
