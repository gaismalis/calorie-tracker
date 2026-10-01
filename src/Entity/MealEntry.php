<?php

namespace App\Entity;

use App\Estimation\Estimable;
use App\Estimation\EstimationState;
use App\Nutrition\MealEstimate;
use App\Repository\MealEntryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing the user said they ate ("400g yogurt, 50g jam..."), plus the AI's per-item breakdown.
 * Totals are always recomputed from the items, never taken from the AI.
 */
#[ORM\Entity(repositoryClass: MealEntryRepository::class)]
#[ORM\Index(columns: ['user_id', 'eaten_at'])]
class MealEntry implements Estimable
{
    use EstimationState;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $rawText;

    #[ORM\Column]
    private \DateTimeImmutable $eatenAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private float $kcal = 0;

    #[ORM\Column]
    private float $protein = 0;

    #[ORM\Column]
    private float $carbs = 0;

    #[ORM\Column]
    private float $fat = 0;

    /** @var Collection<int, MealItem> */
    #[ORM\OneToMany(targetEntity: MealItem::class, mappedBy: 'entry', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $items;

    /** A new entry starts as Pending until {@see applyEstimate()} or {@see estimationFailed()} is called. */
    public function __construct(User $user, string $rawText, \DateTimeImmutable $eatenAt)
    {
        $this->user = $user;
        $this->rawText = $rawText;
        // Doctrine stores the wall-clock time without its timezone, so always normalise to UTC.
        $this->eatenAt = $eatenAt->setTimezone(new \DateTimeZone('UTC'));
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    /** Replaces any items with the estimate and marks the entry Estimated. */
    public function applyEstimate(MealEstimate $estimate, \DateTimeImmutable $at): void
    {
        $this->items->clear();
        foreach ($estimate->items as $item) {
            $this->items->add(new MealItem(
                $this, $item->name, $item->grams, $item->kcal, $item->protein, $item->carbs, $item->fat, $item->assumption,
            ));
        }
        $this->recalculateTotals();
        $this->estimationSucceeded($estimate->estimatedBy, $at);
    }

    public function addItem(MealItem $item): void
    {
        $this->items->add($item);
        $this->recalculateTotals();
    }

    public function recalculateTotals(): void
    {
        $this->kcal = $this->protein = $this->carbs = $this->fat = 0;
        foreach ($this->items as $item) {
            $this->kcal += $item->getKcal();
            $this->protein += $item->getProtein();
            $this->carbs += $item->getCarbs();
            $this->fat += $item->getFat();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRawText(): string
    {
        return $this->rawText;
    }

    public function getEatenAt(): \DateTimeImmutable
    {
        return $this->eatenAt;
    }

    public function setEatenAt(\DateTimeImmutable $eatenAt): void
    {
        $this->eatenAt = $eatenAt->setTimezone(new \DateTimeZone('UTC'));
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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

    /** @return Collection<int, MealItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }
}
