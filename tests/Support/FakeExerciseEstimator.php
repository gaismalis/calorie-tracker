<?php

namespace App\Tests\Support;

use App\Exercise\ExerciseEstimate;
use App\Exercise\ExerciseEstimationException;
use App\Exercise\ExerciseEstimator;

/** Stands in for the AI in the test environment. Queue up what the next estimate() call should return. */
final class FakeExerciseEstimator implements ExerciseEstimator
{
    private ExerciseEstimate|ExerciseEstimationException|null $next = null;

    /** @var list<array{description: string, weightKg: float|null, timeLimit: float|null}> */
    public array $calls = [];

    public function willReturn(ExerciseEstimate $estimate): void
    {
        $this->next = $estimate;
    }

    public function willFail(string $message = 'Provider is down'): void
    {
        $this->next = new ExerciseEstimationException($message);
    }

    public function estimate(string $description, ?float $weightKg, ?float $timeLimit = null): ExerciseEstimate
    {
        $this->calls[] = ['description' => $description, 'weightKg' => $weightKg, 'timeLimit' => $timeLimit];
        $next = $this->next ?? new ExerciseEstimate([], 'fake');

        if ($next instanceof ExerciseEstimationException) {
            throw $next;
        }

        return $next;
    }
}
