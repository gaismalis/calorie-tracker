<?php

namespace App\Tests\Functional;

use App\Entity\AiUsage;
use App\Entity\MealEntry;
use App\Entity\User;
use App\Entity\WeeklyFoodReview;
use App\Nutrition\EstimatedItem;
use App\Nutrition\FoodCategory;
use App\Nutrition\MealEstimate;
use App\Review\FoodReview;
use App\Review\WeeklyFoodReviews;
use App\Tests\Support\FakeFoodReviewer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** AI review of the week's food quality, in the weekly overview. */
class FoodReviewTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // keep the fake reviewer (and what it received) across requests
        $this->user = (new User())->setEmail('me@example.com')->setPassword('x');
        $this->em()->persist($this->user);
        $this->em()->flush();
        $this->client->loginUser($this->user);
    }

    public function testTheWeeklyOverviewLoadsTheReviewOfTheChartsWeekWithoutToday(): void
    {
        $this->storeMeal(1, 'Porridge');

        $crawler = $this->client->request('GET', '/');

        $frame = $crawler->filter('section.week turbo-frame#food-review');
        self::assertSame('lazy', $frame->attr('loading'), 'only when scrolled into view, without slowing the page');
        self::assertSame($this->reviewUrl(), $frame->attr('src'), 'the 6 days before today (the chart shows today too)');

        $earlier = $this->user->today()->modify('-7 days');
        $this->storeMeal(7, 'Dinner');
        $crawler = $this->client->request('GET', '/?chart=week&chartTo='.$earlier->format('Y-m-d'));
        self::assertSame('/week/food-review?from='.$earlier->modify('-6 days')->format('Y-m-d').'&to='.$earlier->format('Y-m-d'),
            $crawler->filter('turbo-frame#food-review')->attr('src'), 'an earlier page: the same 7 days as the chart');
    }

    public function testEveryItemOfTheWeekIsSentAndTheReviewIsShown(): void
    {
        $this->storeMeal(1, 'Porridge', [new EstimatedItem('Oat porridge', 300, 250, 8, 40, 5), new EstimatedItem('Beer', 500, 215, 2, 18, 0, category: FoodCategory::Alcohol)]);
        $this->storeMeal(3, 'Pizza');
        $this->storeMeal(6, 'Chocolate', [new EstimatedItem('Milk chocolate', 50, 270, 4, 28, 15, category: FoodCategory::Snack)]);
        $this->storeMeal(7, 'On the previous page, not included');
        $this->storeMeal(0, 'Today, not included (the day is not over)');
        $this->reviewer()->willReturn(new FoodReview('mixed', 'Good protein, but few vegetables.', ['Protein at breakfast'], ['Add a side salad to dinner', 'Have fruit instead of chocolate'], [['name' => 'Fibre', 'status' => 'low', 'note' => 'Little veg or whole grain.']]));

        $crawler = $this->client->request('GET', $this->reviewUrl());

        self::assertResponseIsSuccessful();
        $log = $this->reviewer()->received[0];
        self::assertSame(3, $log->loggedDays());
        $text = $log->toText();
        self::assertStringContainsString('Oat porridge: 300 g, 250 kcal', $text);
        self::assertStringContainsString('Beer: 500 g, 215 kcal, protein 2 g, carbs 18 g, fat 0 g (alcohol)', $text);
        self::assertStringContainsString('Milk chocolate', $text);
        self::assertStringNotContainsString('not included', $text);
        self::assertStringNotContainsString('me@example.com', $text, 'nothing that identifies the user');

        $review = $crawler->filter('turbo-frame#food-review');
        self::assertStringContainsString('Mixed', $review->filter('.food-rating')->text());
        self::assertStringContainsString('Good protein, but few vegetables.', $review->text());
        self::assertSame(['Add a side salad to dinner', 'Have fruit instead of chocolate'], $review->filter('.food-suggestions li')->each(fn ($li) => $li->text()));
        self::assertStringContainsString('Fibre ↓ likely low: Little veg or whole grain.', $review->filter('.food-nutrients')->text());
        self::assertStringContainsString('not medical advice', $review->text());
    }

    public function testTheReviewIsStoredAndOnlyRedoneWhenTheWeeksFoodChanges(): void
    {
        foreach ([1, 2, 3] as $daysAgo) {
            $this->storeMeal($daysAgo, 'Lunch');
        }

        $this->client->request('GET', $this->reviewUrl());
        $this->client->request('GET', $this->reviewUrl());
        self::assertCount(1, $this->reviewer()->received, 'the second view uses the stored review');

        $this->storeMeal(0, 'Dinner today'); // not in the week yet
        $this->client->request('GET', $this->reviewUrl());
        self::assertCount(1, $this->reviewer()->received);

        $this->storeMeal(2, 'Forgotten snack');
        $this->client->request('GET', $this->reviewUrl());
        self::assertCount(2, $this->reviewer()->received, 'a meal added to the week: reviewed again');
        self::assertCount(1, $this->em()->getRepository(WeeklyFoodReview::class)->findAll(), 'updated, not duplicated');
    }

    public function testAtMost5AiReviewsPerDayThenTheStoredOneIsShownAsItIs(): void
    {
        foreach ([1, 2, 3] as $daysAgo) {
            $this->storeMeal($daysAgo, 'Lunch');
        }
        $this->reviewer()->willFail(); // failed requests count too
        $this->client->request('GET', $this->reviewUrl());
        $this->reviewer()->willReturn(new FoodReview('good', 'Review number two.', [], [], []));
        for ($i = 2; $i <= 5; ++$i) {
            $this->storeMeal(2, "Snack $i"); // the week's food changed: ask again
            $crawler = $this->client->request('GET', $this->reviewUrl());
        }
        self::assertCount(5, $this->reviewer()->received);
        self::assertCount(0, $crawler->filter('.food-outdated'));

        $this->storeMeal(2, 'Snack 6');
        $crawler = $this->client->request('GET', $this->reviewUrl());

        self::assertCount(5, $this->reviewer()->received, 'the 6th change today is not sent');
        self::assertStringContainsString('Review number two.', $crawler->filter('.food-summary')->text(), 'the stored review is still shown');
        self::assertStringContainsString("From before your latest changes to this week: you've used today's 5 food reviews", $crawler->filter('.food-outdated')->text());

        // Another week (browsing a past day) has nothing stored yet.
        $this->storeMeal(4, 'Breakfast'); // its 3rd logged day
        $crawler = $this->client->request('GET', '/week/food-review?from='.$this->user->today()->modify('-8 days')->format('Y-m-d').'&to='.$this->user->today()->modify('-2 days')->format('Y-m-d'));
        self::assertCount(5, $this->reviewer()->received);
        self::assertStringContainsString("You've used today's 5 food reviews. Come back tomorrow", $crawler->filter('turbo-frame#food-review')->text());
    }

    public function testTheLimitIsPerLocalDay(): void
    {
        foreach ([1, 2, 3] as $daysAgo) {
            $this->storeMeal($daysAgo, 'Lunch');
        }
        $user = $this->em()->find(User::class, $this->user->getId());
        $usage = new AiUsage($user, WeeklyFoodReviews::USAGE_FEATURE, $user->today()->modify('-1 day')->format('Y-m-d'));
        for ($i = 0; $i < 5; ++$i) {
            $usage->increment();
        }
        $this->em()->persist($usage);
        $this->em()->flush();

        $this->client->request('GET', $this->reviewUrl());

        self::assertCount(1, $this->reviewer()->received, "yesterday's 5 don't count today");
    }

    public function testTooFewLoggedDaysAreNotReviewed(): void
    {
        $this->storeMeal(1, 'Lunch');
        $this->storeMeal(2, 'Lunch');

        $crawler = $this->client->request('GET', $this->reviewUrl());

        self::assertSame([], $this->reviewer()->received);
        self::assertStringContainsString('Log meals on at least 3 of these 6 days', $crawler->filter('turbo-frame#food-review')->text());
    }

    public function testAFailedReviewOffersToTryAgain(): void
    {
        foreach ([1, 2, 3] as $daysAgo) {
            $this->storeMeal($daysAgo, 'Lunch');
        }
        $this->reviewer()->willFail();

        $crawler = $this->client->request('GET', $this->reviewUrl());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString("Couldn't review your food right now.", $crawler->text());
        self::assertSame($this->reviewUrl(), $crawler->filter('turbo-frame#food-review a')->attr('href'));
        self::assertCount(0, $this->em()->getRepository(WeeklyFoodReview::class)->findAll());
    }

    public function testAnInvalidDateOrRangeIsNotFound(): void
    {
        foreach (['from=2026-10-01&to=last-week', 'to=2026-10-07', 'from=2026-10-01&to=2026-10-08', 'from=2026-10-07&to=2026-10-01'] as $query) {
            $this->client->request('GET', '/week/food-review?'.$query);
            self::assertResponseStatusCodeSame(404, $query);
        }
    }

    private function reviewUrl(): string
    {
        $today = $this->user->today();

        return '/week/food-review?from='.$today->modify('-6 days')->format('Y-m-d').'&to='.$today->modify('-1 day')->format('Y-m-d');
    }

    /** @param list<EstimatedItem>|null $items */
    private function storeMeal(int $daysAgo, string $text, ?array $items = null): void
    {
        $user = $this->em()->find(User::class, $this->user->getId()); // still managed after a request
        $eatenAt = $user->today()->modify("-$daysAgo days")->setTime(12, 0);
        $entry = new MealEntry($user, $text, $eatenAt);
        $entry->applyEstimate(new MealEstimate($items ?? [new EstimatedItem($text, 300, 500, 20, 50, 20)], 'test'), $eatenAt);
        $this->em()->persist($entry);
        $this->em()->flush();
    }

    private function reviewer(): FakeFoodReviewer
    {
        return static::getContainer()->get(\App\Review\FoodReviewer::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
