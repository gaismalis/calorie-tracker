<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class MealItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MealEntry $entry;

    public function __construct(
        MealEntry $entry,
        #[ORM\Column(length: 255)]
        private string $name,
        #[ORM\Column]
        private float $grams,
        #[ORM\Column]
        private float $kcal,
        #[ORM\Column]
        private float $protein,
        #[ORM\Column]
        private float $carbs,
        #[ORM\Column]
        private float $fat,
        /** What the AI had to guess, e.g. "big tablespoon ≈ 20 g". */
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $assumption = null,
    ) {
        $this->entry = $entry;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntry(): MealEntry
    {
        return $this->entry;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getGrams(): float
    {
        return $this->grams;
    }

    public function getKcal(): float
    {
        return $this->kcal;
    }

    public function getProtein(): float
    {
        return $this->protein;
    }

    public function getCarbs(): float
    {
        return $this->carbs;
    }

    public function getFat(): float
    {
        return $this->fat;
    }

    public function getAssumption(): ?string
    {
        return $this->assumption;
    }

    /**
     * Changes the amount and scales kcal and macros proportionally (same food, different portion).
     * Call {@see MealEntry::recalculateTotals()} afterwards.
     */
    public function changeGrams(float $grams): void
    {
        if ($grams < 0) {
            throw new \InvalidArgumentException('Grams cannot be negative.');
        }
        if ($this->grams > 0) {
            $factor = $grams / $this->grams;
            $this->kcal *= $factor;
            $this->protein *= $factor;
            $this->carbs *= $factor;
            $this->fat *= $factor;
        }
        $this->grams = $grams;
    }
}
