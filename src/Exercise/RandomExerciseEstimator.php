<?php

namespace App\Exercise;

use Random\Randomizer;

/**
 * Fake provider for local development: no API calls. Splits the text like a real provider would,
 * uses durations the user typed and makes up a plausible burn (4–10 kcal per minute).
 */
final class RandomExerciseEstimator implements ExerciseEstimator
{
    public const NAME = 'random';

    private const SEPARATORS = '/\s*(?:(?<!\d),|,(?!\d)|;|\+|&|\R|\band\b|\bun\b)\s*/iu';
    private const HOURS = '/(\d+(?:[.,]\d+)?)\s*(?:h|hours?|hrs?|st(?:undas?)?)\b/iu';
    private const MINUTES = '/(\d+)\s*(?:min|minutes?|mins|minūtes?)\b/iu';

    private readonly Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    public function estimate(string $description, ?float $weightKg, ?float $timeLimit = null): ExerciseEstimate
    {
        $items = [];
        foreach (preg_split(self::SEPARATORS, $description, flags: PREG_SPLIT_NO_EMPTY) as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }

            $assumption = null;
            if (preg_match(self::HOURS, $part, $m)) {
                $minutes = round((float) str_replace(',', '.', $m[1]) * 60);
            } elseif (preg_match(self::MINUTES, $part, $m)) {
                $minutes = (float) $m[1];
            } else {
                $minutes = (float) $this->randomizer->getInt(20, 90);
                $assumption = 'random duration (fake provider)';
            }

            $items[] = new EstimatedActivity($part, $minutes, round($minutes * $this->randomizer->getFloat(4, 10)), $assumption);
        }

        return new ExerciseEstimate($items, self::NAME);
    }
}
