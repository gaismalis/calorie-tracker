<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\WeightEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WeightLogTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = $this->createUser('me@example.com');
        $this->client->loginUser($this->user);
    }

    public function testLoggingWeightForToday(): void
    {
        $crawler = $this->client->request('GET', '/weight');
        self::assertSame($this->localToday(), $crawler->filter('#weight_entry_form_date')->attr('value'), 'date defaults to today');

        $this->client->submitForm('Save weight', ['weight_entry_form[weightKg]' => '82.46']);

        self::assertResponseRedirects('/weight');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Saved 82.5 kg');
        self::assertSelectorTextContains('.weigh-ins', '82.5 kg');

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame($this->localToday(), $entries[0]->getDate()->format('Y-m-d'));
        self::assertSame(82.5, $entries[0]->getWeightKg(), 'rounded to 0.1 kg');
    }

    public function testLoggingSameDayAgainReplacesTheValue(): void
    {
        $this->client->request('GET', '/weight');
        $this->client->submitForm('Save weight', ['weight_entry_form[weightKg]' => '80', 'weight_entry_form[date]' => '2026-09-01']);
        $this->client->followRedirect();
        $this->client->submitForm('Save weight', ['weight_entry_form[weightKg]' => '79.4', 'weight_entry_form[date]' => '2026-09-01']);

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame(79.4, $entries[0]->getWeightKg());
    }

    public function testFormPrefillsLastWeight(): void
    {
        $this->storeWeight($this->user, '2026-09-01', 81.3);

        $crawler = $this->client->request('GET', '/weight');

        self::assertSame('81.3', $crawler->filter('#weight_entry_form_weightKg')->attr('value'));
    }

    public function testHistoryIsGroupedByWeekNewestFirst(): void
    {
        $this->storeWeight($this->user, '2026-08-31', 80.0); // Monday
        $this->storeWeight($this->user, '2026-09-02', 81.0);
        $this->storeWeight($this->user, '2026-09-08', 80.2); // the next week
        $this->storeWeight($this->user, '2026-09-10', 79.6);

        $crawler = $this->client->request('GET', '/weight');

        $weeks = $crawler->filter('.weight-week > summary')->each(fn ($summary) => [
            $summary->filter('.week-dates')->text(), $summary->filter('.week-avg strong')->text(), $summary->filter('.week-change')->text(),
        ]);
        self::assertSame([['7 – 13 Sep', '79.9', '−0.6'], ['31 Aug – 6 Sep', '80.5', '']], $weeks);
        self::assertStringContainsString('away', $crawler->filter('.weight-week .week-change')->first()->attr('class'), 'the default goal is to keep the weight: 0.6 kg down is off course');
    }

    public function testTheLatestWeekIsOpenWithItsWeighIns(): void
    {
        $this->storeWeight($this->user, '2026-09-08', 80.2);
        $this->storeWeight($this->user, '2026-09-10', 79.6);
        $this->storeWeight($this->user, '2026-09-01', 80.0);

        $crawler = $this->client->request('GET', '/weight');

        self::assertNotNull($crawler->filter('.weight-week')->first()->attr('open'));
        self::assertNull($crawler->filter('.weight-week')->last()->attr('open'));
        self::assertSame(['Thu, 10 Sep 79.6 kg', 'Tue, 8 Sep 80.2 kg'], $crawler->filter('.weight-week')->first()->filter('.weigh-ins li')->each(
            fn ($li) => $li->filter('.day')->text().' '.$li->filter('.kg')->text(),
        ));
    }

    public function testTheOverviewShowsTheTrendTheChangeAndThePaceAgainstTheGoal(): void
    {
        $today = $this->user->today();
        for ($daysAgo = 30; $daysAgo >= 0; --$daysAgo) {
            $this->storeWeight($this->user, $today->modify("-$daysAgo days")->format('Y-m-d'), 0 === $daysAgo ? 80.4 : 80.0);
        }

        $this->client->request('GET', '/weight');

        self::assertSelectorTextContains('.weight-now .value strong', '80.0', 'the trend: one heavier morning barely moves it');
        self::assertSelectorTextContains('.weight-now', 'last weigh-in 80.4 kg today');
        $stats = $this->client->getCrawler()->filter('.weight-stats > div')->each(fn ($div) => $div->text());
        self::assertSame(['Since '.$today->modify('-30 days')->format('j M').' 0.0 kg', 'Last 4 weeks +0.01 kg/week', 'Goal keep weight On track'], $stats);
    }

    public function testWithoutWeighInsTheOverviewInvitesTheFirstOne(): void
    {
        $this->client->request('GET', '/weight');

        self::assertSelectorTextContains('.weight-summary', 'Log your first weigh-in below');
        self::assertSelectorNotExists('.weight-stats');
        self::assertSelectorTextContains('body', 'No weight logged yet.');
    }

    public function testChartShowsWeighInsAndTrendForTheChosenRange(): void
    {
        $today = $this->user->today();
        foreach ([40, 20, 10, 0] as $daysAgo) {
            $this->storeWeight($this->user, $today->modify("-$daysAgo days")->format('Y-m-d'), 80 - $daysAgo / 20);
        }

        $crawler = $this->client->request('GET', '/weight');
        self::assertCount(4, $crawler->filter('.weight-chart.chart-wide svg[role=img] circle.weigh-in'), '90 days by default');
        self::assertCount(4, $crawler->filter('.weight-chart.chart-compact svg[role=img] circle.weigh-in'), 'phone version shows the same');
        self::assertSelectorExists('.weight-chart path.trend-line');
        self::assertSelectorTextContains('.weight-chart .end-label', 'kg');
        self::assertSelectorTextContains('.range a[aria-current]', '90 days');
        $days = json_decode($crawler->filter('.weight-chart.chart-wide')->attr('data-chart-hover-days-value'), true);
        self::assertSame(['series' => 'weigh-in', 'value' => '80.0 kg', 'name' => 'Weigh-in'], end($days)['rows'][0]);

        $crawler = $this->client->request('GET', '/weight?range=30');
        self::assertCount(3, $crawler->filter('.weight-chart.chart-wide svg[role=img] circle.weigh-in'));
        self::assertSelectorTextContains('.range a[aria-current]', '30 days');
    }

    public function testChartIsAlwaysShownEvenWithoutWeighIns(): void
    {
        $crawler = $this->client->request('GET', '/weight');

        self::assertSelectorExists('.weight-chart svg[role=img]');
        self::assertSelectorTextContains('.weight-chart.chart-wide .empty-label', 'No weigh-ins in this period yet');
        self::assertSame(2, $crawler->filter('.weight-chart.chart-compact .empty-label tspan')->count(), 'split into two lines to fit a phone');
        self::assertCount(0, $crawler->filter('.weight-chart svg[role=img] circle.weigh-in'));
    }

    public function testChartWithASingleWeighInAndUnknownRangeFallsBackTo90Days(): void
    {
        $this->storeWeight($this->user, $this->user->today()->format('Y-m-d'), 80.0);

        $crawler = $this->client->request('GET', '/weight?range=7');

        self::assertCount(1, $crawler->filter('.weight-chart.chart-wide svg[role=img] circle.weigh-in'));
        self::assertSelectorNotExists('.weight-chart .empty-label');
        self::assertSelectorTextContains('.range a[aria-current]', '90 days');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidInput(): iterable
    {
        yield 'too low' => ['10', 'today', 'Weight must be between 20 and 400 kg'];
        yield 'too high' => ['500', 'today', 'Weight must be between 20 and 400 kg'];
        yield 'future date' => ['80', '+2 days', "You can't log weight for a future date"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInput')]
    public function testInvalidInputIsRejected(string $weight, string $relativeDate, string $error): void
    {
        $date = (new \DateTimeImmutable($relativeDate, $this->user->getDateTimeZone()))->format('Y-m-d');

        $this->client->request('GET', '/weight');
        $this->client->submitForm('Save weight', ['weight_entry_form[weightKg]' => $weight, 'weight_entry_form[date]' => $date]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name=weight_entry_form]', $error);
        self::assertSame([], $this->entries());
    }

    public function testUsersOnlySeeTheirOwnWeights(): void
    {
        $this->storeWeight($this->createUser('other@example.com'), '2026-09-01', 123.4);

        $this->client->request('GET', '/weight');

        self::assertSelectorTextContains('main', 'No weight logged yet');
    }

    public function testDeletingOwnEntry(): void
    {
        $this->storeWeight($this->user, '2026-09-01', 80.0);

        $this->client->request('GET', '/weight');
        $this->client->submitForm('✕');

        self::assertResponseRedirects('/weight');
        self::assertSame([], $this->entries());
    }

    public function testDeleteUsesTwoClickConfirmationInsteadOfAPopup(): void
    {
        $this->storeWeight($this->user, '2026-09-01', 80.0);

        $crawler = $this->client->request('GET', '/weight');

        $form = $crawler->filter('form[action$="/delete"]');
        self::assertSame('confirm-delete', $form->attr('data-controller'));
        self::assertNull($form->attr('onsubmit'), 'no browser confirm() popup');
    }

    public function testCannotDeleteSomeoneElsesEntry(): void
    {
        $entry = $this->storeWeight($this->createUser('other@example.com'), '2026-09-01', 80.0);

        $this->client->request('POST', '/weight/'.$entry->getId().'/delete', ['_token' => 'x']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRequiresValidCsrfToken(): void
    {
        $entry = $this->storeWeight($this->user, '2026-09-01', 80.0);

        $this->client->request('POST', '/weight/'.$entry->getId().'/delete', ['_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->entries());
    }

    private function localToday(): string
    {
        return $this->user->today()->format('Y-m-d');
    }

    /** @return list<WeightEntry> */
    private function entries(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(WeightEntry::class)->findBy(['user' => $this->user->getId()]);
    }

    private function storeWeight(User $user, string $date, float $kg): WeightEntry
    {
        $entry = new WeightEntry($user, new \DateTimeImmutable($date), $kg);
        $this->em()->persist($entry);
        $this->em()->flush();

        return $entry;
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
