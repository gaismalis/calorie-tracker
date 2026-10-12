<?php

namespace App\Repository;

use App\Entity\MealEntry;
use App\Entity\PendingMealPhoto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PendingMealPhoto> */
class PendingMealPhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingMealPhoto::class);
    }

    public function findForEntry(MealEntry $entry): ?PendingMealPhoto
    {
        return $this->findOneBy(['entry' => $entry]);
    }
}
