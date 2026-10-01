<?php

namespace App\Meal;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Estimation\Estimable;
use App\Estimation\EstimationOutcome;
use App\Estimation\EstimationWorkflow;
use App\Message\EstimateMeal;
use App\Nutrition\NutritionEstimator;
use App\Repository\MealEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Estimation of logged meals; the lifecycle (quick try, background retries, manual retry) is in {@see EstimationWorkflow}.
 *
 * @extends EstimationWorkflow<MealEntry>
 */
final class MealEstimation extends EstimationWorkflow
{
    public function __construct(
        private readonly NutritionEstimator $estimator,
        private readonly MealEntryRepository $meals,
        EntityManagerInterface $entityManager,
        MessageBusInterface $bus,
        ClockInterface $clock,
        LoggerInterface $logger,
    ) {
        parent::__construct($entityManager, $bus, $clock, $logger);
    }

    /**
     * Saves a new meal (eaten now unless $eatenAt is given) and tries to estimate it right away.
     * A meal without any food is not kept.
     *
     * @return array{MealEntry, EstimationOutcome}
     */
    public function logMeal(User $user, string $description, ?\DateTimeImmutable $eatenAt = null): array
    {
        $entry = new MealEntry($user, $description, $eatenAt ?? $this->clock->now());

        return [$entry, $this->saveAndEstimate($entry)];
    }

    protected function estimateAndApply(Estimable $entry, ?float $timeLimit, \DateTimeImmutable $now): bool
    {
        $estimate = $this->estimator->estimate($entry->getRawText(), $timeLimit);
        if ([] === $estimate->items) {
            return false;
        }
        $entry->applyEstimate($estimate, $now);

        return true;
    }

    protected function find(int $id): ?MealEntry
    {
        return $this->meals->find($id);
    }

    protected function retryMessage(int $id): object
    {
        return new EstimateMeal($id);
    }

    protected function nothingFoundError(): string
    {
        return 'No food found in the description.';
    }
}
