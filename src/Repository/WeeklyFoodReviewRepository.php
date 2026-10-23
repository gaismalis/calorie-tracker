<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\WeeklyFoodReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WeeklyFoodReview> */
class WeeklyFoodReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeeklyFoodReview::class);
    }

    public function findForWeek(User $user, string $lastDay): ?WeeklyFoodReview
    {
        return $this->findOneBy(['user' => $user, 'lastDay' => $lastDay]);
    }
}
