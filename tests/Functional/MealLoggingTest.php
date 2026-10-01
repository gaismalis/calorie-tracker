<?php

namespace App\Tests\Functional;

use App\Entity\MealEntry;
use App\Entity\MealItem;
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
