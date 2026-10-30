<?php

namespace App\Tests\Unit\Demo;

use App\Demo\DemoPlan;
use App\Demo\DemoStory;
use App\Nutrition\FoodCategory;
use PHPUnit\Framework\TestCase;

class DemoPlanTest extends TestCase
{
    private const TIMEZONE = 'Europe/Riga';

    public function testThreeMonthsUpToNowAndNothingLater(): void
    {
        $now = new \DateTimeImmutable('2026-10-30 13:05', new \DateTimeZone(self::TIMEZONE));
        $plan = new DemoPlan($now->setTime(0, 0), $now);

        $weights = $plan->weights();
        self::assertSame('2026-08-02', array_key_first($weights), 'the first of 90 days is weighed');
        self::assertSame('2026-10-30', array_key_last($weights), 'and today');
        self::assertGreaterThan(65, count($weights), 'most mornings');

        $days = array_unique(array_map(fn ($meal) => $meal['at']->format('Y-m-d'), $plan->meals()));
        self::assertSame('2026-08-02', min($days));
        self::assertGreaterThanOrEqual(84, count($days), 'nearly every day logged');
        foreach ([...$plan->meals(), ...$plan->exercises()] as $entry) {
            self::assertLessThanOrEqual($now, $entry['at']);
        }
        $today = array_filter($plan->meals(), fn ($meal) => '2026-10-30' === $meal['at']->format('Y-m-d'));
        self::assertNotEmpty($today, 'breakfast and lunch are in');
        self::assertLessThan(5, count($today), 'dinner is not');
    }

    public function testTheWeightFollowsTheFood(): void
    {
        $now = new \DateTimeImmutable('2026-10-30 22:00', new \DateTimeZone(self::TIMEZONE));
        $plan = new DemoPlan($now->setTime(0, 0), $now);
        $story = new DemoStory($plan);

        self::assertEqualsWithDelta(DemoPlan::START_KG, $story->startKg, 0.5);
        self::assertGreaterThan(4.5, $story->lostKg, 'a visible result');
        self::assertLessThan(8.0, $story->lostKg, 'about half a kilo a week, not a crash diet');
        self::assertGreaterThan(1900, $story->averageKcal);
        self::assertLessThan(2400, $story->averageKcal);
        self::assertSame(13, $story->weeks);
        self::assertCount(13, $story->bars);
        self::assertSame(count($plan->weights()), count(explode(' ', $story->weightPoints)));
    }

    public function testSameDatesSamePlan(): void
    {
        $now = new \DateTimeImmutable('2026-10-30 22:00', new \DateTimeZone(self::TIMEZONE));
        $a = new DemoPlan($now->setTime(0, 0), $now);
        $b = new DemoPlan($now->setTime(0, 0), $now);

        self::assertSame($a->weights(), $b->weights());
        self::assertEquals($a->meals(), $b->meals());
    }

    public function testRealisticMealsWithRecipesTreatsAndWorkouts(): void
    {
        $now = new \DateTimeImmutable('2026-10-30 22:00', new \DateTimeZone(self::TIMEZONE));
        $plan = new DemoPlan($now->setTime(0, 0), $now);

        self::assertSame(['Greek yogurt bowl', 'Coffee with milk'], array_keys($plan->recipes()));
        $fromRecipes = array_filter($plan->meals(), fn ($meal) => null !== $meal['recipe']);
        self::assertNotEmpty(array_filter($fromRecipes, fn ($meal) => '' === $meal['text']), 'recipes as they are');
        self::assertNotEmpty(array_filter($fromRecipes, fn ($meal) => 'a banana' === $meal['text'] && 'Banana' === $meal['items'][0]->name), 'and with extras');

        $categories = [];
        foreach ($plan->meals() as $meal) {
            foreach ($meal['items'] as $item) {
                $categories[$item->category->value] = true;
            }
        }
        self::assertEqualsCanonicalizing([FoodCategory::Food->value, FoodCategory::Snack->value, FoodCategory::Alcohol->value], array_keys($categories));
        self::assertGreaterThan(20, count($plan->exercises()));
        self::assertEqualsWithDelta(146 + 45.6 + 130.8, DemoPlan::kcal(['recipe' => 'Greek yogurt bowl', 'items' => []]), 0.1);
    }
}
