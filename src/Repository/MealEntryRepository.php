<?php

namespace App\Repository;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Meal\MealStatus;
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
            ->setParameter('estimated', MealStatus::Estimated)
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
}
