<?php

namespace App\Demo;

use App\Review\FoodLog;
use App\Review\FoodReview;
use App\Review\FoodReviewer;
use App\Review\ReviewResult;

/**
 * Weekly food reviews for the demo account, written from the week's numbers by code (WeeklyFoodReviews uses it
 * instead of the AI for the demo user): no API costs, however many visitors page through the weeks.
 */
final class DemoFoodReviewer implements FoodReviewer
{
    public const NAME = 'demo';

    public function review(FoodLog $log, ?float $timeLimit = null): ReviewResult
    {
        $days = max(1, $log->loggedDays());
        $kcal = $protein = $alcohol = $snacks = 0.0;
        $vegetableDays = 0;
        $takeaways = [];
        foreach ($log->days as $items) {
            $vegetables = false;
            foreach ($items as $item) {
                $kcal += $item['kcal'];
                $protein += $item['protein'];
                match ($item['category']) {
                    'alcohol' => $alcohol += $item['kcal'],
                    'snack' => $snacks += $item['kcal'],
                    default => null,
                };
                $vegetables = $vegetables || 1 === preg_match('/salad|broccoli|vegetable/i', $item['name']);
                if (1 === preg_match('/pizza|burger|fries|pelmeni/i', $item['name'])) {
                    $takeaways[$item['name']] = true;
                }
            }
            $vegetableDays += (int) $vegetables;
        }
        $perDay = $kcal / $days;
        $proteinPerDay = $protein / $days;
        $treats = $alcohol + $snacks;

        $rating = match (true) {
            $perDay <= 2150 && $treats < 900 => 'great',
            $perDay <= 2350 => 'good',
            default => 'mixed',
        };
        $summary = sprintf(
            'About %s kcal a day on the %d logged days, %s. %s',
            number_format($perDay, 0, '.', ' '),
            $log->loggedDays(),
            $perDay <= 2300 ? 'which fits losing about half a kilo a week' : 'a little above what fits your goal of half a kilo a week',
            $treats > 0 ? sprintf('Drinks and sweets added %s kcal over the week.', number_format($treats, 0, '.', ' ')) : 'No drinks or sweets this week.',
        );

        $strengths = [];
        if ($proteinPerDay >= 100) {
            $strengths[] = sprintf('Protein averaged %d g a day, which helps keep muscle while losing weight.', (int) round($proteinPerDay));
        }
        $strengths[] = sprintf('You logged %d of %d days, so the numbers are reliable.', $log->loggedDays(), $log->dayCount());
        if ($vegetableDays >= $days / 2) {
            $strengths[] = sprintf('Vegetables on %d of the logged days.', $vegetableDays);
        }

        $suggestions = [];
        if ($alcohol > 0) {
            $suggestions[] = sprintf('The drinks were %s kcal: swapping one beer or glass of wine for sparkling water saves about 200.', number_format($alcohol, 0, '.', ' '));
        }
        if ([] !== $takeaways) {
            $suggestions[] = sprintf('%s: fine as a treat; a side salad instead of a second slice or the fries keeps the evening lighter.', implode(', ', array_keys($takeaways)));
        }
        if ($vegetableDays < $days / 2 || count($suggestions) < 2) {
            $suggestions[] = 'Add a portion of vegetables to lunch on the wrap and sushi days.';
        }

        $nutrients = [];
        if ($vegetableDays < $days / 2) {
            $nutrients[] = ['name' => 'Fibre', 'status' => 'low', 'note' => 'Few vegetables or whole grains on most days.'];
        }
        if (count($takeaways) >= 2) {
            $nutrients[] = ['name' => 'Salt', 'status' => 'high', 'note' => 'Pizza, burgers and pelmeni are salty; drink enough water on those days.'];
        }

        return new ReviewResult(new FoodReview(
            $rating,
            $summary,
            array_slice($strengths, 0, FoodReview::MAX_STRENGTHS),
            array_slice($suggestions, 0, FoodReview::MAX_SUGGESTIONS),
            array_slice($nutrients, 0, FoodReview::MAX_NUTRIENTS),
        ), self::NAME);
    }
}
