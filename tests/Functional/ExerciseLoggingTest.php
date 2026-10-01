<?php

namespace App\Tests\Functional;

use App\Entity\ExerciseEntry;
use App\Entity\User;
use App\Entity\WeightEntry;
use App\Estimation\EstimationStatus;
use App\Exercise\EstimatedActivity;
use App\Exercise\ExerciseEstimate;
use App\Exercise\ExerciseEstimator;
use App\Tests\Support\FakeExerciseEstimator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ExerciseLoggingTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->user = (new User())->setEmail('me@example.com')->setPassword('x');
        $this->em()->persist($this->user);
        $this->em()->flush();
        $this->client->loginUser($this->user);
    }

    public function testStatedCaloriesAreLoggedWithoutAi(): void
    {
        $this->client->request('GET', '/');
        $this->client->submitForm('Log exercise', ['description' => 'I had a workout and burned 600kcal']);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Logged 600 kcal burned, as you entered.');
        self::assertSame([], $this->estimator()->calls);
        self::assertSelectorTextContains('#panel-exercise .entry', '600 kcal burned');
    }

    public function testAiEstimatesActivity(): void
    {
        $this->estimator()->willReturn(new ExerciseEstimate([new EstimatedActivity('Basketball', 120, 950, null)], 'gemini:test'));

        $this->client->request('GET', '/');
        $this->client->submitForm('Log exercise', ['description' => 'played basketball for 2h']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-success', 'Logged ~950 kcal burned.');
        self::assertSame('played basketball for 2h', $this->estimator()->calls[0]['description']);
        self::assertSame(950.0, $this->onlyEntry()->getKcal());
    }

    public function testNoActivityIsNotStored(): void
    {
        $this->client->request('GET', '/');
        $this->client->submitForm('Log exercise', ['description' => 'watched a movie']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', "couldn't find any activity");
        self::assertSame(0, $this->em()->getRepository(ExerciseEntry::class)->count([]));
    }

    public function testSlowAiSavesAndShowsEstimatingWithTheLogOpenOnTheExerciseTab(): void
    {
        $this->estimator()->willFail('Time limit of 5s reached.');

        $this->client->request('GET', '/');
        $this->client->submitForm('Log exercise', ['description' => 'yoga']);
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-info', 'in the background');
        self::assertNotNull($crawler->filter('details.log')->attr('open'), 'log opens when something needs attention');
        self::assertSame('true', $crawler->filter('#tab-exercise')->attr('aria-selected'));
        self::assertNull($crawler->filter('#panel-exercise')->attr('hidden'));
        self::assertSelectorTextContains('#panel-exercise .entry-pending', 'Estimating…');
        self::assertSelectorExists('meta[http-equiv="refresh"]');
    }

    public function testExerciseOnAPastDayAtAGivenTime(): void
    {
        $day = $this->user->today()->modify('-2 days');

        $this->client->request('GET', '/day/'.$day->format('Y-m-d'));
        $this->client->submitForm('Log exercise', ['description' => 'run, 400 kcal', 'time' => '07:15']);

        self::assertResponseRedirects('/day/'.$day->format('Y-m-d'));
        self::assertSame($day->setTime(7, 15)->getTimestamp(), $this->onlyEntry()->getPerformedAt()->getTimestamp());
    }

    public function testEmptyAndTooLongDescriptionsAreRejected(): void
    {
        $this->client->request('GET', '/');
        $this->client->submitForm('Log exercise', ['description' => '  ']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Tell me what you did first');

        $form = $this->client->getCrawler()->selectButton('Log exercise')->form();
        $form->disableValidation()->setValues(['description' => str_repeat('a', 1001)]);
        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'under 1000 characters');
    }

    public function testAdjustingCaloriesAndDeleting(): void
    {
        $this->client->request('GET', '/');
        $this->client->submitForm('Log exercise', ['description' => 'workout 600 kcal']);
        $entry = $this->onlyEntry();
        $item = $entry->getItems()->first();

        $this->client->request('GET', '/exercises/'.$entry->getId().'/edit');
        self::assertSelectorTextContains('h1', 'Adjust exercise');
        $this->client->submitForm('Save', ['kcal['.$item->getId().']' => '450']);
        self::assertResponseRedirects('/');
        self::assertSame(450.0, $this->onlyEntry()->getKcal());

        $this->client->request('GET', '/');
        $this->client->getCrawler()->filter('#panel-exercise form[action$="/delete"]')->form();
        $this->client->submit($this->client->getCrawler()->filter('#panel-exercise form[action$="/delete"]')->form());
        self::assertSame(0, $this->em()->getRepository(ExerciseEntry::class)->count([]));
    }

    public function testInvalidAdjustmentIsRejected(): void
    {
        $this->client->request('GET', '/');
        $this->client->submitForm('Log exercise', ['description' => 'workout 600 kcal']);
        $entry = $this->onlyEntry();
        $item = $entry->getItems()->first();

        $this->client->request('GET', '/exercises/'.$entry->getId().'/edit');
        $form = $this->client->getCrawler()->selectButton('Save')->form();
        $form->disableValidation()->setValues(['kcal['.$item->getId().']' => '-1']);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('table.adjust', 'Enter kcal between 0 and 10000');
    }

    public function testOtherUsersExerciseIsNotReachable(): void
    {
        $other = (new User())->setEmail('other@example.com')->setPassword('x');
        $this->em()->persist($other);
        $entry = new ExerciseEntry($other, 'run', new \DateTimeImmutable());
        $this->em()->persist($entry);
        $this->em()->flush();

        foreach (['/edit' => 'GET', '/delete' => 'POST', '/retry' => 'POST'] as $suffix => $method) {
            $this->client->request($method, '/exercises/'.$entry->getId().$suffix, ['_token' => 'x']);
            self::assertResponseStatusCodeSame(404, $suffix);
        }
    }

    public function testCsrfIsRequired(): void
    {
        $this->client->request('POST', '/exercises', ['description' => 'run 300 kcal', '_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testFailedExerciseOffersRetry(): void
    {
        $entry = new ExerciseEntry($this->em()->find(User::class, $this->user->getId()), 'mystery sport', new \DateTimeImmutable());
        $entry->estimationFailed('down', new \DateTimeImmutable('-2 hours'), giveUp: true);
        $this->em()->persist($entry);
        $this->em()->flush();
        $this->estimator()->willReturn(new ExerciseEstimate([new EstimatedActivity('Sport', 60, 400)], 'gemini:test'));

        $this->client->request('GET', '/');
        $this->client->submit($this->client->getCrawler()->filter('#panel-exercise .retry form')->form());

        self::assertResponseRedirects('/');
        self::assertSame(EstimationStatus::Estimated, $this->onlyEntry()->getStatus());
    }

    public function testExerciseCountsTowardsBurnedAndTarget(): void
    {
        $user = $this->em()->find(User::class, $this->user->getId());
        $user->setSex(\App\Profile\Sex::Male)->setBirthDate(new \DateTimeImmutable('-30 years -1 day'))->setHeightCm(180)
            ->setActivityLevel(\App\Profile\ActivityLevel::Sedentary);
        $this->em()->persist(new WeightEntry($user, $user->today(), 80));
        $this->em()->flush();

        $this->client->request('GET', '/');
        $this->client->submitForm('Log exercise', ['description' => 'workout, burned 500 kcal']);
        $this->client->followRedirect();

        $baseline = round(round(10 * 80 + 6.25 * 180 - 5 * $user->getAge() + 5) * 1.2);
        self::assertSelectorTextContains('.stat-burned', number_format($baseline + 500, 0, '.', ' '));
        self::assertSelectorTextContains('.stat-burned', 'incl. 500 exercise');
        self::assertSelectorTextContains('.stat-left', 'of ~'.number_format($baseline + 500, 0, '.', ' ').' kcal');
    }

    private function onlyEntry(): ExerciseEntry
    {
        $this->em()->clear();
        $entries = $this->em()->getRepository(ExerciseEntry::class)->findAll();
        self::assertCount(1, $entries);

        return $entries[0];
    }

    private function estimator(): FakeExerciseEstimator
    {
        return static::getContainer()->get(ExerciseEstimator::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
