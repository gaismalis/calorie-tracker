<?php

namespace App\MessageHandler;

use App\Exercise\ExerciseEstimation;
use App\Message\EstimateExercise;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class EstimateExerciseHandler
{
    public function __construct(private readonly ExerciseEstimation $estimation)
    {
    }

    public function __invoke(EstimateExercise $message): void
    {
        $this->estimation->runScheduledAttempt($message->exerciseEntryId);
    }
}
