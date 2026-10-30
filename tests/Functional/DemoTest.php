<?php

namespace App\Tests\Functional;

use App\Demo\DemoAccount;
use App\Entity\MealEntry;
use App\Entity\User;
use App\Entity\WeightEntry;
use App\EventSubscriber\DemoReadOnlySubscriber;
use App\Review\FoodReviewer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class DemoTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // keep the fake reviewer and the mocked clock across requests
        static::mockTime('2026-10-30 11:00 UTC'); // 13:00 in Riga
    }

    public function testTheLandingPageAdvertisesTheDemo(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('.demo-story h2', 'Alex lost');
        self::assertSelectorTextContains('.demo-story h2', 'kg in 13 weeks');
        self::assertCount(2, $crawler->filter('form.demo-form[action="/demo"][method=post]'), 'in the hero and the demo section');
        self::assertSame('Try the demo', $crawler->filter('.hero form.demo-form button')->text());
        self::assertSame('Explore the demo', $crawler->filter('.demo-story form.demo-form button')->text());
        self::assertCount(1, $crawler->filter('.demo-chart svg polyline.weight-line'));
        self::assertCount(13, $crawler->filter('.demo-chart rect.kcal-bar'));
        self::assertNull($this->demoUser(), 'drawn from the plan; the account is only made when someone opens it');
    }

    public function testTryingTheDemoLogsIntoThreeMonthsOfData(): void
    {
        $this->client->request('POST', '/demo');
        self::assertResponseRedirects('/');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.demo-banner', "You're exploring Alex's last 3 months");
        self::assertSame('/demo/sign-up', $crawler->filter('.demo-banner a')->attr('href'));
        self::assertSelectorTextContains('.topbar .user-email', 'Demo');
        self::assertSelectorNotExists('.verify-banner');
        self::assertSelectorTextContains('.overview', 'calculated from your last', 'the target comes from the logged data');

        $demo = $this->demoUser();
        self::assertTrue($demo->isDemo());
        $em = $this->em();
        $meals = $em->getRepository(MealEntry::class)->findBy(['user' => $demo]);
        self::assertGreaterThan(400, count($meals));
        $weights = $em->getRepository(WeightEntry::class)->findBy(['user' => $demo], ['date' => 'ASC']);
        self::assertSame('2026-08-02', $weights[0]->getDate()->format('Y-m-d'));
        self::assertSame('2026-10-30', end($weights)->getDate()->format('Y-m-d'));
        self::assertGreaterThan(4.5, $weights[0]->getWeightKg() - end($weights)->getWeightKg());
    }

    public function testTheDataIsRebuiltUpToNowOnlyWhenItIsOld(): void
    {
        $this->client->request('POST', '/demo');
        $firstMeal = $this->firstMealId();

        static::mockTime('2026-10-30 11:40 UTC');
        $this->client->request('POST', '/demo');
        self::assertSame($firstMeal, $this->firstMealId(), 'fresh enough: kept');

        static::mockTime('2026-10-30 12:10 UTC');
        $this->client->request('POST', '/demo');
        self::assertNotSame($firstMeal, $this->firstMealId(), 'an hour later: rebuilt');

        static::mockTime('2026-11-02 08:00 UTC');
        $this->client->request('POST', '/demo');
        $weights = $this->em()->getRepository(WeightEntry::class)->findBy(['user' => $this->demoUser()], ['date' => 'DESC'], 1);
        self::assertSame('2026-11-02', $weights[0]->getDate()->format('Y-m-d'), 'always ends today');
    }

    public function testNothingCanBeChangedInTheDemo(): void
    {
        $this->client->request('POST', '/demo');
        $this->client->followRedirect();
        $meals = $this->mealCount();

        $this->client->request('POST', '/meals', ['description' => '2 eggs'], server: ['HTTP_REFERER' => 'http://localhost/day/2026-10-20']);
        self::assertResponseRedirects('/day/2026-10-20', 303, 'back where they were');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', DemoReadOnlySubscriber::MESSAGE);
        self::assertSame($meals, $this->mealCount());

        $this->client->request('POST', '/meals', ['description' => '2 eggs'], server: ['HTTP_TURBO_FRAME' => 'entry-panel', 'HTTP_REFERER' => 'http://localhost/']);
        self::assertResponseRedirects('/frame-exit?to=/', 303, 'out of the dialog');

        $this->client->request('POST', '/profile', server: ['HTTP_REFERER' => 'https://evil.example.com/']);
        self::assertResponseRedirects('/', 303, 'only back to this site');
        self::assertSame($meals, $this->mealCount());
    }

    public function testTheWeeklyReviewIsWrittenWithoutTheAi(): void
    {
        $this->client->request('POST', '/demo');
        $this->client->request('GET', '/week/food-review?from=2026-10-17&to=2026-10-23');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'kcal a day on the 7 logged days');
        self::assertSame([], static::getContainer()->get(FoodReviewer::class)->received, 'the AI was not asked');
    }

    public function testSignUpLeavesTheDemo(): void
    {
        $this->client->request('POST', '/demo');
        $this->client->request('GET', '/demo/sign-up');
        self::assertResponseRedirects('/register');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful('the registration form, not the demo');
        $this->client->request('GET', '/');
        self::assertSelectorExists('.hero', 'logged out: the landing page');
    }

    public function testSomeoneLoggedInStaysThemselves(): void
    {
        $user = (new User())->setEmail('me@example.com')->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();
        $this->client->loginUser($user);

        $this->client->request('POST', '/demo');
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorNotExists('.demo-banner');
        self::assertNull($this->demoUser());
    }

    private function demoUser(): ?User
    {
        $this->em()->clear();

        return $this->em()->getRepository(User::class)->findOneBy(['email' => DemoAccount::EMAIL]);
    }

    private function firstMealId(): int
    {
        $meals = $this->em()->getRepository(MealEntry::class)->findBy(['user' => $this->demoUser()], ['id' => 'ASC'], 1);

        return $meals[0]->getId();
    }

    private function mealCount(): int
    {
        return $this->em()->getRepository(MealEntry::class)->count(['user' => $this->demoUser()]);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
