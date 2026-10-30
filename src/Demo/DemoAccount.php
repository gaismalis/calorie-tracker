<?php

namespace App\Demo;

use App\Entity\AiUsage;
use App\Entity\ExerciseEntry;
use App\Entity\MealEntry;
use App\Entity\Recipe;
use App\Entity\User;
use App\Entity\WeeklyFoodReview;
use App\Entity\WeightEntry;
use App\Exercise\ExerciseEstimate;
use App\Nutrition\MealEstimate;
use App\Profile\ActivityLevel;
use App\Profile\Sex;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The shared demo account behind "Try the demo": a made-up person ("Alex") with three months of meals, workouts
 * and weigh-ins (DemoPlan). Created on first use; its data is rebuilt up to "now" when it's older than
 * REFRESH_AFTER or from an earlier day, so the demo always ends today. Visitors can look but not change anything
 * (DemoReadOnlySubscriber), and its weekly reviews are written by DemoFoodReviewer, never the AI.
 */
final class DemoAccount
{
    public const EMAIL = 'demo@example.com';
    public const NAME = 'Alex';
    public const TIMEZONE = 'Europe/Riga';
    private const REFRESH_AFTER = '1 hour';
    /** Postgres advisory lock: one rebuild at a time. */
    private const LOCK = 7_260_1030;
    public const ESTIMATED_BY = 'demo';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly ClockInterface $clock,
    ) {
    }

    /** The demo user, with data up to now. */
    public function ready(): User
    {
        return $this->em->wrapInTransaction(function (): User {
            $this->em->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);

            $user = $this->users->findOneBy(['email' => self::EMAIL]);
            if (null === $user) {
                $user = $this->create();
            } elseif (!$user->isDemo()) {
                throw new \LogicException(sprintf('%s belongs to a real account, not the demo.', self::EMAIL));
            }

            $now = $this->clock->now();
            $refreshed = $user->getDemoRefreshedAt();
            if (null === $refreshed || $refreshed < $now->modify('-'.self::REFRESH_AFTER) || $user->today($refreshed) != $user->today($now)) {
                $this->rebuild($user, $now);
                $user = $this->users->find($user->getId());
            }

            return $user;
        });
    }

    /** The plan for today, e.g. for the landing page's weight curve (no database). */
    public function plan(): DemoPlan
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE));

        return new DemoPlan($now->setTime(0, 0), $now);
    }

    private function create(): User
    {
        $user = (new User())
            ->setEmail(self::EMAIL)
            ->setPassword('!') // not a password hash: nobody can log in with a password
            ->setTimezone(self::TIMEZONE)
            ->setSex(Sex::Male)
            ->setBirthDate(new \DateTimeImmutable('1991-04-12'))
            ->setHeightCm(180)
            ->setActivityLevel(ActivityLevel::Light)
            ->setWeeklyGoalKg(-0.5)
            ->markVerified()
            ->markDemo();
        $this->em->persist($user);
        $this->em->flush(); // an id, for the rebuild's queries

        return $user;
    }

    private function rebuild(User $user, \DateTimeImmutable $now): void
    {
        foreach ([MealEntry::class, ExerciseEntry::class, WeightEntry::class, Recipe::class, WeeklyFoodReview::class, AiUsage::class] as $entity) {
            $this->em->createQuery(sprintf('DELETE FROM %s e WHERE e.user = :user', $entity))->setParameter('user', $user)->execute();
        }

        $local = $now->setTimezone($user->getDateTimeZone());
        $plan = new DemoPlan($local->setTime(0, 0), $local);

        $recipes = [];
        foreach ($plan->recipes() as $name => $items) {
            $this->em->persist($recipes[$name] = new Recipe($user, $name, $items));
        }
        foreach ($plan->meals() as $planned) {
            $meal = new MealEntry($user, $planned['text'], $planned['at']);
            if (null !== $planned['recipe']) {
                $meal->startFromRecipe($recipes[$planned['recipe']]);
            }
            $meal->applyEstimate(new MealEstimate($planned['items'], null === $planned['recipe'] ? self::ESTIMATED_BY : Recipe::ESTIMATED_BY, $planned['name']), $planned['at']);
            $this->em->persist($meal);
        }
        foreach ($plan->exercises() as $planned) {
            $exercise = new ExerciseEntry($user, $planned['text'], $planned['at']);
            $exercise->applyEstimate(new ExerciseEstimate($planned['activities'], self::ESTIMATED_BY), $planned['at']);
            $this->em->persist($exercise);
        }
        foreach ($plan->weights() as $date => $kg) {
            $this->em->persist(new WeightEntry($user, new \DateTimeImmutable($date), $kg));
        }

        $user->setDemoRefreshedAt($now);
        $this->em->flush();
        $this->em->clear(); // thousands of rows; the caller reloads what it needs
    }
}
