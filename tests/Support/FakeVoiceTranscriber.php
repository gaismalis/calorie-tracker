<?php

namespace App\Tests\Support;

use App\Ai\Recording;
use App\Voice\HeardFoods;
use App\Voice\VoiceException;
use App\Voice\VoiceTranscriber;

/** Stands in for Gemini's voice transcription in the test environment. Records what it gets. */
final class FakeVoiceTranscriber implements VoiceTranscriber
{
    private HeardFoods|VoiceException|null $next = null;

    /** @var list<Recording> */
    public array $received = [];

    public function willHear(string ...$foods): void
    {
        $this->next = new HeardFoods(array_values($foods), implode(' and ', $foods));
    }

    public function willFail(string $message = 'Provider is down'): void
    {
        $this->next = new VoiceException($message);
    }

    public function foods(Recording $recording, ?float $timeLimit = null): HeardFoods
    {
        $this->received[] = $recording;
        if ($this->next instanceof VoiceException) {
            throw $this->next;
        }

        return $this->next ?? new HeardFoods(['an apple'], 'an apple');
    }
}
