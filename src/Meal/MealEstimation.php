<?php

namespace App\Meal;

use App\Ai\Photo;
use App\Entity\MealEntry;
use App\Entity\PendingMealPhoto;
use App\Entity\Recipe;
use App\Entity\User;
use App\Estimation\Estimable;
use App\Estimation\EstimationOutcome;
use App\Estimation\EstimationWorkflow;
use App\Message\EstimateMeal;
use App\Nutrition\MealEstimate;
use App\Nutrition\NutritionEstimator;
use App\Repository\MealEntryRepository;
use App\Repository\PendingMealPhotoRepository;
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
        private readonly PendingMealPhotoRepository $photos,
        EntityManagerInterface $entityManager,
        MessageBusInterface $bus,
        ClockInterface $clock,
        LoggerInterface $logger,
    ) {
        parent::__construct($entityManager, $bus, $clock, $logger);
    }

    /**
     * Saves a new meal (eaten now unless $eatenAt is given) and tries to estimate it right away.
     * A meal without any food is not kept. A photo is kept only until the meal is estimated.
     *
     * With a recipe, its foods are the base and the description/photo is only what was added: the AI
     * estimates just that. A recipe with nothing added is logged as it is, without the AI.
     *
     * @return array{MealEntry, EstimationOutcome}
     */
    public function logMeal(User $user, string $description, ?\DateTimeImmutable $eatenAt = null, ?Photo $photo = null, ?Recipe $recipe = null): array
    {
        $entry = new MealEntry($user, $description, $eatenAt ?? $this->clock->now());
        if (null !== $recipe) {
            $entry->startFromRecipe($recipe);
            if ('' === trim($description) && null === $photo) {
                $entry->applyEstimate(new MealEstimate([], Recipe::ESTIMATED_BY), $this->clock->now());
                $this->entityManager->persist($entry);
                $this->entityManager->flush();

                return [$entry, EstimationOutcome::Estimated];
            }
        }
        if (null !== $photo) {
            $entry->markFromPhoto();
            $this->entityManager->persist(new PendingMealPhoto($entry, $photo));
        }

        return [$entry, $this->saveAndEstimate($entry)];
    }

    protected function estimateAndApply(Estimable $entry, ?float $timeLimit, \DateTimeImmutable $now): bool
    {
        $pending = $entry->isFromPhoto() ? $this->photos->findForEntry($entry) : null;
        $estimate = $this->estimator->estimate($entry->getRawText(), $timeLimit, $pending?->getPhoto());
        if (null !== $pending) {
            // Estimated (or nothing to estimate): the photo isn't needed any more.
            $this->entityManager->remove($pending);
        }
        if ([] === $estimate->items && !$entry->hasBaseItems()) {
            return false; // with a recipe, the recipe is still a meal
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
