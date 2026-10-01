<?php

namespace App\MessageHandler;

use App\Meal\MealEstimation;
use App\Message\EstimateMeal;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class EstimateMealHandler
{
    public function __construct(private readonly MealEstimation $estimation)
    {
    }

    public function __invoke(EstimateMeal $message): void
    {
        $this->estimation->runScheduledAttempt($message->mealEntryId);
    }
}
