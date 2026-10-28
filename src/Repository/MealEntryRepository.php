<?php

namespace App\Repository;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Estimation\EstimationStatus;
use App\Nutrition\FoodCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MealEntry> */
class MealEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MealEntry::class);
    }

    /**
     * @param \DateTimeImmutable $day any moment of the day, in the timezone that defines the day (usually the user's)
     *
     * @return list<MealEntry>
     */
    public function findForDay(User $user, \DateTimeImmutable $day): array
    {
        $start = $day->setTime(0, 0);
        $end = $start->modify('+1 day'); // calendar day, so DST days are 23 or 25 hours
        $utc = new \DateTimeZone('UTC');

        return $this->createQueryBuilder('m')
            ->addSelect('i')
            ->leftJoin('m.items', 'i')
            ->where('m.user = :user')
            ->andWhere('m.eatenAt >= :start AND m.eatenAt < :end')
            ->setParameter('user', $user)
            ->setParameter('start', $start->setTimezone($utc))
            ->setParameter('end', $end->setTimezone($utc))
            ->orderBy('m.eatenAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<MealEntry> all of the user's estimated meals with their foods, oldest first */
    public function findEstimatedForUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('i')
            ->leftJoin('m.items', 'i')
            ->where('m.user = :user')
            ->andWhere('m.status = :estimated')
            ->setParameter('user', $user)
            ->setParameter('estimated', EstimationStatus::Estimated)
            ->orderBy('m.eatenAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Total kcal of estimated meals per calendar day in the user's timezone.
     *
     * @param \DateTimeImmutable $from first local date (inclusive)
     * @param \DateTimeImmutable $to   last local date (exclusive)
     *
     * @return array<string, float> kcal by 'Y-m-d'; days without estimated meals are absent
     */
    public function dailyIntake(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $timezone = $user->getDateTimeZone();
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($from->format('Y-m-d'), $timezone);
        $end = new \DateTimeImmutable($to->format('Y-m-d'), $timezone);

        $rows = $this->createQueryBuilder('m')
            ->select('m.eatenAt, m.kcal')
            ->where('m.user = :user')
            ->andWhere('m.status = :estimated')
            ->andWhere('m.eatenAt >= :start AND m.eatenAt < :end')
            ->setParameter('user', $user)
            ->setParameter('estimated', EstimationStatus::Estimated)
            ->setParameter('start', $start->setTimezone($utc))
            ->setParameter('end', $end->setTimezone($utc))
            ->getQuery()
            ->getArrayResult();

        $byDay = [];
        foreach ($rows as $row) {
            $day = $row['eatenAt']->setTimezone($timezone)->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + $row['kcal'];
        }
        ksort($byDay);

        return $byDay;
    }

    /**
     * Every item of the estimated meals in a date range, by local day, in the order they were eaten (for the
     * weekly food review).
     *
     * @param \DateTimeImmutable $from first local date (inclusive)
     * @param \DateTimeImmutable $to   last local date (exclusive)
     *
     * @return array<string, list<array{time: string, name: string, grams: float, kcal: float, protein: float, carbs: float, fat: float, category: string}>>
     */
    public function itemsByDay(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $timezone = $user->getDateTimeZone();
        $utc = new \DateTimeZone('UTC');

        $rows = $this->createQueryBuilder('m')
            ->select('m.eatenAt, i.name, i.grams, i.kcal, i.protein, i.carbs, i.fat, i.category')
            ->join('m.items', 'i')
            ->where('m.user = :user')
            ->andWhere('m.status = :estimated')
            ->andWhere('m.eatenAt >= :start AND m.eatenAt < :end')
            ->orderBy('m.eatenAt')
            ->addOrderBy('i.id')
            ->setParameter('user', $user)
            ->setParameter('estimated', EstimationStatus::Estimated)
            ->setParameter('start', (new \DateTimeImmutable($from->format('Y-m-d'), $timezone))->setTimezone($utc))
            ->setParameter('end', (new \DateTimeImmutable($to->format('Y-m-d'), $timezone))->setTimezone($utc))
            ->getQuery()
            ->getArrayResult();

        $byDay = [];
        foreach ($rows as $row) {
            $local = $row['eatenAt']->setTimezone($timezone);
            $byDay[$local->format('Y-m-d')][] = [
                'time' => $local->format('H:i'),
                'name' => $row['name'],
                'grams' => (float) $row['grams'],
                'kcal' => (float) $row['kcal'],
                'protein' => (float) $row['protein'],
                'carbs' => (float) $row['carbs'],
                'fat' => (float) $row['fat'],
                // Items from before categories existed count as food, as everywhere else.
                'category' => ($row['category'] instanceof FoodCategory ? $row['category']->value : $row['category']) ?? FoodCategory::Food->value,
            ];
        }

        return $byDay;
    }

    /**
     * kcal per day of the items in one category (e.g. alcohol), for estimated meals.
     *
     * @param \DateTimeImmutable $from first local date (inclusive)
     * @param \DateTimeImmutable $to   last local date (exclusive)
     *
     * @return array<string, float> kcal by 'Y-m-d'; days without such items are absent
     */
    public function dailyCategoryIntake(User $user, FoodCategory $category, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $timezone = $user->getDateTimeZone();
        $utc = new \DateTimeZone('UTC');

        $rows = $this->createQueryBuilder('m')
            ->select('m.eatenAt, i.kcal')
            ->join('m.items', 'i')
            ->where('m.user = :user')
            ->andWhere('m.status = :estimated')
            ->andWhere('i.category = :category')
            ->andWhere('m.eatenAt >= :start AND m.eatenAt < :end')
            ->setParameter('user', $user)
            ->setParameter('estimated', EstimationStatus::Estimated)
            ->setParameter('category', $category)
            ->setParameter('start', (new \DateTimeImmutable($from->format('Y-m-d'), $timezone))->setTimezone($utc))
            ->setParameter('end', (new \DateTimeImmutable($to->format('Y-m-d'), $timezone))->setTimezone($utc))
            ->getQuery()
            ->getArrayResult();

        $byDay = [];
        foreach ($rows as $row) {
            $day = $row['eatenAt']->setTimezone($timezone)->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + $row['kcal'];
        }
        ksort($byDay);

        return $byDay;
    }

    /** The user's local date of their first estimated meal, or null without any. */
    public function firstMealDay(User $user): ?\DateTimeImmutable
    {
        $first = $this->createQueryBuilder('m')
            ->select('MIN(m.eatenAt)')
            ->where('m.user = :user')
            ->andWhere('m.status = :estimated')
            ->setParameter('user', $user)
            ->setParameter('estimated', EstimationStatus::Estimated)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $first ? null : (new \DateTimeImmutable($first, new \DateTimeZone('UTC')))->setTimezone($user->getDateTimeZone())->setTime(0, 0);
    }
}
