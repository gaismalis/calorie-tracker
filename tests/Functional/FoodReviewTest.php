<?php

namespace App\Tests\Functional;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Entity\WeeklyFoodReview;
use App\Nutrition\EstimatedItem;
use App\Nutrition\FoodCategory;
use App\Nutrition\MealEstimate;
use App\Review\FoodReview;
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

    public function testTheWeeklyOverviewLoadsTheReviewForTheLast7FullDays(): void
    {
        $this->storeMeal(1, 'Porridge');

        $crawler = $this->client->request('GET', '/');

        $frame = $crawler->filter('section.week turbo-frame#food-review');
        self::assertSame('lazy', $frame->attr('loading'), 'only when scrolled into view, without slowing the page');
        $yesterday = $this->user->today()->modify('-1 day')->format('Y-m-d');
        self::assertSame('/week/food-review?to='.$yesterday, $frame->attr('src'));
    }

    public function testEveryItemOfTheWeekIsSentAndTheReviewIsShown(): void
    {
        $this->storeMeal(1, 'Porridge', [new EstimatedItem('Oat porridge', 300, 250, 8, 40, 5), new EstimatedItem('Beer', 500, 215, 2, 18, 0, category: FoodCategory::Alcohol)]);
        $this->storeMeal(3, 'Pizza');
        $this->storeMeal(6, 'Chocolate', [new EstimatedItem('Milk chocolate', 50, 270, 4, 28, 15, category: FoodCategory::Snack)]);
        $this->storeMeal(8, 'Last week, not included');
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

    public function testTooFewLoggedDaysAreNotReviewed(): void
    {
        $this->storeMeal(1, 'Lunch');
        $this->storeMeal(2, 'Lunch');

        $crawler = $this->client->request('GET', $this->reviewUrl());

        self::assertSame([], $this->reviewer()->received);
        self::assertStringContainsString('Log meals on at least 3 of these 7 days', $crawler->filter('turbo-frame#food-review')->text());
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

    public function testAnInvalidDateIsNotFound(): void
    {
        $this->client->request('GET', '/week/food-review?to=last-week');

        self::assertResponseStatusCodeSame(404);
    }

    private function reviewUrl(): string
    {
        return '/week/food-review?to='.$this->user->today()->modify('-1 day')->format('Y-m-d');
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
