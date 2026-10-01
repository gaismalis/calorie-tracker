<?php

namespace App\Tests\Functional;

use App\Entity\MealEntry;
use App\Entity\MealItem;
use App\Entity\WeightEntry;
use App\Profile\ActivityLevel;
use App\Profile\Sex;
use App\Entity\User;
use App\Nutrition\EstimatedItem;
use App\Nutrition\MealEstimate;
use App\Nutrition\NutritionEstimator;
use App\Tests\Support\FakeNutritionEstimator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MealLoggingTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // keep the same FakeNutritionEstimator instance across requests
        $this->user = $this->createUser('eater@example.com');
        $this->client->loginUser($this->user);
    }

    public function testLoggingAMealStoresItemsAndShowsTotals(): void
    {
        $this->estimator()->willReturn(new MealEstimate([
            new EstimatedItem('Greek yogurt', 400, 380, 40, 16, 16),
            new EstimatedItem('Peanut butter', 20, 120, 5, 4, 10, 'big tablespoon ≈ 20 g'),
        ], 'gemini:test'));

        $this->client->request('GET', '/');
        $this->client->submitForm('Log meal', ['description' => '400 g yogurt + big tbsp peanut butter']);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Logged ~500 kcal');
        self::assertSelectorTextContains('.totals', '500');
        self::assertSelectorTextContains('.entry', 'Greek yogurt');
        self::assertSelectorTextContains('.entry .assumption', 'big tablespoon ≈ 20 g');

        $entry = $this->em()->getRepository(MealEntry::class)->findOneBy(['user' => $this->user]);
        self::assertSame('400 g yogurt + big tbsp peanut butter', $entry->getRawText());
        self::assertSame('gemini:test', $entry->getEstimatedBy());
        self::assertSame(500.0, $entry->getKcal(), 'totals are summed from items');
        self::assertSame(45.0, $entry->getProtein());
        self::assertCount(2, $entry->getItems());
    }

    public function testEmptyDescriptionIsRejectedWithoutCallingTheAi(): void
    {
        $this->client->request('GET', '/');
        $this->client->submitForm('Log meal', ['description' => '   ']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'Tell me what you ate first');
        self::assertSame([], $this->estimator()->received);
    }

    public function testTooLongDescriptionIsRejected(): void
    {
        $this->client->request('GET', '/');
        $form = $this->client->getCrawler()->selectButton('Log meal')->form();
        $form->disableValidation()->setValues(['description' => str_repeat('a', 1001)]);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'under 1000 characters');
        self::assertSame([], $this->estimator()->received);
    }

    public function testProviderFailureShowsFriendlyErrorAndStoresNothing(): void
    {
        $this->estimator()->willFail('HTTP 429: Quota exceeded');

        $this->client->request('GET', '/');
        $this->client->submitForm('Log meal', ['description' => 'an apple']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'Could not estimate that meal');
        self::assertSame(0, $this->em()->getRepository(MealEntry::class)->count([]));
    }

    public function testTextWithoutFoodIsNotStored(): void
    {
        $this->estimator()->willReturn(new MealEstimate([], 'gemini:test'));

        $this->client->request('GET', '/');
        $this->client->submitForm('Log meal', ['description' => 'hello there']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', "couldn't find any food");
        self::assertSame(0, $this->em()->getRepository(MealEntry::class)->count([]));
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/meals', ['description' => 'an apple', '_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->estimator()->received);
    }

    public function testDashboardShowsOnlyTodaysMealsOfCurrentUser(): void
    {
        $this->storeEntry($this->user, 'today meal', new \DateTimeImmutable('today 08:00'));
        $this->storeEntry($this->user, 'yesterday meal', new \DateTimeImmutable('yesterday 20:00'));
        $this->storeEntry($this->createUser('other@example.com'), 'someone else meal', new \DateTimeImmutable('today 09:00'));

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('today meal', $text);
        self::assertStringNotContainsString('yesterday meal', $text);
        self::assertStringNotContainsString('someone else meal', $text);
    }

    public function testTodayFollowsTheUsersTimezone(): void
    {
        $this->user->setTimezone('Pacific/Kiritimati'); // UTC+14: "today" there starts 14 h before UTC midnight
        $this->em()->flush();
        $kiritimatiMidnight = $this->user->today();
        $this->storeEntry($this->user, 'just after local midnight', $kiritimatiMidnight->modify('+5 minutes'));
        $this->storeEntry($this->user, 'just before local midnight', $kiritimatiMidnight->modify('-5 minutes'));

        $crawler = $this->client->request('GET', '/');

        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('just after local midnight', $text);
        self::assertStringNotContainsString('just before local midnight', $text);
        self::assertSelectorTextContains('.entry .time', '00:05', 'times are shown in the user timezone');
    }

    public function testDashboardAsksForMissingProfileDataAndWeight(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.target-missing', 'add your sex, birth date, height and activity level in your profile and log your weight.');
        self::assertSelectorNotExists('.target .bar');
    }

    public function testDashboardAsksOnlyForWeightWhenProfileIsComplete(): void
    {
        $this->completeProfile();

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.target-missing', 'To see your daily calorie target, log your weight.');
    }

    public function testDashboardShowsTargetAndRemainingCalories(): void
    {
        $this->completeProfile(); // male, 30 y, 180 cm, moderate
        $this->em()->persist(new WeightEntry($this->user, $this->user->today(), 80));
        $this->em()->flush();
        $this->storeEntry($this->user, 'lunch', new \DateTimeImmutable()); // 200 kcal

        $this->client->request('GET', '/');

        $age = $this->user->getAge();
        $tdee = round(round(10 * 80 + 6.25 * 180 - 5 * $age + 5) * 1.55);
        self::assertSelectorTextContains('.target', sprintf('200 of ~%d kcal · %d left', $tdee, $tdee - 200));
        self::assertSelectorNotExists('.target-missing');
    }

    public function testDashboardShowsCaloriesOverTarget(): void
    {
        $this->completeProfile();
        $this->em()->persist(new WeightEntry($this->user, $this->user->today(), 80));
        foreach (range(1, 15) as $i) {
            $this->storeEntry($this->user, 'meal '.$i, new \DateTimeImmutable()); // 15 × 200 = 3000 kcal
        }

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.target', 'over');
    }

    public function testEntriesFromTheRandomProviderAreMarkedAsFake(): void
    {
        $this->estimator()->willReturn(new MealEstimate([new EstimatedItem('Rice', 100, 130, 3, 28, 0)], 'random'));

        $this->client->request('GET', '/');
        $this->client->submitForm('Log meal', ['description' => 'rice']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.entry .badge', 'fake');
    }

    public function testEntriesFromRealProvidersAreNotMarkedAsFake(): void
    {
        $this->storeEntry($this->user, 'real meal', new \DateTimeImmutable());

        $this->client->request('GET', '/');

        self::assertSelectorNotExists('.entry .badge');
    }

    public function testUserCanDeleteOwnMeal(): void
    {
        $this->storeEntry($this->user, 'to delete', new \DateTimeImmutable());

        $this->client->request('GET', '/');
        $this->client->submitForm('✕');

        self::assertResponseRedirects('/');
        self::assertSame(0, $this->em()->getRepository(MealEntry::class)->count([]));
    }

    public function testUserCannotDeleteSomeoneElsesMeal(): void
    {
        $entry = $this->storeEntry($this->createUser('other@example.com'), 'not yours', new \DateTimeImmutable());

        $this->client->request('POST', '/meals/'.$entry->getId().'/delete', ['_token' => 'irrelevant']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->em()->getRepository(MealEntry::class)->count([]));
    }

    private function completeProfile(): void
    {
        $this->user->setSex(Sex::Male)->setBirthDate(new \DateTimeImmutable('-30 years -1 day'))->setHeightCm(180)->setActivityLevel(ActivityLevel::Moderate);
        $this->em()->flush();
    }

    private function estimator(): FakeNutritionEstimator
    {
        return static::getContainer()->get(NutritionEstimator::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('not-used');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function storeEntry(User $user, string $text, \DateTimeImmutable $eatenAt): MealEntry
    {
        $entry = new MealEntry($user, $text, $eatenAt, 'fake');
        $entry->addItem(new MealItem($entry, 'Something', 100, 200, 10, 20, 5));
        $this->em()->persist($entry);
        $this->em()->flush();

        return $entry;
    }
}
