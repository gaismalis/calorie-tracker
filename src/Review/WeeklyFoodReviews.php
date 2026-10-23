<?php

namespace App\Review;

use App\Entity\User;
use App\Entity\WeeklyFoodReview;
use App\Repository\AiUsageRepository;
use App\Repository\MealEntryRepository;
use App\Repository\WeeklyFoodReviewRepository;
use App\Summary\WeeklySummary;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The food review shown in the weekly overview: collects every item eaten in the 7 days, and asks the AI
 * only when there's no stored review for exactly that food yet (so about once a day, or after a past meal
 * in the week is changed). At most DAILY_LIMIT AI requests per user per local day; past that, the stored
 * review (if any) is shown as it is.
 */
final class WeeklyFoodReviews
{
    /** Fewer logged days than this and there's too little to judge. */
    public const MIN_DAYS = 3;
    /** AI requests per user per local day (failed ones count too). */
    public const DAILY_LIMIT = 5;
    public const USAGE_FEATURE = 'food_review';
    /** Seconds the page's request waits for the AI. */
    private const TIME_LIMIT = 25.0;

    public function __construct(
        private readonly MealEntryRepository $meals,
        private readonly WeeklyFoodReviewRepository $reviews,
        private readonly AiUsageRepository $usage,
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
     * The review of the week ending on $lastDay.
     *
     * @throws FoodReviewException when the AI fails
     */
    public function forWeek(User $user, \DateTimeImmutable $lastDay): WeeklyFoodReviewResult
    {
        $log = $this->log($user, $lastDay);
        if ($log->loggedDays() < self::MIN_DAYS) {
            return new WeeklyFoodReviewResult(null, tooFewDays: true);
        }

        $stored = $this->reviews->findForWeek($user, $log->to);
        if (null !== $stored && $stored->isFor($log->hash())) {
            return new WeeklyFoodReviewResult($stored);
        }

        $today = $user->today($this->clock->now())->format('Y-m-d');
        if ($this->usage->usedOn($user, self::USAGE_FEATURE, $today) >= self::DAILY_LIMIT) {
            return new WeeklyFoodReviewResult($stored, limitReached: true);
        }
        $this->usage->record($user, self::USAGE_FEATURE, $today);

        $result = $this->reviewer->review($log, self::TIME_LIMIT);
        $stored ??= new WeeklyFoodReview($user, $log->to);
        $stored->update($log->hash(), $result->review, $result->reviewedBy, $this->clock->now());
        $this->em->persist($stored);
        $this->em->flush();

        return new WeeklyFoodReviewResult($stored);
    }
}
