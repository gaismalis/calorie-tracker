<?php

namespace App\Tests\Integration;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Entity\WeightEntry;
use App\Nutrition\EstimatedItem;
use App\Nutrition\MealEstimate;
use App\Repository\MealEntryRepository;
use App\Repository\WeightEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EnergyRepositoriesTest extends KernelTestCase
{
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->user = (new User())->setEmail('me@example.com')->setPassword('x')->setTimezone('Europe/Riga');
        $this->em()->persist($this->user);
        $this->em()->flush();
    }

    public function testDailyIntakeGroupsByLocalDayAndCountsOnlyEstimatedMeals(): void
    {
        $riga = new \DateTimeZone('Europe/Riga');
        $this->meal('2026-09-10 08:00', 500, $riga);
        $this->meal('2026-09-10 23:30', 300, $riga); // 20:30 UTC, still the 10th in Riga
        $this->meal('2026-09-11 00:30', 200, $riga); // 21:30 UTC on the 10th, but the 11th in Riga
        $this->meal('2026-09-12 12:00', 999, $riga, estimated: false); // pending: no numbers yet
        $this->meal('2026-09-14 12:00', 700, $riga); // outside the range

        $intake = $this->meals()->dailyIntake($this->user, new \DateTimeImmutable('2026-09-10'), new \DateTimeImmutable('2026-09-14'));

        self::assertSame(['2026-09-10' => 800.0, '2026-09-11' => 200.0], $intake);
    }

    public function testDailyIntakeOnlyForTheGivenUser(): void
    {
        $other = (new User())->setEmail('other@example.com')->setPassword('x');
        $this->em()->persist($other);
        $this->meal('2026-09-10 12:00', 500, new \DateTimeZone('UTC'), user: $other);

        self::assertSame([], $this->meals()->dailyIntake($this->user, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-10-01')));
    }

    public function testWeightsByDate(): void
    {
        foreach (['2026-08-01' => 82.0, '2026-09-02' => 81.0, '2026-09-01' => 81.4] as $date => $kg) {
            $this->em()->persist(new WeightEntry($this->user, new \DateTimeImmutable($date), $kg));
        }
        $this->em()->flush();

        self::assertSame(
            ['2026-09-01' => 81.4, '2026-09-02' => 81.0],
            static::getContainer()->get(WeightEntryRepository::class)->weightsByDate($this->user, new \DateTimeImmutable('2026-09-01')),
        );
    }

    private function meal(string $localTime, float $kcal, \DateTimeZone $tz, bool $estimated = true, ?User $user = null): void
    {
        $entry = new MealEntry($user ?? $this->user, 'food', new \DateTimeImmutable($localTime, $tz));
        if ($estimated) {
            $entry->applyEstimate(new MealEstimate([new EstimatedItem('Food', 100, $kcal, 1, 1, 1)], 'test'), new \DateTimeImmutable());
        }
        $this->em()->persist($entry);
        $this->em()->flush();
    }

    private function meals(): MealEntryRepository
    {
        return static::getContainer()->get(MealEntryRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
