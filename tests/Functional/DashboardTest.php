<?php

namespace App\Tests\Functional;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Nutrition\EstimatedItem;
use App\Nutrition\MealEstimate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = (new User())->setEmail('me@example.com')->setPassword('x');
        $this->em()->persist($this->user);
        $this->em()->flush();
        $this->client->loginUser($this->user);
    }

    public function testLayoutOrder(): void
    {
        $crawler = $this->client->request('GET', '/');

        $sections = $crawler->filter('main > .card, main > section, main > details')->each(fn ($n) => $n->attr('class'));
        self::assertStringContainsString('overview', $sections[0]);
        self::assertSame('Last 14 days', $crawler->filter('main > section.card')->eq(1)->filter('h2')->text());
        self::assertStringContainsString('log', end($sections));
    }

    public function testLogIsCollapsedByDefaultWithFoodTabFirst(): void
    {
        $this->storeMeal('lunch');

        $crawler = $this->client->request('GET', '/');

        self::assertNull($crawler->filter('details.log')->attr('open'));
        self::assertSelectorTextContains('details.log summary', '1 meal · 0 activities');
        self::assertSame('true', $crawler->filter('#tab-food')->attr('aria-selected'));
        self::assertNotNull($crawler->filter('#panel-exercise')->attr('hidden'));
        self::assertSelectorTextContains('#panel-food .entry', 'lunch');
        self::assertSelectorTextContains('#panel-exercise', 'No exercise logged yet today');
    }

    public function testComingBackFromTheLogKeepsItOpenOnThatTabAndScrollsToIt(): void
    {
        $this->storeMeal('lunch');

        $crawler = $this->client->request('GET', '/?log=exercise');
        self::assertNotNull($crawler->filter('details.log')->attr('open'));
        self::assertSame('true', $crawler->filter('#tab-exercise')->attr('aria-selected'));
        self::assertNull($crawler->filter('#panel-exercise')->attr('hidden'));
        self::assertNotNull($crawler->filter('#panel-food')->attr('hidden'));
        self::assertSame('scroll-into-view', $crawler->filter('details.log')->attr('data-controller'));

        $crawler = $this->client->request('GET', '/?log=food');
        self::assertSame('true', $crawler->filter('#tab-food')->attr('aria-selected'));

        $crawler = $this->client->request('GET', '/?log=nonsense');
        self::assertNull($crawler->filter('details.log')->attr('open'), 'unknown values are ignored');
        self::assertNull($crawler->filter('details.log')->attr('data-controller'));
    }

    public function testDeletingFromTheLogReturnsWithItOpen(): void
    {
        $this->storeMeal('lunch');
        $this->storeMeal('dinner');

        $this->client->request('GET', '/');
        $this->client->submit($this->client->getCrawler()->filter('#panel-food form[action$="/delete"]')->first()->form());
        self::assertResponseRedirects('/?log=food');

        $crawler = $this->client->followRedirect();
        self::assertNotNull($crawler->filter('details.log')->attr('open'));
        self::assertCount(1, $crawler->filter('#panel-food .entry'));
    }

    public function testAddButtonOffersMealAndExerciseDialogs(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorExists('.add-entry button.fab[aria-label="Add a meal or exercise"]');
        self::assertSame(['🍽 Meal', '🏃 Exercise'], $crawler->filter('.add-menu button')->each(fn ($b) => $b->text()));
        self::assertSame('/meals', $crawler->filter('dialog[data-kind=meal] form')->attr('action'));
        self::assertSame('/exercises', $crawler->filter('dialog[data-kind=exercise] form')->attr('action'));
    }

    public function testChartShowsEatenDaysAndNoBurnedLineWithoutEstimate(): void
    {
        $this->storeMeal('dinner', $this->user->today()->modify('-3 days')->setTime(19, 0));
        $this->storeMeal('lunch');

        $crawler = $this->client->request('GET', '/');

        self::assertCount(2, $crawler->filter('.energy-chart svg[role=img] circle.eaten-dot'));
        self::assertCount(0, $crawler->filter('.energy-chart svg[role=img] path.burned-line'));
        self::assertSelectorTextContains('.chart-note', '"Burned" appears once');
        $days = json_decode($crawler->filter('.energy-chart')->attr('data-chart-hover-days-value'), true);
        self::assertCount(14, $days);
        self::assertStringEndsWith('(so far)', end($days)['label']);
    }

    private function storeMeal(string $text, ?\DateTimeImmutable $at = null): void
    {
        $entry = new MealEntry($this->user, $text, $at ?? new \DateTimeImmutable());
        $entry->applyEstimate(new MealEstimate([new EstimatedItem('Food', 300, 600, 30, 60, 20)], 'test'), new \DateTimeImmutable());
        $this->em()->persist($entry);
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
