<?php

namespace App\Repository;

use App\Entity\Recipe;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Recipe> */
class RecipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recipe::class);
    }

    /** @return list<Recipe> the user's recipes, by name */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('LOWER(r.name)', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }
}
