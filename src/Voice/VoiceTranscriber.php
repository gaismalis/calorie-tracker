<?php

namespace App\Voice;

use App\Ai\Recording;

/** Turns a voice message into the foods it mentions. Picked by NUTRITION_PROVIDER, like the meal estimator. */
interface VoiceTranscriber
{
    /** @throws VoiceException */
    public function foods(Recording $recording, ?float $timeLimit = null): HeardFoods;
}
