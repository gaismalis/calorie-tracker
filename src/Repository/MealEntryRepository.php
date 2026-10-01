<?php

namespace App\Repository;

use App\Entity\MealEntry;
use App\Entity\User;
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
}
