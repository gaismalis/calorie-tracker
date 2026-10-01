<?php

namespace App\Exercise;

use App\Entity\ExerciseEntry;
use App\Entity\User;
use App\Estimation\Estimable;
use App\Estimation\EstimationOutcome;
use App\Estimation\EstimationWorkflow;
use App\Message\EstimateExercise;
use App\Repository\ExerciseEntryRepository;
use App\Repository\WeightEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Estimation of logged exercise. A calorie number the user typed is trusted as-is (no AI call);
 * otherwise the AI estimates from the activity and the user's latest weight.
 *
 * @extends EstimationWorkflow<ExerciseEntry>
 */
final class ExerciseEstimation extends EstimationWorkflow
{
    public const ESTIMATED_BY_USER = 'user';

    public function __construct(
        private readonly ExerciseEstimator $estimator,
        private readonly ExerciseEntryRepository $exercises,
        private readonly WeightEntryRepository $weights,
        EntityManagerInterface $entityManager,
        MessageBusInterface $bus,
        ClockInterface $clock,
        LoggerInterface $logger,
    ) {
        parent::__construct($entityManager, $bus, $clock, $logger);
    }

    /**
     * Saves new exercise (done now unless $performedAt is given) and estimates it right away.
     * Text without any activity is not kept.
     *
     * @return array{ExerciseEntry, EstimationOutcome}
     */
    public function logExercise(User $user, string $description, ?\DateTimeImmutable $performedAt = null): array
    {
        $entry = new ExerciseEntry($user, $description, $performedAt ?? $this->clock->now());

        return [$entry, $this->saveAndEstimate($entry)];
    }

    protected function estimateAndApply(Estimable $entry, ?float $timeLimit, \DateTimeImmutable $now): bool
    {
        $stated = ExplicitCalories::find($entry->getRawText());
        if (null !== $stated) {
            $name = mb_substr(trim($entry->getRawText()), 0, 255);
            $entry->applyEstimate(new ExerciseEstimate([new EstimatedActivity($name, null, $stated, 'as you entered')], self::ESTIMATED_BY_USER), $now);

            return true;
        }

        $weight = $this->weights->findLatest($entry->getUser())?->getWeightKg();
        $estimate = $this->estimator->estimate($entry->getRawText(), $weight, $timeLimit);
        if ([] === $estimate->items) {
            return false;
        }
        $entry->applyEstimate($estimate, $now);

        return true;
    }

    protected function find(int $id): ?ExerciseEntry
    {
        return $this->exercises->find($id);
    }

    protected function retryMessage(int $id): object
    {
        return new EstimateExercise($id);
    }

    protected function nothingFoundError(): string
    {
        return 'No physical activity found in the description.';
    }
}
