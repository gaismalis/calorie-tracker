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

    /** @return list<MealEntry> */
    public function findForDay(User $user, \DateTimeImmutable $day): array
    {
        $start = $day->setTime(0, 0);

        return $this->createQueryBuilder('m')
            ->addSelect('i')
            ->leftJoin('m.items', 'i')
            ->where('m.user = :user')
            ->andWhere('m.eatenAt >= :start AND m.eatenAt < :end')
            ->setParameter('user', $user)
            ->setParameter('start', $start)
            ->setParameter('end', $start->modify('+1 day'))
            ->orderBy('m.eatenAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
