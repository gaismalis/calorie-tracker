<?php

namespace App\Estimation;

use App\Ai\AiEstimationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Turning a logged description into numbers, the same way for meals and exercises:
 *
 * 1. The entry is saved immediately, then estimated with a short time limit while the user waits.
 * 2. If that fails or is too slow, it is retried in the background after 10 s, 1 min and 10 min.
 * 3. If all of those fail, it is marked Failed and the user may retry manually once per hour.
 *
 * @template T of Estimable
 */
abstract class EstimationWorkflow
{
    /** Seconds the user waits for the first estimate. */
    public const QUICK_TIME_LIMIT = 5.0;
    /** Delay before each background retry, in seconds; one entry per retry. */
    public const RETRY_DELAYS = [10, 60, 600];
    /** Minimum time between manual retries of a failed entry. */
    public const MANUAL_RETRY_INTERVAL = '1 hour';

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        protected readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Asks the estimator and applies the result to the entry.
     *
     * @param T $entry
     *
     * @return bool false when the description contains nothing to estimate (no food, no activity)
     *
     * @throws AiEstimationException
     */
    abstract protected function estimateAndApply(Estimable $entry, ?float $timeLimit, \DateTimeImmutable $now): bool;

    /** @return T|null */
    abstract protected function find(int $id): ?Estimable;

    /** Message that triggers a background attempt for this entry. */
    abstract protected function retryMessage(int $id): object;

    /** Stored as the error when the AI found nothing to estimate. */
    abstract protected function nothingFoundError(): string;

    /** Background attempt (from the queue). Ignores entries that were deleted or are no longer pending. */
    public function runScheduledAttempt(int $id): ?EstimationOutcome
    {
        $entry = $this->find($id);
        if (!$entry?->isPending()) {
            return null;
        }

        $outcome = $this->attempt($entry, null);
        $this->entityManager->flush();

        return $outcome;
    }

    public function canRetryManually(Estimable $entry): bool
    {
        return $entry->isFailed() && $this->clock->now() >= $this->manualRetryAvailableAt($entry);
    }

    public function manualRetryAvailableAt(Estimable $entry): \DateTimeImmutable
    {
        return ($entry->getLastEstimationAttemptAt() ?? $entry->getCreatedAt())->modify('+'.self::MANUAL_RETRY_INTERVAL);
    }

    /** A user-triggered retry of a Failed entry, with the quick time limit. It stays Failed if this fails too. */
    public function retryManually(Estimable $entry): EstimationOutcome
    {
        if (!$this->canRetryManually($entry)) {
            throw new \LogicException('This entry cannot be retried yet.');
        }

        $outcome = $this->attempt($entry, self::QUICK_TIME_LIMIT, allowAutomaticRetry: false);
        $this->entityManager->flush();

        return $outcome;
    }

    /**
     * Saves a new entry and tries to estimate it within {@see QUICK_TIME_LIMIT}. An entry with nothing to estimate is not kept.
     *
     * @param T $entry
     */
    protected function saveAndEstimate(Estimable $entry): EstimationOutcome
    {
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $outcome = $this->attempt($entry, self::QUICK_TIME_LIMIT);
        if (EstimationOutcome::NoFood === $outcome) {
            $this->entityManager->remove($entry);
        }
        $this->entityManager->flush();

        return $outcome;
    }

    private function attempt(Estimable $entry, ?float $timeLimit, bool $allowAutomaticRetry = true): EstimationOutcome
    {
        $now = $this->clock->now();

        try {
            $found = $this->estimateAndApply($entry, $timeLimit, $now);
        } catch (AiEstimationException $e) {
            $this->logger->warning('Estimation failed', [
                'type' => (new \ReflectionClass($entry))->getShortName(),
                'id' => $entry->getId(),
                'attempt' => $entry->getEstimationAttempts() + 1,
                'exception' => $e,
            ]);

            return $this->failed($entry, $e->getMessage(), $now, $allowAutomaticRetry);
        }

        if (!$found) {
            $entry->estimationFailed($this->nothingFoundError(), $now, giveUp: true);

            return EstimationOutcome::NoFood;
        }

        return EstimationOutcome::Estimated;
    }

    private function failed(Estimable $entry, string $error, \DateTimeImmutable $now, bool $allowAutomaticRetry): EstimationOutcome
    {
        // Attempt 1 is the quick one; retry N follows attempt N.
        $retryIndex = $entry->getEstimationAttempts();
        $retry = $allowAutomaticRetry && $retryIndex < count(self::RETRY_DELAYS);

        $entry->estimationFailed($error, $now, giveUp: !$retry);
        if (!$retry) {
            return EstimationOutcome::Failed;
        }

        $this->entityManager->flush(); // the worker must see the attempt count
        $this->bus->dispatch($this->retryMessage($entry->getId()), [new DelayStamp(self::RETRY_DELAYS[$retryIndex] * 1000)]);

        return EstimationOutcome::Retrying;
    }
}
