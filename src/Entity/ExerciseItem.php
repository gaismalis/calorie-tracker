<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ExerciseItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ExerciseEntry $entry;

    public function __construct(
        ExerciseEntry $entry,
        #[ORM\Column(length: 255)]
        private string $name,
        /** Null when unknown, e.g. "burned 600 kcal" without a duration. */
        #[ORM\Column(nullable: true)]
        private ?float $minutes,
        /** Burned on top of the baseline (net). */
        #[ORM\Column]
        private float $kcal,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $assumption = null,
    ) {
        $this->entry = $entry;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntry(): ExerciseEntry
    {
        return $this->entry;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMinutes(): ?float
    {
        return $this->minutes;
    }

    public function getKcal(): float
    {
        return $this->kcal;
    }

    /** Call {@see ExerciseEntry::recalculateTotals()} afterwards. */
    public function setKcal(float $kcal): void
    {
        if ($kcal < 0) {
            throw new \InvalidArgumentException('kcal cannot be negative.');
        }
        $this->kcal = $kcal;
    }

    public function getAssumption(): ?string
    {
        return $this->assumption;
    }
}
