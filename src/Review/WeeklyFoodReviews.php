<?php

namespace App\Review;

use App\Entity\User;
use App\Entity\WeeklyFoodReview;
use App\Repository\MealEntryRepository;
use App\Repository\WeeklyFoodReviewRepository;
use App\Summary\WeeklySummary;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The food review shown in the weekly overview: collects every item eaten in the 7 days, and asks the AI
 * only when there's no stored review for exactly that food yet (so about once a day, or after a past meal
 * in the week is changed).
 */
final class WeeklyFoodReviews
{
    /** Fewer logged days than this and there's too little to judge. */
    public const MIN_DAYS = 3;
    /** Seconds the page's request waits for the AI. */
    private const TIME_LIMIT = 25.0;

    public function __construct(
        private readonly MealEntryRepository $meals,
        private readonly WeeklyFoodReviewRepository $reviews,
        private readonly FoodReviewer $reviewer,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function log(User $user, \DateTimeImmutable $lastDay): FoodLog
    {
        $first = $lastDay->modify(sprintf('-%d days', WeeklySummary::DAYS - 1));

        return new FoodLog(
            $first->format('Y-m-d'),
            $lastDay->format('Y-m-d'),
            $this->meals->itemsByDay($user, $first, $lastDay->modify('+1 day')),
            $user->getWeeklyGoalKg(),
            $user->getSex()?->value,
            $user->getAge(),
        );
    }

    /**
     * The review of the week ending on $lastDay; null when fewer than MIN_DAYS days have meals.
     *
     * @throws FoodReviewException when the AI fails and there's no stored review to show
     */
    public function forWeek(User $user, \DateTimeImmutable $lastDay): ?WeeklyFoodReview
    {
        $log = $this->log($user, $lastDay);
        if ($log->loggedDays() < self::MIN_DAYS) {
            return null;
        }

        $stored = $this->reviews->findForWeek($user, $log->to);
        if (null !== $stored && $stored->isFor($log->hash())) {
            return $stored;
        }

        $result = $this->reviewer->review($log, self::TIME_LIMIT);
        $stored ??= new WeeklyFoodReview($user, $log->to);
        $stored->update($log->hash(), $result->review, $result->reviewedBy, $this->clock->now());
        $this->em->persist($stored);
        $this->em->flush();

        return $stored;
    }
}
