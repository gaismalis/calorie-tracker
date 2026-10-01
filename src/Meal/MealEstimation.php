<?php

namespace App\Meal;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Message\EstimateMeal;
use App\Nutrition\NutritionEstimationException;
use App\Nutrition\NutritionEstimator;
use App\Repository\MealEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Lifecycle of turning a logged meal into numbers:
 *
 * 1. The meal is saved immediately, then estimated with a short time limit while the user waits.
 * 2. If that fails or is too slow, it is retried in the background after 10 s, 1 min and 10 min.
 * 3. If all of those fail, the meal is marked Failed and the user may retry manually once per hour.
 */
final class MealEstimation
{
    /** Seconds the user waits for the first estimate. */
    public const QUICK_TIME_LIMIT = 5.0;
    /** Delay before each background retry, in seconds; one entry per retry. */
    public const RETRY_DELAYS = [10, 60, 600];
    /** Minimum time between manual retries of a failed meal. */
    public const MANUAL_RETRY_INTERVAL = '1 hour';

    private const NO_FOOD = 'No food found in the description.';

    public function __construct(
        private readonly NutritionEstimator $estimator,
        private readonly EntityManagerInterface $entityManager,
        private readonly MealEntryRepository $meals,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Saves a new meal and tries to estimate it within {@see QUICK_TIME_LIMIT}.
     * A meal without any food is not kept.
     *
     * @return array{MealEntry, EstimationOutcome}
     */
    public function logMeal(User $user, string $description): array
    {
        $entry = new MealEntry($user, $description, $this->clock->now());
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $outcome = $this->attempt($entry, self::QUICK_TIME_LIMIT);
        if (EstimationOutcome::NoFood === $outcome) {
            $this->entityManager->remove($entry);
        }
        $this->entityManager->flush();

        return [$entry, $outcome];
    }

    /** Background attempt (from the queue). Ignores meals that were deleted or are no longer pending. */
    public function runScheduledAttempt(int $mealEntryId): ?EstimationOutcome
    {
        $entry = $this->meals->find($mealEntryId);
        if (!$entry?->isPending()) {
            return null;
        }

        $outcome = $this->attempt($entry, null);
        $this->entityManager->flush();

        return $outcome;
    }

    public function canRetryManually(MealEntry $entry): bool
    {
        return $entry->isFailed() && $this->clock->now() >= $this->manualRetryAvailableAt($entry);
    }

    public function manualRetryAvailableAt(MealEntry $entry): \DateTimeImmutable
    {
        return ($entry->getLastEstimationAttemptAt() ?? $entry->getCreatedAt())->modify('+'.self::MANUAL_RETRY_INTERVAL);
    }

    /** A user-triggered retry of a Failed meal, with the quick time limit. It stays Failed if this fails too. */
    public function retryManually(MealEntry $entry): EstimationOutcome
    {
        if (!$this->canRetryManually($entry)) {
            throw new \LogicException('This meal cannot be retried yet.');
        }

        $outcome = $this->attempt($entry, self::QUICK_TIME_LIMIT, allowAutomaticRetry: false);
        $this->entityManager->flush();

        return $outcome;
    }

    private function attempt(MealEntry $entry, ?float $timeLimit, bool $allowAutomaticRetry = true): EstimationOutcome
    {
        $now = $this->clock->now();

        try {
            $estimate = $this->estimator->estimate($entry->getRawText(), $timeLimit);
        } catch (NutritionEstimationException $e) {
            $this->logger->warning('Meal estimation failed', ['meal' => $entry->getId(), 'attempt' => $entry->getEstimationAttempts() + 1, 'exception' => $e]);

            return $this->failed($entry, $e->getMessage(), $now, $allowAutomaticRetry);
        }

        if ([] === $estimate->items) {
            $entry->estimationFailed(self::NO_FOOD, $now, giveUp: true);

            return EstimationOutcome::NoFood;
        }

        $entry->applyEstimate($estimate, $now);

        return EstimationOutcome::Estimated;
    }

    private function failed(MealEntry $entry, string $error, \DateTimeImmutable $now, bool $allowAutomaticRetry): EstimationOutcome
    {
        // Attempt 1 is the quick one; retry N follows attempt N.
        $retryIndex = $entry->getEstimationAttempts();
        $retry = $allowAutomaticRetry && $retryIndex < count(self::RETRY_DELAYS);

        $entry->estimationFailed($error, $now, giveUp: !$retry);
        if (!$retry) {
            return EstimationOutcome::Failed;
        }

        $this->entityManager->flush(); // the worker must see the attempt count
        $this->bus->dispatch(new EstimateMeal($entry->getId()), [new DelayStamp(self::RETRY_DELAYS[$retryIndex] * 1000)]);

        return EstimationOutcome::Retrying;
    }
}
