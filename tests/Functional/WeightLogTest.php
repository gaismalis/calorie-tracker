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
        self::assertSelectorTextContains('table.weights', '82.5');

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

    public function testHistoryIsNewestFirstWithChange(): void
    {
        $this->storeWeight($this->user, '2026-09-01', 80.0);
        $this->storeWeight($this->user, '2026-09-03', 80.6);
        $this->storeWeight($this->user, '2026-09-02', 81.0);

        $crawler = $this->client->request('GET', '/weight');

        $rows = $crawler->filter('table.weights tbody tr')->each(fn ($tr) => [
            $tr->filter('td')->eq(1)->text(), $tr->filter('td')->eq(2)->text(),
        ]);
        self::assertSame([['80.6', '-0.4'], ['81.0', '+1.0'], ['80.0', '']], $rows);
    }

    public function testHistoryShowsSmoothedTrend(): void
    {
        $this->storeWeight($this->user, '2026-09-01', 80.0);
        $this->storeWeight($this->user, '2026-09-02', 81.0);

        $crawler = $this->client->request('GET', '/weight');

        $trend = $crawler->filter('table.weights tbody td.trend')->each(fn ($td) => $td->text());
        self::assertSame(['80.1', '80.0'], $trend, 'a 1 kg jump moves the trend only 0.1 kg');
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
        self::assertSelectorTextContains('form', $error);
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
