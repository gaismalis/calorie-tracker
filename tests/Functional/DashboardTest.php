<?php

namespace App\Tests\Functional;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Entity\WeightEntry;
use App\Nutrition\EstimatedItem;
use App\Nutrition\FoodCategory;
use App\Nutrition\MealEstimate;
use App\Profile\ActivityLevel;
use App\Profile\Sex;
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
        self::assertCount(4, $cards);
        self::assertStringContainsString('overview', $cards[0]);
        self::assertSame('Eaten vs burned', $crawler->filter('main .card')->eq(1)->filter('h2')->text());
        self::assertStringContainsString('week', $cards[2]);
        self::assertStringContainsString('log', $cards[3]);
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

        self::assertSelectorExists('.add-entry button.fab[aria-label="Add a meal, exercise or weight"]');
        self::assertSame(['🍽 Meal', '🏃 Exercise', '⚖️ Weight'], $crawler->filter('.add-menu a')->each(fn ($a) => $a->text()));
        self::assertSame(['/meals/new', '/exercises/new', '/weight/quick'], $crawler->filter('.add-menu a')->each(fn ($a) => $a->attr('href')));
        self::assertSelectorExists('dialog.entry-dialog turbo-frame#entry-panel');
        // Closing by clicking outside also needs the press to start outside (see entry_dialog_controller.js),
        // so dragging a slider past the dialog's edge doesn't close it.
        self::assertStringContainsString('pointerdown->entry-dialog#pressed', $crawler->filter('dialog.entry-dialog')->attr('data-action'));
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
        self::assertSame(['＋ Meal', '＋ Exercise', '＋ Weight'], $buttons->each(fn ($a) => $a->text()));
        self::assertSame(['/meals/new', '/exercises/new', '/weight/quick'], $buttons->each(fn ($a) => $a->attr('href')));
        self::assertSame(['entry-dialog#open', 'entry-dialog#open', 'entry-dialog#open'], $buttons->each(fn ($a) => $a->attr('data-action')));
    }

    public function testAdjustLinksOpenInTheDialog(): void
    {
        $this->storeMeal('lunch');

        $crawler = $this->client->request('GET', '/');

        $adjust = $crawler->filter('#panel-food a.adjust-link');
        self::assertSame('entry-dialog#open:prevent', $adjust->attr('data-action'));
        self::assertMatchesRegularExpression('#^/meals/\d+/edit$#', $adjust->attr('href'));
    }

    public function testChartShowsPlainEatenBarsBeforeThereIsAnEstimate(): void
    {
        $this->storeMeal('dinner', $this->user->today()->modify('-3 days')->setTime(19, 0));
        $this->storeMeal('lunch');

        $crawler = $this->client->request('GET', '/');

        self::assertCount(2, $crawler->filter('.energy-chart.chart-wide svg[role=img] rect.bar-unknown'));
        self::assertCount(1, $crawler->filter('.energy-chart.chart-wide svg[role=img] rect.bar-today'));
        self::assertCount(0, $crawler->filter('.energy-chart svg[role=img] .burned-mark'));
        self::assertSelectorTextContains('.energy-chart .legend', 'Eaten');
        self::assertSelectorTextContains('.chart-note', '"Burned" appears once');
        $days = json_decode($crawler->filter('.energy-chart.chart-wide')->attr('data-chart-hover-days-value'), true);
        self::assertCount(7, $days, 'a week by default');
        self::assertStringEndsWith('(so far)', end($days)['label']);
    }

    public function testChartComesInAWideAndACompactPhoneVersion(): void
    {
        $this->storeMeal('lunch');

        $crawler = $this->client->request('GET', '/');

        self::assertSame('0 0 680 220', $crawler->filter('.energy-chart.chart-wide svg[role=img]')->attr('viewbox'));
        self::assertSame('0 0 340 220', $crawler->filter('.energy-chart.chart-compact svg[role=img]')->attr('viewbox'));
        self::assertCount(1, $crawler->filter('.energy-chart.chart-compact svg[role=img] rect.bar-unknown'));
        $days = json_decode($crawler->filter('.energy-chart.chart-compact')->attr('data-chart-hover-days-value'), true);
        self::assertCount(7, $days, 'hover works on the compact chart too');
    }

    public function testChartComparesEachDayWithWhatWasBurned(): void
    {
        // Burns ~2100/day; the goal (−1 kg/week ≈ −1100/day) must not move the comparison.
        $this->user->setSex(Sex::Male)->setBirthDate(new \DateTimeImmutable('1990-01-01'))->setHeightCm(180)
            ->setActivityLevel(ActivityLevel::Sedentary)->setWeeklyGoalKg(-1.0);
        $this->em()->persist(new WeightEntry($this->user, $this->user->today(), 80.0));
        $this->em()->flush();
        $yesterday = $this->user->today()->modify('-1 day');
        $this->storeMeal('feast', $yesterday->setTime(19, 0), 5000);
        $this->storeMeal('pasta', $yesterday->modify('-1 day')->setTime(12, 0), 1800);
        $this->storeMeal('lunch');

        $crawler = $this->client->request('GET', '/');

        $wide = $crawler->filter('.energy-chart.chart-wide svg[role=img]');
        self::assertCount(1, $wide->filter('rect.bar-over'));
        self::assertCount(2, $wide->filter('rect.bar-under'), 'pasta day: over the target, but less than burned; and today so far');
        self::assertCount(7, $wide->filter('.burned-mark'));
        self::assertMatchesRegularExpression('/^\+\d[\d ]*$/', $wide->filter('.over-label')->text());
        self::assertCount(1, $crawler->filter('.energy-chart.chart-compact .over-label'), 'a week fits labels on phones too');
        $month = $this->client->request('GET', '/?chart=month');
        self::assertCount(0, $month->filter('.energy-chart.chart-compact .over-label'), '30 bars on a phone: no room');
        self::assertSelectorTextContains('.energy-chart .legend', 'Ate less than burned');
        self::assertSelectorTextContains('.chart-note', 'Today is shown faded');
    }

    public function testLogShowsValuesPer100gForEachItem(): void
    {
        $this->storeMeal('lunch'); // 300 g, 600 kcal, P 30, C 60, F 20

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('#panel-food .per100g', 'per 100 g: 200 kcal · P 10 · C 20 · F 6.7');
    }

    public function testChartPeriodButtonsSwitchTheChartAndTheWeeklyOverviewTogether(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertCount(1, $crawler->filter('turbo-frame#period section.energy'));
        self::assertCount(1, $crawler->filter('turbo-frame#period section.week'), 'one frame: they change together');
        $links = $crawler->filter('turbo-frame#period nav.range a');
        self::assertSame(['Week', 'Month', 'All'], $links->each(fn ($a) => $a->text()));
        self::assertSame(['/?chart=week', '/?chart=month', '/?chart=all'], $links->each(fn ($a) => $a->attr('href')));
        self::assertSame('Week', $crawler->filter('nav.range a[aria-current]')->text());
        self::assertSelectorTextContains('.chart-period', 'Last 7 days');
    }

    public function testMonthShowsThirtyDays(): void
    {
        $crawler = $this->client->request('GET', '/?chart=month');

        self::assertSame('Month', $crawler->filter('nav.range a[aria-current]')->text());
        self::assertCount(30, json_decode($crawler->filter('.energy-chart.chart-wide')->attr('data-chart-hover-days-value'), true));
        self::assertSelectorTextContains('.chart-period', 'Last 30 days');
    }

    public function testAllStartsAtTheFirstMealAndUsesWeeksForLongHistories(): void
    {
        $today = $this->user->today();
        $this->storeMeal('first', $today->modify('-20 days')->setTime(12, 0));

        $crawler = $this->client->request('GET', '/?chart=all');
        self::assertCount(21, json_decode($crawler->filter('.energy-chart.chart-wide')->attr('data-chart-hover-days-value'), true), 'a bar per day');
        self::assertSelectorTextContains('.chart-period', 'Since '.$today->modify('-20 days')->format('j M Y'));

        $this->storeMeal('long ago', $today->modify('-200 days')->setTime(12, 0));
        $crawler = $this->client->request('GET', '/?chart=all');
        $bars = json_decode($crawler->filter('.energy-chart.chart-wide')->attr('data-chart-hover-days-value'), true);
        self::assertCount(29, $bars, '201 days → 29 weekly bars');
        self::assertStringContainsString(' – ', end($bars)['label']);
        self::assertSelectorTextContains('.chart-period', 'one bar per week');
    }

    public function testAllWithoutMealsAndUnknownPeriodsShowAWeek(): void
    {
        $crawler = $this->client->request('GET', '/?chart=all');
        self::assertCount(7, json_decode($crawler->filter('.energy-chart.chart-wide')->attr('data-chart-hover-days-value'), true));

        $crawler = $this->client->request('GET', '/?chart=decade');
        self::assertSame('Week', $crawler->filter('nav.range a[aria-current]')->text());
    }

    public function testPeriodButtonsOnAPastDayStayOnThatDay(): void
    {
        $day = $this->user->today()->modify('-3 days')->format('Y-m-d');

        $crawler = $this->client->request('GET', '/day/'.$day.'?chart=month');

        self::assertSame('/day/'.$day.'?chart=week', $crawler->filter('nav.range a')->first()->attr('href'));
        $bars = json_decode($crawler->filter('.energy-chart.chart-wide')->attr('data-chart-hover-days-value'), true);
        self::assertSame((new \DateTimeImmutable($day))->format('D, j M'), end($bars)['label']);
    }

    public function testWeeklySummaryCoversTheChartsWeekButLeavesOutToday(): void
    {
        // Burns ~2100/day (formula); goal: lose 0.5 kg/week.
        $this->user->setSex(Sex::Male)->setBirthDate(new \DateTimeImmutable('1990-01-01'))->setHeightCm(180)
            ->setActivityLevel(ActivityLevel::Sedentary)->setWeeklyGoalKg(-0.5);
        $this->em()->persist(new WeightEntry($this->user, $this->user->today(), 80.0));
        $this->em()->flush();
        $today = $this->user->today();
        foreach ([1, 2, 3, 4, 5] as $daysAgo) {
            $this->storeMeal('food', $today->modify("-$daysAgo days")->setTime(12, 0), 1600);
        }
        $this->storeMeal('today does not count yet', $today->setTime(8, 0), 5000);

        $crawler = $this->client->request('GET', '/');

        $week = $crawler->filter('section.week');
        self::assertSame('Last 7 days', $week->filter('.pager-label')->text(), 'same as the chart');
        self::assertSame('1 600', $week->filter('.week-stats > div')->eq(0)->filter('strong')->text());
        self::assertStringStartsWith('−', $week->filter('.week-balance strong')->text(), 'ate less than burned');
        self::assertStringContainsString('goal lose 0.5 kg/week', $week->filter('.week-pace')->text());
        self::assertStringContainsString("Meals logged on 5 of 6 days (today counts once it's over)", $week->text());
        self::assertStringContainsString('Exercise: none logged', $week->text());
    }

    public function testAlcoholAndSnacksAreShownOnTheChartAndCountedInTheWeek(): void
    {
        $yesterday = $this->user->today()->modify('-1 day')->setTime(20, 0);
        $entry = new MealEntry($this->user, 'pizza, 2 beers and ice cream', $yesterday);
        $entry->applyEstimate(new MealEstimate([
            new EstimatedItem('Pizza', 300, 800, 30, 90, 30),
            new EstimatedItem('Beer', 1000, 430, 5, 36, 0, category: FoodCategory::Alcohol),
            new EstimatedItem('Ice cream', 100, 200, 3, 24, 11, category: FoodCategory::Snack),
        ], 'test'), $yesterday);
        $this->em()->persist($entry);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/');

        self::assertCount(1, $crawler->filter('.energy-chart.chart-wide svg[role=img] rect.bar-alcohol'));
        self::assertCount(1, $crawler->filter('.energy-chart.chart-wide svg[role=img] rect.bar-snack'));
        self::assertSelectorTextContains('.energy-chart .legend', 'Alcohol');
        self::assertSelectorTextContains('.energy-chart .legend', 'Snacks');
        $days = json_decode($crawler->filter('.energy-chart.chart-wide')->attr('data-chart-hover-days-value'), true);
        self::assertContains(['series' => 'alcohol', 'value' => '430 kcal', 'name' => 'of it alcohol'], $days[5]['rows']);
        self::assertContains(['series' => 'snacks', 'value' => '200 kcal', 'name' => 'of it snacks'], $days[5]['rows']);
        $week = $crawler->filter('section.week')->text();
        self::assertStringContainsString('🍷 Alcohol: 430 kcal (30% of what you ate)', $week);
        self::assertStringContainsString('🍫 Snacks: 200 kcal (14% of what you ate)', $week);
    }

    public function testWeeklySummaryOnAPastDayEndsOnThatDay(): void
    {
        $day = $this->user->today()->modify('-10 days');
        $this->storeMeal('food', $day->setTime(12, 0), 2000);

        $crawler = $this->client->request('GET', '/day/'.$day->format('Y-m-d'));

        self::assertSame('/week/food-review?from='.$day->modify('-6 days')->format('Y-m-d').'&to='.$day->format('Y-m-d'), $crawler->filter('section.week turbo-frame#food-review')->attr('src'), 'the day shown is over: all 7 days');
        self::assertStringContainsString('Meals logged on 1 of 7 days, so these averages are rough', $crawler->filter('section.week')->text());
        self::assertStringContainsString('"Burned" appears once', $crawler->filter('section.week')->text(), 'no profile yet');
    }

    public function testChartWeeksAndMonthsCanBePagedBackToTheFirstMeal(): void
    {
        $today = $this->user->today();
        $this->storeMeal('long ago', $today->modify('-40 days')->setTime(12, 0));

        $crawler = $this->client->request('GET', '/');
        $pager = $crawler->filter('section.energy .pager');
        self::assertSame('Last 7 days', $pager->filter('.pager-label')->text());
        $earlier = $today->modify('-7 days')->format('Y-m-d');
        self::assertSame('/?chart=week&chartTo='.$earlier, $pager->filter('a.pager-prev')->attr('href'));
        self::assertCount(0, $pager->filter('a.pager-next'), 'nothing after today');

        $crawler = $this->client->request('GET', '/?chart=week&chartTo='.$earlier);
        $pager = $crawler->filter('section.energy .pager');
        $bars = json_decode($crawler->filter('.energy-chart.chart-wide')->attr('data-chart-hover-days-value'), true);
        self::assertCount(7, $bars);
        self::assertSame((new \DateTimeImmutable($earlier))->format('D, j M'), end($bars)['label']);
        self::assertStringEndsWith((new \DateTimeImmutable($earlier))->format('j M'), $pager->filter('.pager-label')->text());
        self::assertSame('/?chart=week', $pager->filter('a.pager-next')->attr('href'), 'the latest page has no date');

        $monthBack = $today->modify('-30 days')->format('Y-m-d');
        $crawler = $this->client->request('GET', '/?chart=month');
        self::assertSame('/?chart=month&chartTo='.$monthBack, $crawler->filter('section.energy a.pager-prev')->attr('href'));
        $crawler = $this->client->request('GET', '/?chart=month&chartTo='.$monthBack);
        self::assertCount(30, json_decode($crawler->filter('.energy-chart.chart-wide')->attr('data-chart-hover-days-value'), true));
        self::assertCount(0, $crawler->filter('section.energy a.pager-prev'), 'the first meal is on this page');
        self::assertCount(0, $this->client->request('GET', '/?chart=all')->filter('section.energy .pager'), 'all is one page');
    }

    public function testChartPagesNeverGoPastTheShownDayAndIgnoreBadDates(): void
    {
        $day = $this->user->today()->modify('-3 days');
        $this->storeMeal('food', $day->modify('-20 days')->setTime(12, 0));

        $crawler = $this->client->request('GET', '/day/'.$day->format('Y-m-d').'?chart=week&chartTo='.$this->user->today()->format('Y-m-d'));
        self::assertSame('Last 7 days', $crawler->filter('section.energy .pager-label')->text(), 'later than the day shown: its latest page');
        self::assertSame('/day/'.$day->format('Y-m-d').'?chart=week&chartTo='.$day->modify('-7 days')->format('Y-m-d'), $crawler->filter('section.energy a.pager-prev')->attr('href'));

        $crawler = $this->client->request('GET', '/?chart=week&chartTo=2026-02-31');
        self::assertSame('Last 7 days', $crawler->filter('section.energy .pager-label')->text());
    }

    public function testTheWeeklyOverviewShowsTheSameWeekAsTheChart(): void
    {
        $today = $this->user->today();
        $this->storeMeal('old food', $today->modify('-12 days')->setTime(12, 0), 1000);
        $this->storeMeal('recent food', $today->modify('-2 days')->setTime(12, 0), 3000);

        $crawler = $this->client->request('GET', '/');
        $week = $crawler->filter('section.week');
        self::assertSame('Last 7 days', $week->filter('.pager-label')->text());
        self::assertSame('3 000', $week->filter('.week-stats strong')->first()->text());
        $earlier = $today->modify('-7 days')->format('Y-m-d');
        self::assertSame('/?chart=week&chartTo='.$earlier, $week->filter('a.pager-prev')->attr('href'), 'the same link as the chart');
        self::assertSame($crawler->filter('section.energy a.pager-prev')->attr('href'), $week->filter('a.pager-prev')->attr('href'));

        $crawler = $this->client->request('GET', '/?chart=week&chartTo='.$earlier);
        $week = $crawler->filter('section.week');
        self::assertSame($crawler->filter('section.energy .pager-label')->text(), $week->filter('.pager-label')->text(), 'same days as the chart');
        self::assertSame('1 000', $week->filter('.week-stats strong')->first()->text());
        self::assertStringContainsString('Meals logged on 1 of 7 days', $week->text(), 'an earlier week is all 7 days');
        self::assertSame('/week/food-review?from='.$today->modify('-13 days')->format('Y-m-d').'&to='.$earlier, $week->filter('turbo-frame#food-review')->attr('src'), 'the AI review of that week');
        self::assertSame('/?chart=week', $week->filter('a.pager-next')->attr('href'));
        self::assertCount(0, $week->filter('a.pager-prev'), 'nothing logged before');
    }

    public function testInMonthViewTheWeeklyOverviewIsTheLastWeekOfTheChartAndPagesByAWeek(): void
    {
        $today = $this->user->today();
        $this->storeMeal('old food', $today->modify('-40 days')->setTime(12, 0));
        $monthBack = $today->modify('-30 days');

        $crawler = $this->client->request('GET', '/?chart=month&chartTo='.$monthBack->format('Y-m-d'));

        $week = $crawler->filter('section.week');
        self::assertStringEndsWith($monthBack->format('j M'), $week->filter('.pager-label')->text());
        self::assertStringStartsWith($monthBack->modify('-6 days')->format('j'), $week->filter('.pager-label')->text());
        self::assertSame('/?chart=month&chartTo='.$monthBack->modify('-7 days')->format('Y-m-d'), $week->filter('a.pager-prev')->attr('href'), 'a week back, the chart moves with it');
        $all = $this->client->request('GET', '/?chart=all');
        self::assertSame('/?chart=week&chartTo='.$today->modify('-7 days')->format('Y-m-d'), $all->filter('section.week a.pager-prev')->attr('href'), 'from All: to that week');
    }

    public function testEmptyWeek(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('section.week', "Nothing logged in these days yet (today counts once it's over).");
    }

    public function testEmailInTheHeaderCanBeHiddenOnPhones(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.topbar .user-email', $this->user->getEmail());
        self::assertSelectorTextContains('.topbar a.logout', 'Log out');
    }

    private function storeMeal(string $text, ?\DateTimeImmutable $at = null, float $kcal = 600): void
    {
        $entry = new MealEntry($this->user, $text, $at ?? new \DateTimeImmutable());
        $entry->applyEstimate(new MealEstimate([new EstimatedItem('Food', 300, $kcal, 30, 60, 20)], 'test'), new \DateTimeImmutable());
        $this->em()->persist($entry);
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
