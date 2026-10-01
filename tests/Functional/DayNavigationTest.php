<?php

namespace App\Tests\Functional;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Meal\MealStatus;
use App\Nutrition\EstimatedItem;
use App\Nutrition\MealEstimate;
use App\Nutrition\NutritionEstimator;
use App\Tests\Support\FakeNutritionEstimator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DayNavigationTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->user = (new User())->setEmail('me@example.com')->setPassword('x')->setTimezone('Europe/Riga');
        $this->em()->persist($this->user);
        $this->em()->flush();
        $this->client->loginUser($this->user);
        $this->today = $this->user->today();
    }

    public function testPastDayShowsOnlyItsMealsWithTitleAndNavigation(): void
    {
        $yesterday = $this->today->modify('-1 day');
        $this->storeEntry('yesterday lunch', $yesterday->setTime(13, 0));
        $this->storeEntry('today breakfast', $this->today->setTime(0, 30));

        $crawler = $this->client->request('GET', '/day/'.$yesterday->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.day-title', $yesterday->format('l, j F Y'), 'title is the local day, not shifted by UTC');
        self::assertSelectorTextContains('h1', 'What did you eat on '.$yesterday->format('l').'?');
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('yesterday lunch', $text);
        self::assertStringNotContainsString('today breakfast', $text);
        self::assertSame('/day/'.$yesterday->modify('-1 day')->format('Y-m-d'), $crawler->filter('.day-nav a.prev')->attr('href'));
        self::assertSame('/', $crawler->filter('.day-nav a.next')->attr('href'), 'the day after yesterday is "/"');
        self::assertSame($yesterday->format('Y-m-d'), $crawler->filter('.meal-form input[name=date]')->attr('value'));
    }

    public function testTodayHasNoNextDayAndNoDateField(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('.day-title', 'Today');
        self::assertSelectorExists('.day-nav .next.disabled');
        self::assertSame('/day/'.$this->today->modify('-1 day')->format('Y-m-d'), $crawler->filter('.day-nav a.prev')->attr('href'));
        self::assertSelectorNotExists('.meal-form input[name=date]');
    }

    public function testTodayAndFutureDatesRedirectToTheDashboard(): void
    {
        $this->client->request('GET', '/day/'.$this->today->format('Y-m-d'));
        self::assertResponseRedirects('/');

        $this->client->request('GET', '/day/'.$this->today->modify('+3 days')->format('Y-m-d'));
        self::assertResponseRedirects('/');
    }

    public function testInvalidDateIs404(): void
    {
        $this->client->request('GET', '/day/2026-02-30');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLoggingAMealOnAPastDayAtAGivenTime(): void
    {
        $day = $this->today->modify('-3 days');
        $this->estimator()->willFail(); // stays pending → back to that day

        $this->client->request('GET', '/day/'.$day->format('Y-m-d'));
        $this->client->submitForm('Log meal', ['description' => 'pancakes', 'time' => '19:45']);

        self::assertResponseRedirects('/day/'.$day->format('Y-m-d'));
        self::assertSame($day->setTime(19, 45)->getTimestamp(), $this->onlyEntry()->getEatenAt()->getTimestamp());
    }

    public function testPastDayWithoutTimeIsNoon(): void
    {
        $day = $this->today->modify('-1 day');
        $this->estimator()->willFail();

        $this->client->request('GET', '/day/'.$day->format('Y-m-d'));
        $this->client->submitForm('Log meal', ['description' => 'pancakes']);

        self::assertSame($day->setTime(12, 0)->getTimestamp(), $this->onlyEntry()->getEatenAt()->getTimestamp());
    }

    public function testTodayWithTimeAndWithoutTime(): void
    {
        $this->estimator()->willFail();
        $this->client->request('GET', '/');
        $this->client->submitForm('Log meal', ['description' => 'just now']);
        self::assertEqualsWithDelta(time(), $this->onlyEntry()->getEatenAt()->getTimestamp(), 5, 'empty time today = now');
    }

    public function testFutureTimeIsRejected(): void
    {
        // Late in the evening "+2 hours" wraps to tomorrow morning, which is in the past for today.
        if ((int) (new \DateTimeImmutable('now', $this->user->getDateTimeZone()))->format('H') >= 22) {
            self::markTestSkipped('Runs only before 22:00 local time.');
        }

        $this->client->request('POST', '/meals', [
            'description' => 'later',
            'time' => (new \DateTimeImmutable('+2 hours', $this->user->getDateTimeZone()))->format('H:i'),
            '_token' => $this->formToken('/'),
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', "You can't log meals in the future");
        self::assertSame(0, $this->em()->getRepository(MealEntry::class)->count([]));
    }

    public function testInvalidTimeIsRejectedAndUserStaysOnTheDay(): void
    {
        $day = $this->today->modify('-2 days')->format('Y-m-d');

        $this->client->request('POST', '/meals', ['description' => 'x', 'date' => $day, 'time' => '25:99', '_token' => $this->formToken('/day/'.$day)]);

        self::assertResponseRedirects('/day/'.$day);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid time');
    }

    public function testEmptyDescriptionOnPastDayReturnsToThatDay(): void
    {
        $day = $this->today->modify('-2 days')->format('Y-m-d');

        $this->client->request('GET', '/day/'.$day);
        $this->client->submitForm('Log meal', ['description' => ' ']);

        self::assertResponseRedirects('/day/'.$day);
    }

    public function testAdjustingAndDeletingAPastMealReturnToItsDay(): void
    {
        $day = $this->today->modify('-5 days');
        $entry = $this->storeEntry('old meal', $day->setTime(9, 0));

        $crawler = $this->client->request('GET', '/meals/'.$entry->getId().'/edit');
        self::assertSame('/day/'.$day->format('Y-m-d'), $crawler->filter('a:contains("Back")')->attr('href'));
        $this->client->submitForm('Save');
        self::assertResponseRedirects('/day/'.$day->format('Y-m-d'));

        $this->client->request('GET', '/day/'.$day->format('Y-m-d'));
        $this->client->submitForm('✕');
        self::assertResponseRedirects('/day/'.$day->format('Y-m-d'));
    }

    public function testEstimatedPastMealGoesToReviewThenBackToItsDay(): void
    {
        $day = $this->today->modify('-1 day');
        $this->estimator()->willReturn(new MealEstimate([new EstimatedItem('Soup', 300, 150, 5, 20, 5)], 'test'));

        $this->client->request('GET', '/day/'.$day->format('Y-m-d'));
        $this->client->submitForm('Log meal', ['description' => 'soup', 'time' => '18:00']);
        $this->client->followRedirect();
        $this->client->submitForm('Save');

        self::assertResponseRedirects('/day/'.$day->format('Y-m-d'));
        self::assertSame(MealStatus::Estimated, $this->onlyEntry()->getStatus());
    }

    public function testAdjustPageShowsWhenTheMealWasEatenInLocalTime(): void
    {
        $entry = $this->storeEntry('late snack', $this->today->modify('-1 day')->setTime(23, 40)); // 20:40 UTC

        $crawler = $this->client->request('GET', '/meals/'.$entry->getId().'/edit');

        self::assertSame($this->today->modify('-1 day')->format('Y-m-d'), $crawler->filter('input[name=eaten_date]')->attr('value'));
        self::assertSame('23:40', $crawler->filter('input[name=eaten_time]')->attr('value'));
    }

    public function testMovingAMealToAnotherDayAndTime(): void
    {
        $entry = $this->storeEntry('dinner', $this->today->setTime(0, 10));
        $target = $this->today->modify('-2 days');

        $this->client->request('GET', '/meals/'.$entry->getId().'/edit');
        $this->client->submitForm('Save', ['eaten_date' => $target->format('Y-m-d'), 'eaten_time' => '19:30']);

        self::assertResponseRedirects('/day/'.$target->format('Y-m-d'), message: 'goes to the new day');
        self::assertSame($target->setTime(19, 30)->getTimestamp(), $this->onlyEntry()->getEatenAt()->getTimestamp());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidMoves(): iterable
    {
        yield 'future' => ['+2 days', '12:00', "You can't log meals in the future"];
        yield 'bad time' => ['-1 day', '7pm', 'Enter a valid date and time'];
        yield 'bad date' => ['2026-02-30', '12:00', 'Enter a valid date and time'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMoves')]
    public function testInvalidMoveIsRejectedAndNothingChanges(string $date, string $time, string $error): void
    {
        $original = $this->today->modify('-1 day')->setTime(8, 0);
        $entry = $this->storeEntry('breakfast', $original);
        $dateValue = str_starts_with($date, '20') ? $date : $this->today->modify($date)->format('Y-m-d');

        $this->client->request('GET', '/meals/'.$entry->getId().'/edit');
        $form = $this->client->getCrawler()->selectButton('Save')->form();
        $form->disableValidation()->setValues(['eaten_date' => $dateValue, 'eaten_time' => $time]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.eaten-at', $error);
        self::assertSame($original->getTimestamp(), $this->onlyEntry()->getEatenAt()->getTimestamp());
    }

    private function formToken(string $url): string
    {
        return $this->client->request('GET', $url)->filter('.meal-form input[name=_token]')->attr('value');
    }

    private function onlyEntry(): MealEntry
    {
        $this->em()->clear();
        $entries = $this->em()->getRepository(MealEntry::class)->findAll();
        self::assertCount(1, $entries);

        return $entries[0];
    }

    private function storeEntry(string $text, \DateTimeImmutable $eatenAt): MealEntry
    {
        $entry = new MealEntry($this->em()->find(User::class, $this->user->getId()), $text, $eatenAt);
        $entry->applyEstimate(new MealEstimate([new EstimatedItem('Something', 100, 200, 10, 20, 5)], 'test'), new \DateTimeImmutable());
        $this->em()->persist($entry);
        $this->em()->flush();

        return $entry;
    }

    private function estimator(): FakeNutritionEstimator
    {
        return static::getContainer()->get(NutritionEstimator::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
