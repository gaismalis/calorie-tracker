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
}
