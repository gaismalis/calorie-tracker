<?php

namespace App\Controller;

use App\Entity\User;
use App\Review\FoodReviewException;
use App\Review\WeeklyFoodReviews;
use App\Summary\WeeklySummary;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The AI food review in the weekly overview. Loaded into its own Turbo Frame, so the main page doesn't wait for it. */
#[IsGranted('ROLE_USER')]
final class FoodReviewController extends AbstractController
{
    #[Route('/week/food-review', name: 'app_food_review', methods: ['GET'])]
    public function show(WeeklyFoodReviews $reviews, LoggerInterface $logger, #[MapQueryParameter] string $from = '', #[MapQueryParameter] string $to = ''): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $parse = function (string $date) use ($user): ?\DateTimeImmutable {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $user->getDateTimeZone());

            return false !== $parsed && $parsed->format('Y-m-d') === $date ? $parsed : null;
        };
        $firstDay = $parse($from);
        $lastDay = $parse($to);
        if (null === $firstDay || null === $lastDay || $firstDay > $lastDay || $firstDay->diff($lastDay)->days >= WeeklySummary::DAYS) {
            throw $this->createNotFoundException('Use ?from=YYYY-MM-DD&to=YYYY-MM-DD, at most 7 days.');
        }

        $result = null;
        $failed = false;
        try {
            $result = $reviews->forWeek($user, $firstDay, $lastDay);
        } catch (FoodReviewException $e) {
            $logger->warning('Food review failed: {message}', ['message' => $e->getMessage()]);
            $failed = true;
        }

        return $this->render('dashboard/_food_review.html.twig', [
            'review' => $result?->review,
            'tooFewDays' => $result?->tooFewDays ?? false,
            'limitReached' => $result?->limitReached ?? false,
            'failed' => $failed,
            'from' => $from,
            'to' => $to,
            'days' => $firstDay->diff($lastDay)->days + 1,
            'minDays' => WeeklyFoodReviews::MIN_DAYS,
            'dailyLimit' => WeeklyFoodReviews::DAILY_LIMIT,
        ]);
    }
}
