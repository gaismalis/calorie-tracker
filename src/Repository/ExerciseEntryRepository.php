<?php

namespace App\Repository;

use App\Entity\ExerciseEntry;
use App\Entity\User;
use App\Estimation\EstimationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ExerciseEntry> */
class ExerciseEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExerciseEntry::class);
    }

    /**
     * @param \DateTimeImmutable $day any moment of the day, in the timezone that defines the day (usually the user's)
     *
     * @return list<ExerciseEntry>
     */
    public function findForDay(User $user, \DateTimeImmutable $day): array
    {
        $start = $day->setTime(0, 0);
        $utc = new \DateTimeZone('UTC');

        return $this->createQueryBuilder('e')
            ->addSelect('i')
            ->leftJoin('e.items', 'i')
            ->where('e.user = :user')
            ->andWhere('e.performedAt >= :start AND e.performedAt < :end')
            ->setParameter('user', $user)
            ->setParameter('start', $start->setTimezone($utc))
            ->setParameter('end', $start->modify('+1 day')->setTimezone($utc))
            ->orderBy('e.performedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Total kcal of estimated exercise per calendar day in the user's timezone.
     *
     * @param \DateTimeImmutable $from first local date (inclusive)
     * @param \DateTimeImmutable $to   last local date (exclusive)
     *
     * @return array<string, float> kcal by 'Y-m-d'; days without estimated exercise are absent
     */
    public function dailyExercise(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $timezone = $user->getDateTimeZone();
        $utc = new \DateTimeZone('UTC');

        $rows = $this->createQueryBuilder('e')
            ->select('e.performedAt, e.kcal')
            ->where('e.user = :user')
            ->andWhere('e.status = :estimated')
            ->andWhere('e.performedAt >= :start AND e.performedAt < :end')
            ->setParameter('user', $user)
            ->setParameter('estimated', EstimationStatus::Estimated)
            ->setParameter('start', (new \DateTimeImmutable($from->format('Y-m-d'), $timezone))->setTimezone($utc))
            ->setParameter('end', (new \DateTimeImmutable($to->format('Y-m-d'), $timezone))->setTimezone($utc))
            ->getQuery()
            ->getArrayResult();

        $byDay = [];
        foreach ($rows as $row) {
            $day = $row['performedAt']->setTimezone($timezone)->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + $row['kcal'];
        }
        ksort($byDay);

        return $byDay;
    }
}
