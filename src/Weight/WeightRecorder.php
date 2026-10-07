<?php

namespace App\Weight;

use App\Entity\User;
use App\Entity\WeightEntry;
use App\Repository\WeightEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Saves a weigh-in: one per user per calendar day, logging the same day again replaces it. */
final class WeightRecorder
{
    public const MIN_KG = 20;
    public const MAX_KG = 400;

    public function __construct(
        private readonly WeightEntryRepository $weights,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param \DateTimeImmutable $date the calendar date (only Y-m-d is used) */
    public function record(User $user, \DateTimeImmutable $date, float $weightKg): WeightEntry
    {
        $entry = $this->weights->findForDate($user, $date);
        if ($entry) {
            $entry->setWeightKg($weightKg);
        } else {
            $entry = new WeightEntry($user, $date, $weightKg);
            $this->entityManager->persist($entry);
        }
        $this->entityManager->flush();

        return $entry;
    }
}
