<?php

namespace App\Entity;

use App\Nutrition\FoodCategory;
use App\Nutrition\Per100g;
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

    /** Values per 100 g the totals came from; null for items logged before these were stored. */
    #[ORM\Column(nullable: true)]
    private ?float $kcalPer100g = null;
    #[ORM\Column(nullable: true)]
    private ?float $proteinPer100g = null;
    #[ORM\Column(nullable: true)]
    private ?float $carbsPer100g = null;
    #[ORM\Column(nullable: true)]
    private ?float $fatPer100g = null;

    /** Food, snack or alcohol; null for items logged before categories existed, until they're backfilled. */
    #[ORM\Column(length: 16, nullable: true, enumType: FoodCategory::class)]
    private ?FoodCategory $category = null;

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
        ?Per100g $per100g = null,
        ?FoodCategory $category = null,
    ) {
        $this->entry = $entry;
        $this->category = $category;
        if (null !== $per100g) {
            $this->kcalPer100g = $per100g->kcal;
            $this->proteinPer100g = $per100g->protein;
            $this->carbsPer100g = $per100g->carbs;
            $this->fatPer100g = $per100g->fat;
        }
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

    /** Not categorized yet (older items) counts as food. */
    public function getCategory(): FoodCategory
    {
        return $this->category ?? FoodCategory::Food;
    }

    public function isCategorized(): bool
    {
        return null !== $this->category;
    }

    public function setCategory(FoodCategory $category): void
    {
        $this->category = $category;
    }

    /** Per-100 g values: as estimated, or (older items) worked out from the totals; null without a weight. */
    public function getPer100g(): ?Per100g
    {
        if (null !== $this->kcalPer100g) {
            return new Per100g($this->kcalPer100g, $this->proteinPer100g, $this->carbsPer100g, $this->fatPer100g);
        }
        if ($this->grams <= 0) {
            return null;
        }
        $per = fn (float $total) => round($total / $this->grams * 100, 1);

        return new Per100g($per($this->kcal), $per($this->protein), $per($this->carbs), $per($this->fat));
    }

    /**
     * Corrects the nutrition values (e.g. from the product label) and recalculates the totals for the
     * current amount. Call {@see MealEntry::recalculateTotals()} afterwards.
     */
    public function changePer100g(Per100g $per100g): void
    {
        $this->kcalPer100g = $per100g->kcal;
        $this->proteinPer100g = $per100g->protein;
        $this->carbsPer100g = $per100g->carbs;
        $this->fatPer100g = $per100g->fat;
        ['kcal' => $this->kcal, 'protein' => $this->protein, 'carbs' => $this->carbs, 'fat' => $this->fat] = $per100g->forGrams($this->grams);
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
        if (null !== $this->kcalPer100g) {
            ['kcal' => $this->kcal, 'protein' => $this->protein, 'carbs' => $this->carbs, 'fat' => $this->fat] = $this->getPer100g()->forGrams($grams);
        } elseif ($this->grams > 0) {
            $factor = $grams / $this->grams;
            $this->kcal *= $factor;
            $this->protein *= $factor;
            $this->carbs *= $factor;
            $this->fat *= $factor;
        }
        $this->grams = $grams;
    }
}
