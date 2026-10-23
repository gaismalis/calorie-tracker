<?php

namespace App\Repository;

use App\Entity\AiUsage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AiUsage> */
class AiUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiUsage::class);
    }

    public function usedOn(User $user, string $feature, string $day): int
    {
        return $this->findOneBy(['user' => $user, 'feature' => $feature, 'day' => $day])?->getCount() ?? 0;
    }

    public function record(User $user, string $feature, string $day): void
    {
        $usage = $this->findOneBy(['user' => $user, 'feature' => $feature, 'day' => $day]) ?? new AiUsage($user, $feature, $day);
        $usage->increment();
        $this->getEntityManager()->persist($usage);
        $this->getEntityManager()->flush();
    }
}
