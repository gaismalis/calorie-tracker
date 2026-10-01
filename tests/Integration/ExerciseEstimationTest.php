<?php

namespace App\Tests\Integration;

use App\Entity\ExerciseEntry;
use App\Entity\User;
use App\Entity\WeightEntry;
use App\Estimation\EstimationOutcome;
use App\Estimation\EstimationStatus;
use App\Exercise\EstimatedActivity;
use App\Exercise\ExerciseEstimate;
use App\Exercise\ExerciseEstimation;
use App\Exercise\ExerciseEstimator;
use App\Message\EstimateExercise;
use App\MessageHandler\EstimateExerciseHandler;
use App\Repository\ExerciseEntryRepository;
use App\Tests\Support\FakeExerciseEstimator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

class ExerciseEstimationTest extends KernelTestCase
{
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->user = (new User())->setEmail('me@example.com')->setPassword('x');
        $this->em()->persist($this->user);
        $this->em()->flush();
    }

    public function testStatedCaloriesAreTrustedWithoutAskingTheAi(): void
    {
        [$entry, $outcome] = $this->estimation()->logExercise($this->user, 'I had a workout and burned 600kcal');

        self::assertSame(EstimationOutcome::Estimated, $outcome);
        self::assertSame(600.0, $entry->getKcal());
        self::assertSame('user', $entry->getEstimatedBy());
        self::assertSame('as you entered', $entry->getItems()->first()->getAssumption());
        self::assertSame([], $this->estimator()->calls, 'no AI call');
    }

    public function testAiEstimatesWithLatestWeightAndQuickTimeLimit(): void
    {
        $this->em()->persist(new WeightEntry($this->user, new \DateTimeImmutable('-3 days'), 84.2));
        $this->em()->flush();
        $this->estimator()->willReturn(new ExerciseEstimate([new EstimatedActivity('Basketball', 120, 950)], 'gemini:test'));

        [$entry, $outcome] = $this->estimation()->logExercise($this->user, 'played basketball for 2h');

        self::assertSame(EstimationOutcome::Estimated, $outcome);
        self::assertSame(950.0, $entry->getKcal());
        self::assertSame([['description' => 'played basketball for 2h', 'weightKg' => 84.2, 'timeLimit' => 5.0]], $this->estimator()->calls);
    }

    public function testTextWithoutActivityIsNotKept(): void
    {
        [, $outcome] = $this->estimation()->logExercise($this->user, 'watched TV');

        self::assertSame(EstimationOutcome::NoFood, $outcome);
        self::assertSame(0, $this->em()->getRepository(ExerciseEntry::class)->count([]));
    }

    public function testFailureIsRetriedInTheBackgroundAndHandlerFinishesIt(): void
    {
        $this->estimator()->willFail('HTTP 503');

        [$entry, $outcome] = $this->estimation()->logExercise($this->user, 'yoga 1h');

        self::assertSame(EstimationOutcome::Retrying, $outcome);
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        self::assertEquals(new EstimateExercise($entry->getId()), $sent[0]->getMessage());
        self::assertSame(10_000, $sent[0]->last(DelayStamp::class)->getDelay());

        $this->estimator()->willReturn(new ExerciseEstimate([new EstimatedActivity('Yoga', 60, 180)], 'gemini:test'));
        static::getContainer()->get(EstimateExerciseHandler::class)(new EstimateExercise($entry->getId()));

        $this->em()->clear();
        $entry = $this->em()->find(ExerciseEntry::class, $entry->getId());
        self::assertSame(EstimationStatus::Estimated, $entry->getStatus());
        self::assertSame(180.0, $entry->getKcal());
        self::assertNull($this->estimator()->calls[1]['timeLimit'], 'background attempts have no 5 s limit');
    }

    public function testRepositoryGroupsByLocalDayAndCountsOnlyEstimated(): void
    {
        $riga = new \DateTimeZone('Europe/Riga');
        $this->logAt('run 300 kcal', '2026-09-10 08:00', $riga);
        $this->logAt('swim 200 kcal', '2026-09-11 00:30', $riga); // 21:30 UTC on the 10th, the 11th in Riga
        $this->estimator()->willFail();
        $this->logAt('mystery sport', '2026-09-10 12:00', $riga); // pending

        $repo = static::getContainer()->get(ExerciseEntryRepository::class);
        self::assertSame(['2026-09-10' => 300.0, '2026-09-11' => 200.0], $repo->dailyExercise($this->user, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30')));
        self::assertCount(2, $repo->findForDay($this->user, new \DateTimeImmutable('2026-09-10', $riga)), 'the day view lists pending entries too');
    }

    private function logAt(string $text, string $localTime, \DateTimeZone $tz): void
    {
        $this->estimation()->logExercise($this->user, $text, new \DateTimeImmutable($localTime, $tz));
    }

    private function estimation(): ExerciseEstimation
    {
        return static::getContainer()->get(ExerciseEstimation::class);
    }

    private function estimator(): FakeExerciseEstimator
    {
        return static::getContainer()->get(ExerciseEstimator::class);
    }

    private function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
