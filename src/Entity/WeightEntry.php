<?php

namespace App\Entity;

use App\Repository\WeightEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/** The user's body weight on one calendar day (in their timezone). One entry per day; logging again replaces it. */
#[ORM\Entity(repositoryClass: WeightEntryRepository::class)]
#[ORM\UniqueConstraint(columns: ['user_id', 'date'])]
class WeightEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column]
    private float $weightKg;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, \DateTimeImmutable $date, float $weightKg)
    {
        $this->user = $user;
        $this->date = self::dateOnly($date);
        $this->setWeightKg($weightKg);
    }

    /** Keeps only the calendar date, so a time or timezone on the input can't shift it to another day. */
    private static function dateOnly(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getWeightKg(): float
    {
        return $this->weightKg;
    }

    public function setWeightKg(float $weightKg): void
    {
        $this->weightKg = round($weightKg, 1);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
