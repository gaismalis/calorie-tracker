<?php

namespace App\Voice;

use App\Ai\Recording;

/** NUTRITION_PROVIDER=random: always hears the same breakfast, no API calls (local development). */
final class RandomVoiceTranscriber implements VoiceTranscriber
{
    public const NAME = 'random';

    public function foods(Recording $recording, ?float $timeLimit = null): HeardFoods
    {
        return new HeardFoods(['2 eggs', 'a slice of rye bread with butter', 'a coffee with milk'], 'Two eggs, a slice of rye bread with butter and a coffee with milk.');
    }
}
