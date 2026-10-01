<?php

namespace App\Tests\Integration;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Estimation\EstimationOutcome;
use App\Meal\MealEstimation;
use App\Estimation\EstimationStatus;
use App\Message\EstimateMeal;
use App\MessageHandler\EstimateMealHandler;
use App\Nutrition\EstimatedItem;
use App\Nutrition\MealEstimate;
use App\Nutrition\NutritionEstimator;
use App\Tests\Support\FakeNutritionEstimator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

class MealEstimationTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-01 12:00:00');
        $this->user = (new User())->setEmail('me@example.com')->setPassword('x');
        $this->em()->persist($this->user);
        $this->em()->flush();
    }

    public function testQuickSuccessEstimatesImmediately(): void
    {
        $this->estimator()->willReturn(self::apple());

        [$entry, $outcome] = $this->estimation()->logMeal($this->user, 'an apple');

        self::assertSame(EstimationOutcome::Estimated, $outcome);
        self::assertSame(EstimationStatus::Estimated, $entry->getStatus());
        self::assertSame(95.0, $entry->getKcal());
        self::assertSame(1, $entry->getEstimationAttempts());
        self::assertSame([5.0], $this->estimator()->timeLimits);
        self::assertSame([], $this->transport()->getSent());
    }

    public function testMealIsSavedBeforeTheAiIsAsked(): void
    {
        $this->estimator()->willFail();

        [$entry] = $this->estimation()->logMeal($this->user, 'an apple');

        $this->em()->clear();
        self::assertNotNull($this->em()->find(MealEntry::class, $entry->getId()));
    }

    public function testNoFoodRemovesTheMeal(): void
    {
        $this->estimator()->willReturn(new MealEstimate([], 'test'));

        [, $outcome] = $this->estimation()->logMeal($this->user, 'hello');

        self::assertSame(EstimationOutcome::NoFood, $outcome);
        self::assertSame(0, $this->em()->getRepository(MealEntry::class)->count([]));
    }

    public function testRetriesAfter10Seconds1MinuteAnd10MinutesThenGivesUp(): void
    {
        $this->estimator()->willFail('HTTP 503');

        [$entry, $outcome] = $this->estimation()->logMeal($this->user, 'an apple');
        self::assertSame(EstimationOutcome::Retrying, $outcome);
        self::assertSame(EstimationStatus::Pending, $entry->getStatus());
        self::assertSame([10_000], $this->takeQueuedDelays($entry));

        self::assertSame(EstimationOutcome::Retrying, $this->estimation()->runScheduledAttempt($entry->getId()));
        self::assertSame([60_000], $this->takeQueuedDelays($entry));

        self::assertSame(EstimationOutcome::Retrying, $this->estimation()->runScheduledAttempt($entry->getId()));
        self::assertSame([600_000], $this->takeQueuedDelays($entry));

        self::assertSame(EstimationOutcome::Failed, $this->estimation()->runScheduledAttempt($entry->getId()));
        self::assertSame([], $this->takeQueuedDelays($entry), 'nothing more is scheduled');

        $entry = $this->reload($entry);
        self::assertSame(EstimationStatus::Failed, $entry->getStatus());
        self::assertSame(4, $entry->getEstimationAttempts(), '1 quick try + 3 retries');
        self::assertSame('HTTP 503', $entry->getLastEstimationError());
        self::assertSame([5.0, null, null, null], $this->estimator()->timeLimits, 'background attempts have no 5 s limit');
    }

    public function testBackgroundRetrySucceeds(): void
    {
        $this->estimator()->willFail();
        [$entry] = $this->estimation()->logMeal($this->user, 'an apple');

        $this->estimator()->willReturn(self::apple());
        self::assertSame(EstimationOutcome::Estimated, $this->estimation()->runScheduledAttempt($entry->getId()));

        $entry = $this->reload($entry);
        self::assertSame(EstimationStatus::Estimated, $entry->getStatus());
        self::assertSame(95.0, $entry->getKcal());
        self::assertNull($entry->getLastEstimationError());
    }

    public function testBackgroundNoFoodMarksMealFailed(): void
    {
        $this->estimator()->willFail();
        [$entry] = $this->estimation()->logMeal($this->user, 'an apple');

        $this->estimator()->willReturn(new MealEstimate([], 'test'));
        self::assertSame(EstimationOutcome::NoFood, $this->estimation()->runScheduledAttempt($entry->getId()));

        self::assertSame(EstimationStatus::Failed, $this->reload($entry)->getStatus());
    }

    public function testScheduledAttemptIgnoresDeletedAndAlreadyEstimatedMeals(): void
    {
        $this->estimator()->willReturn(self::apple());
        [$estimated] = $this->estimation()->logMeal($this->user, 'an apple');

        self::assertNull($this->estimation()->runScheduledAttempt($estimated->getId()));
        self::assertNull($this->estimation()->runScheduledAttempt(999_999));
        self::assertCount(1, $this->estimator()->received, 'the AI is not called again');
    }

    public function testHandlerRunsTheScheduledAttempt(): void
    {
        $this->estimator()->willFail();
        [$entry] = $this->estimation()->logMeal($this->user, 'an apple');
        $this->estimator()->willReturn(self::apple());

        static::getContainer()->get(EstimateMealHandler::class)(new EstimateMeal($entry->getId()));

        self::assertSame(EstimationStatus::Estimated, $this->reload($entry)->getStatus());
    }

    public function testManualRetryIsAllowedOncePerHour(): void
    {
        $entry = $this->failedMeal();

        self::assertFalse($this->estimation()->canRetryManually($entry));
        self::assertEquals(new \DateTimeImmutable('2026-10-01 13:00:00'), $this->estimation()->manualRetryAvailableAt($entry));

        static::mockTime('+59 minutes');
        self::assertFalse($this->estimation()->canRetryManually($entry));

        static::mockTime('+1 minute');
        self::assertTrue($this->estimation()->canRetryManually($entry));
    }

    public function testFailedManualRetryStaysFailedAndRestartsTheHour(): void
    {
        $entry = $this->failedMeal();
        static::mockTime('+2 hours');
        $this->estimator()->willFail();

        self::assertSame(EstimationOutcome::Failed, $this->estimation()->retryManually($entry));

        self::assertSame(EstimationStatus::Failed, $entry->getStatus());
        self::assertSame([5.0], array_slice($this->estimator()->timeLimits, -1), 'manual retry waits at most 5 s');
        self::assertSame([], $this->transport()->getSent(), 'no automatic retries after a manual one');
        self::assertFalse($this->estimation()->canRetryManually($entry));
    }

    public function testSuccessfulManualRetry(): void
    {
        $entry = $this->failedMeal();
        static::mockTime('+1 hour');
        $this->estimator()->willReturn(self::apple());

        self::assertSame(EstimationOutcome::Estimated, $this->estimation()->retryManually($entry));
        self::assertSame(EstimationStatus::Estimated, $this->reload($entry)->getStatus());
    }

    public function testManualRetryTooEarlyIsRefused(): void
    {
        $entry = $this->failedMeal();

        $this->expectException(\LogicException::class);
        $this->estimation()->retryManually($entry);
    }

    private function failedMeal(): MealEntry
    {
        $this->estimator()->willFail();
        [$entry] = $this->estimation()->logMeal($this->user, 'an apple');
        for ($i = 0; $i < 3; ++$i) {
            $this->estimation()->runScheduledAttempt($entry->getId());
        }
        $this->transport()->reset();
        self::assertSame(EstimationStatus::Failed, $entry->getStatus());

        return $entry;
    }

    /** @return list<int> delays (ms) of queued EstimateMeal messages for this meal; clears the queue */
    private function takeQueuedDelays(MealEntry $entry): array
    {
        $delays = array_map(function (Envelope $envelope) use ($entry) {
            self::assertEquals(new EstimateMeal($entry->getId()), $envelope->getMessage());

            return $envelope->last(DelayStamp::class)?->getDelay();
        }, $this->transport()->getSent());
        $this->transport()->reset();

        return $delays;
    }

    private static function apple(): MealEstimate
    {
        return new MealEstimate([new EstimatedItem('Apple', 180, 95, 0.5, 25, 0.3)], 'test');
    }

    private function reload(MealEntry $entry): MealEntry
    {
        $this->em()->clear();

        return $this->em()->find(MealEntry::class, $entry->getId());
    }

    private function estimation(): MealEstimation
    {
        return static::getContainer()->get(MealEstimation::class);
    }

    private function estimator(): FakeNutritionEstimator
    {
        return static::getContainer()->get(NutritionEstimator::class);
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
