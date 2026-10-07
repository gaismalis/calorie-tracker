<?php

namespace App\Tests\Functional;

use App\Entity\MealEntry;
use App\Entity\WeightEntry;
use App\Meal\MealEstimation;
use App\Estimation\EstimationStatus;
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
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

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

        $this->client->request('GET', '/meals/new');
        $this->client->submitForm('Log meal', ['description' => '400 g yogurt + big tbsp peanut butter']);

        $entry = $this->em()->getRepository(MealEntry::class)->findOneBy(['user' => $this->user]);
        self::assertResponseRedirects('/meals/'.$entry->getId().'/edit?new=1', message: 'goes to the review ("we think it is …") first');
        self::assertSame([MealEstimation::QUICK_TIME_LIMIT], $this->estimator()->timeLimits, 'waits at most 5 s');

        $this->client->followRedirect();
        self::assertSelectorTextContains('#entry-title', "We think it's about 500 kcal");
        self::assertSelectorExists('turbo-frame#entry-panel form.review[data-reload-on-close] input[type=range]');
        self::assertSelectorTextContains('.review-details table.adjust', 'Peanut butter');
        $this->client->submitForm('Save');

        self::assertResponseRedirects('/?log=food');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Logged ~500 kcal');
        self::assertSelectorTextContains('.stat-eaten', '500');
        self::assertSelectorTextContains('.macros', '45 g');
        self::assertSelectorTextContains('.entry', 'Greek yogurt');
        self::assertSelectorTextContains('.entry .assumption', 'big tablespoon ≈ 20 g');

        self::assertSame('400 g yogurt + big tbsp peanut butter', $entry->getRawText());
        self::assertSame(EstimationStatus::Estimated, $entry->getStatus());
        self::assertSame('gemini:test', $entry->getEstimatedBy());
        self::assertSame(500.0, $entry->getKcal(), 'totals are summed from items');
        self::assertSame(45.0, $entry->getProtein());
        self::assertCount(2, $entry->getItems());
    }

    public function testEmptyDescriptionIsRejectedWithoutCallingTheAi(): void
    {
        $this->client->request('GET', '/meals/new');
        $this->client->submitForm('Log meal', ['description' => '   ']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'Tell me what you ate first');
        self::assertSame([], $this->estimator()->received);
    }

    public function testTooLongDescriptionIsRejected(): void
    {
        $this->client->request('GET', '/meals/new');
        $form = $this->client->getCrawler()->selectButton('Log meal')->form();
        $form->disableValidation()->setValues(['description' => str_repeat('a', 1001)]);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'under 1000 characters');
        self::assertSame([], $this->estimator()->received);
    }

    public function testSlowOrFailingProviderSavesMealAndEstimatesInBackground(): void
    {
        $this->estimator()->willFail('Time limit of 5s reached.');

        $this->client->request('GET', '/meals/new');
        $this->client->submitForm('Log meal', ['description' => 'an apple']);

        self::assertResponseRedirects('/');
        self::assertCount(1, $this->asyncTransport()->getSent(), 'background retry is queued'); // before the next request resets the transport
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-info', "we'll estimate it in the background");
        self::assertSelectorTextContains('.entry-pending', 'Estimating…');
        self::assertSame('10', $crawler->filter('meta[http-equiv="refresh"]')->attr('content'), 'page refreshes while pending');

        $entry = $this->em()->getRepository(MealEntry::class)->findOneBy(['user' => $this->user]);
        self::assertSame(EstimationStatus::Pending, $entry->getStatus());
    }

    public function testNoAutoRefreshWithoutPendingMeals(): void
    {
        $this->storeEntry($this->user, 'done', new \DateTimeImmutable());

        $this->client->request('GET', '/');

        self::assertSelectorNotExists('meta[http-equiv="refresh"]');
    }

    public function testFailedMealOffersRetryAfterAnHour(): void
    {
        $entry = $this->storeFailedEntry(new \DateTimeImmutable('-61 minutes'));
        $this->estimator()->willReturn(new MealEstimate([new EstimatedItem('Apple', 180, 95, 0.5, 25, 0.3)], 'gemini:test'));

        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.entry-failed', "Couldn't estimate");
        $this->client->submitForm('Retry');

        self::assertResponseRedirects('/meals/'.$entry->getId().'/edit');
        $this->em()->clear();
        self::assertSame(EstimationStatus::Estimated, $this->em()->find(MealEntry::class, $entry->getId())->getStatus());
    }

    public function testFailedMealShowsWhenRetryIsPossibleAndRejectsEarlyRetry(): void
    {
        $lastAttempt = new \DateTimeImmutable('-10 minutes');
        $entry = $this->storeFailedEntry($lastAttempt);

        $this->client->request('GET', '/');
        $expected = $lastAttempt->modify('+1 hour')->setTimezone($this->user->getDateTimeZone())->format('H:i');
        self::assertSelectorTextContains('.entry-failed .retry', 'You can retry at '.$expected);
        self::assertSelectorExists('.entry-failed .retry button[disabled]');

        // Submit anyway (e.g. a stale page or a crafted request)
        $form = $this->client->getCrawler()->filter('.entry-failed .retry form')->form();
        $this->client->request('POST', $form->getUri(), $form->getValues());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'once per hour');
        self::assertSame([], $this->estimator()->received);
    }

    public function testRetryRequiresValidCsrfTokenAndOwnership(): void
    {
        $entry = $this->storeFailedEntry(new \DateTimeImmutable('-2 hours'));
        $this->client->request('POST', '/meals/'.$entry->getId().'/retry', ['_token' => 'nope']);
        self::assertResponseStatusCodeSame(403);

        $other = $this->storeFailedEntry(new \DateTimeImmutable('-2 hours'), $this->createUser('other@example.com'));
        $this->client->request('POST', '/meals/'.$other->getId().'/retry', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdjustingGramsScalesNutritionProportionally(): void
    {
        $entry = $this->storeEntry($this->user, 'yogurt and jam', new \DateTimeImmutable(), [
            new EstimatedItem('Yogurt', 400, 240, 16, 18, 12),
            new EstimatedItem('Jam', 50, 125, 0.2, 30, 0.1),
        ]);
        [$yogurt, $jam] = $entry->getItems()->toArray();

        $this->client->request('GET', '/meals/'.$entry->getId().'/edit');
        $this->client->submitForm('Save', ['grams['.$yogurt->getId().']' => '300', 'grams['.$jam->getId().']' => '25,5']);

        self::assertResponseRedirects('/?log=food');
        $this->em()->clear();
        $entry = $this->em()->find(MealEntry::class, $entry->getId());
        [$yogurt, $jam] = $entry->getItems()->toArray();
        self::assertSame(300.0, $yogurt->getGrams());
        self::assertSame(180.0, $yogurt->getKcal());
        self::assertSame(12.0, $yogurt->getProtein());
        self::assertSame(25.5, $jam->getGrams());
        self::assertEqualsWithDelta(63.75, $jam->getKcal(), 0.001);
        self::assertEqualsWithDelta(243.75, $entry->getKcal(), 0.001, 'meal total is recalculated');
    }

    public function testSettingAnItemToZeroRemovesIt(): void
    {
        $entry = $this->storeEntry($this->user, 'yogurt and jam', new \DateTimeImmutable(), [
            new EstimatedItem('Yogurt', 400, 240, 16, 18, 12),
            new EstimatedItem('Jam', 50, 125, 0.2, 30, 0.1),
        ]);
        [$yogurt, $jam] = $entry->getItems()->toArray();

        $this->client->request('GET', '/meals/'.$entry->getId().'/edit');
        $this->client->submitForm('Save', ['grams['.$yogurt->getId().']' => '400', 'grams['.$jam->getId().']' => '0']);

        $this->em()->clear();
        $entry = $this->em()->find(MealEntry::class, $entry->getId());
        self::assertCount(1, $entry->getItems());
        self::assertSame(240.0, $entry->getKcal());
    }

    public function testSettingAllItemsToZeroRemovesTheMeal(): void
    {
        $entry = $this->storeEntry($this->user, 'x', new \DateTimeImmutable());
        $item = $entry->getItems()->first();

        $this->client->request('GET', '/meals/'.$entry->getId().'/edit');
        $this->client->submitForm('Save', ['grams['.$item->getId().']' => '0']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-success', 'meal was removed');
        self::assertSame(0, $this->em()->getRepository(MealEntry::class)->count([]));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidGrams(): iterable
    {
        yield 'negative' => ['-5'];
        yield 'too much' => ['5001'];
        yield 'not a number' => ['lots'];
        yield 'empty' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidGrams')]
    public function testInvalidGramsAreRejectedAndNothingChanges(string $grams): void
    {
        $entry = $this->storeEntry($this->user, 'x', new \DateTimeImmutable());
        $item = $entry->getItems()->first();

        $this->client->request('GET', '/meals/'.$entry->getId().'/edit');
        $form = $this->client->getCrawler()->selectButton('Save')->form();
        $form->disableValidation()->setValues(['grams['.$item->getId().']' => $grams]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('table.adjust', 'Enter grams between 0 and 5000');
        $this->em()->clear();
        self::assertSame(100.0, $this->em()->find(MealEntry::class, $entry->getId())->getItems()->first()->getGrams());
    }

    public function testEditRequiresOwnershipCsrfAndAnEstimate(): void
    {
        $other = $this->storeEntry($this->createUser('other@example.com'), 'x', new \DateTimeImmutable());
        $this->client->request('GET', '/meals/'.$other->getId().'/edit');
        self::assertResponseStatusCodeSame(404);

        $own = $this->storeEntry($this->user, 'x', new \DateTimeImmutable());
        $this->client->request('POST', '/meals/'.$own->getId().'/edit', ['_token' => 'nope']);
        self::assertResponseStatusCodeSame(403);

        $failed = $this->storeFailedEntry(new \DateTimeImmutable());
        $this->client->request('GET', '/meals/'.$failed->getId().'/edit');
        self::assertResponseRedirects('/?log=food');
    }

    public function testTextWithoutFoodIsNotStored(): void
    {
        $this->estimator()->willReturn(new MealEstimate([], 'gemini:test'));

        $this->client->request('GET', '/meals/new');
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

        self::assertSelectorTextContains('.target-missing', 'To see what you burn and your daily target, log your weight');
        self::assertSelectorTextContains('.stat-burned', 'unknown yet');
        self::assertSelectorNotExists('.target .bar');
    }

    public function testDashboardAsksOnlyForWeightWhenProfileIsComplete(): void
    {
        $this->completeProfile();

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.target-missing', 'To see what you burn and your daily target, log your weight');
    }

    public function testWeightAloneGivesARoughEstimateAndSaysWhatWouldImproveIt(): void
    {
        $user = $this->em()->find(User::class, $this->user->getId());
        $this->em()->persist(new WeightEntry($user, $user->today(), 80));
        $this->em()->flush();

        $this->client->request('GET', '/');

        self::assertSelectorNotExists('.target-missing');
        self::assertSelectorTextContains('.stat-burned', '1 932');
        self::assertSelectorTextContains('.overview .how', '~1 932 kcal/day without workouts is a rough estimate from your weight');
        self::assertSelectorTextContains('.overview .how', 'Add your sex, birth date, height and activity level in your profile');
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
        self::assertSelectorTextContains('.stat-eaten', '200');
        self::assertSelectorTextContains('.stat-burned', number_format($tdee, 0, '.', ' '));
        self::assertSelectorTextContains('.stat-left', sprintf('Left %s of ~%s kcal', number_format($tdee - 200, 0, '.', ' '), number_format($tdee, 0, '.', ' ')));
        self::assertSelectorNotExists('.target-missing');
    }

    public function testFormulaTargetSaysHowLongUntilTheAdaptiveEstimate(): void
    {
        $this->completeProfile();
        $this->em()->persist(new WeightEntry($this->em()->find(User::class, $this->user->getId()), $this->user->today()->modify('-4 days'), 80));
        $this->em()->flush();

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.target-formula', 'formula estimate');
        self::assertSelectorTextContains('.adaptive-progress', 'Keep logging your weight: in 11 more days');
    }

    public function testAdaptiveTargetFromOwnDataReplacesTheFormula(): void
    {
        $this->completeProfile();
        $user = $this->em()->find(User::class, $this->user->getId());
        $today = $user->today();
        for ($day = 28; $day >= 1; --$day) {
            $date = $today->modify("-$day days");
            $this->em()->persist(new WeightEntry($user, $date, 80));
            $this->storeEntry($user, 'day '.$day, $date->setTime(12, 0), [new EstimatedItem('Food', 1000, 2300, 100, 250, 90)]);
        }
        $this->em()->flush();

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.target-adaptive .stat-burned', '2 300');
        self::assertSelectorTextContains('.target-adaptive .stat-left', 'Left 2 300 of ~2 300 kcal');
        self::assertSelectorTextContains('.target-adaptive', 'Your body burns ~2 300 kcal/day without workouts, calculated from your last 27 days: you ate ~2 300 kcal/day and your weight trend stayed the same');
    }

    public function testAdaptiveTargetWorksWithoutProfile(): void
    {
        $user = $this->em()->find(User::class, $this->user->getId());
        $today = $user->today();
        for ($day = 20; $day >= 1; --$day) {
            $date = $today->modify("-$day days");
            $this->em()->persist(new WeightEntry($user, $date, 90 - 0.1 * (20 - $day)));
            $this->storeEntry($user, 'day '.$day, $date->setTime(12, 0), [new EstimatedItem('Food', 1000, 2000, 100, 200, 80)]);
        }
        $this->em()->flush();

        $this->client->request('GET', '/');

        self::assertSelectorExists('.target-adaptive');
        self::assertSelectorTextContains('.target-adaptive', 'weight trend went down');
        self::assertSelectorNotExists('.target-missing');
    }

    public function testWeeklyGoalLowersTheTarget(): void
    {
        $this->completeProfile();
        $user = $this->em()->find(User::class, $this->user->getId());
        $user->setWeeklyGoalKg(-0.5);
        $this->em()->persist(new WeightEntry($user, $user->today(), 80));
        $this->em()->flush();

        $this->client->request('GET', '/');

        $tdee = round(round(10 * 80 + 6.25 * 180 - 5 * $user->getAge() + 5) * 1.55);
        self::assertSelectorTextContains('.stat-left', sprintf('of ~%s kcal', number_format($tdee - 550, 0, '.', ' ')));
        self::assertSelectorTextContains('.target .goal', 'Goal: lose 0.5 kg/week, so ~550 kcal/day below what you burn.');
        self::assertSelectorTextNotContains('.target .goal', 'Limited to');
    }

    public function testTargetIsLimitedToSafeMinimum(): void
    {
        $user = $this->em()->find(User::class, $this->user->getId());
        $user->setSex(Sex::Female)->setBirthDate(new \DateTimeImmutable('-60 years'))->setHeightCm(155)
            ->setActivityLevel(ActivityLevel::Sedentary)->setWeeklyGoalKg(-1.0);
        $this->em()->persist(new WeightEntry($user, $user->today(), 55));
        $this->em()->flush();

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.stat-left', 'of ~1 200 kcal');
        self::assertSelectorTextContains('.target .goal', 'Limited to 1 200 kcal/day');
    }

    public function testWithoutGoalSuggestsSettingOne(): void
    {
        $this->completeProfile();
        $this->em()->persist(new WeightEntry($this->em()->find(User::class, $this->user->getId()), $this->user->today(), 80));
        $this->em()->flush();

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.target .goal', 'Goal: keep your weight. Set a goal');
    }

    public function testDashboardShowsCaloriesOverTarget(): void
    {
        $this->completeProfile();
        $this->em()->persist(new WeightEntry($this->user, $this->user->today(), 80));
        foreach (range(1, 15) as $i) {
            $this->storeEntry($this->user, 'meal '.$i, new \DateTimeImmutable()); // 15 × 200 = 3000 kcal
        }

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.stat-left', 'Over 241');
        self::assertSelectorExists('.stat-left strong.over');
    }

    public function testEntriesFromTheRandomProviderAreMarkedAsFake(): void
    {
        $this->estimator()->willReturn(new MealEstimate([new EstimatedItem('Rice', 100, 130, 3, 28, 0)], 'random'));

        $this->client->request('GET', '/meals/new');
        $this->client->submitForm('Log meal', ['description' => 'rice']);
        $this->client->request('GET', '/');

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

        self::assertResponseRedirects('/?log=food');
        self::assertSame(0, $this->em()->getRepository(MealEntry::class)->count([]));
    }

    public function testDeleteUsesTwoClickConfirmationInsteadOfAPopup(): void
    {
        $this->storeEntry($this->user, 'x', new \DateTimeImmutable());

        $crawler = $this->client->request('GET', '/');

        $form = $crawler->filter('form[action$="/delete"]');
        self::assertSame('confirm-delete', $form->attr('data-controller'));
        self::assertNull($form->attr('onsubmit'), 'no browser confirm() popup');
        self::assertSame('confirm-delete#click', $form->filter('button')->attr('data-action'));
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

    /** @param list<EstimatedItem>|null $items */
    private function storeEntry(User $user, string $text, \DateTimeImmutable $eatenAt, ?array $items = null): MealEntry
    {
        $entry = new MealEntry($this->em()->find(User::class, $user->getId()), $text, $eatenAt);
        $entry->applyEstimate(new MealEstimate($items ?? [new EstimatedItem('Something', 100, 200, 10, 20, 5)], 'fake'), new \DateTimeImmutable());
        $this->em()->persist($entry);
        $this->em()->flush();

        return $entry;
    }

    private function storeFailedEntry(\DateTimeImmutable $lastAttemptAt, ?User $user = null): MealEntry
    {
        // Re-fetch: the service resetter clears the entity manager between requests.
        $entry = new MealEntry($user ?? $this->em()->find(User::class, $this->user->getId()), 'mystery meal', new \DateTimeImmutable());
        $entry->estimationFailed('Provider is down', $lastAttemptAt, giveUp: true);
        $this->em()->persist($entry);
        $this->em()->flush();

        return $entry;
    }

    private function asyncTransport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

}
