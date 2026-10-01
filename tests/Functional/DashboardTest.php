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

        $cards = $crawler->filter('main .card')->each(fn ($n) => $n->attr('class'));
        self::assertCount(3, $cards);
        self::assertStringContainsString('overview', $cards[0]);
        self::assertSame('Last 14 days', $crawler->filter('main .card')->eq(1)->filter('h2')->text());
        self::assertStringContainsString('log', $cards[2]);
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
        self::assertSame(['🍽 Meal', '🏃 Exercise'], $crawler->filter('.add-menu a')->each(fn ($a) => $a->text()));
        self::assertSame(['/meals/new', '/exercises/new'], $crawler->filter('.add-menu a')->each(fn ($a) => $a->attr('href')));
        self::assertSelectorExists('dialog.entry-dialog turbo-frame#entry-panel');
    }

    public function testInsideTheDialogResultsLeaveTheFrameAsAWholePageVisit(): void
    {
        $this->storeMeal('lunch');
        $this->client->disableReboot();
        $meal = static::getContainer()->get(EntityManagerInterface::class)->getRepository(MealEntry::class)->findOneBy([]);

        // Saving the review from inside the dialog (Turbo sends the frame id).
        $this->client->request('GET', '/meals/'.$meal->getId().'/edit', server: ['HTTP_TURBO_FRAME' => 'entry-panel']);
        $form = $this->client->getCrawler()->selectButton('Save')->form();
        $this->client->submit($form, [], ['HTTP_TURBO_FRAME' => 'entry-panel']);

        self::assertResponseRedirects('/frame-exit?to=/?log%3Dfood');
        $crawler = $this->client->followRedirect();
        self::assertSame('/?log=food', $crawler->filter('turbo-frame#entry-panel [data-controller=frame-exit]')->attr('data-frame-exit-url-value'));
    }

    public function testFrameExitOnlyGoesToThisSite(): void
    {
        foreach (['//evil.example' => '/', 'https://evil.example' => '/', '/\\evil' => '/', '/day/2026-09-01' => '/day/2026-09-01'] as $to => $expected) {
            $crawler = $this->client->request('GET', '/frame-exit?to='.urlencode($to));
            self::assertSame($expected, $crawler->filter('[data-controller=frame-exit]')->attr('data-frame-exit-url-value'), $to);
        }
    }

    public function testWithoutTheDialogSavingRedirectsNormally(): void
    {
        $this->storeMeal('lunch');
        $meal = static::getContainer()->get(EntityManagerInterface::class)->getRepository(MealEntry::class)->findOneBy([]);

        $this->client->request('GET', '/meals/'.$meal->getId().'/edit');
        $this->client->submitForm('Save');

        self::assertResponseRedirects('/?log=food');
    }

    public function testWideScreensGetButtonsThatOpenTheSameDialog(): void
    {
        $crawler = $this->client->request('GET', '/');

        $buttons = $crawler->filter('.add-bar a');
        self::assertSame(['＋ Meal', '＋ Exercise'], $buttons->each(fn ($a) => $a->text()));
        self::assertSame(['/meals/new', '/exercises/new'], $buttons->each(fn ($a) => $a->attr('href')));
        self::assertSame(['entry-dialog#open', 'entry-dialog#open'], $buttons->each(fn ($a) => $a->attr('data-action')));
    }

    public function testAdjustLinksOpenInTheDialog(): void
    {
        $this->storeMeal('lunch');

        $crawler = $this->client->request('GET', '/');

        $adjust = $crawler->filter('#panel-food a.adjust-link');
        self::assertSame('entry-dialog#open:prevent', $adjust->attr('data-action'));
        self::assertMatchesRegularExpression('#^/meals/\d+/edit$#', $adjust->attr('href'));
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
