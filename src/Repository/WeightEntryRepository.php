<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\WeightEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WeightEntry> */
class WeightEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeightEntry::class);
    }

    public function findForDate(User $user, \DateTimeImmutable $date): ?WeightEntry
    {
        return $this->findOneBy(['user' => $user, 'date' => new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'))]);
    }

    public function findLatest(User $user): ?WeightEntry
    {
        return $this->findOneBy(['user' => $user], ['date' => 'DESC']);
    }

    /** @return list<WeightEntry> newest first */
    public function findRecent(User $user, int $limit = 60): array
    {
        return $this->findBy(['user' => $user], ['date' => 'DESC'], $limit);
    }

    /** @return array<string, float> kg by date ('Y-m-d') from $since (inclusive), ascending */
    public function weightsByDate(User $user, \DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('w')
            ->select('w.date, w.weightKg')
            ->where('w.user = :user')
            ->andWhere('w.date >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', new \DateTimeImmutable($since->format('Y-m-d'), new \DateTimeZone('UTC')))
            ->orderBy('w.date', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $weights = [];
        foreach ($rows as $row) {
            $weights[$row['date']->format('Y-m-d')] = $row['weightKg'];
        }

        return $weights;
    }
}
