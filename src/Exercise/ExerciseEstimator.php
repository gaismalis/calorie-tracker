<?php

namespace App\Exercise;

/**
 * Turns a free-text activity description into calories burned on top of resting metabolism.
 * Implementations talk to an AI provider; the provider is chosen by NUTRITION_PROVIDER, like meals.
 */
interface ExerciseEstimator
{
    /**
     * @param float|null $weightKg  body weight; burn depends on it
     * @param float|null $timeLimit seconds the whole estimate may take; null = provider default
     *
     * @throws ExerciseEstimationException
     */
    public function estimate(string $description, ?float $weightKg, ?float $timeLimit = null): ExerciseEstimate;
}
