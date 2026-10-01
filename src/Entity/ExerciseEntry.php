<?php

namespace App\Entity;

use App\Estimation\Estimable;
use App\Estimation\EstimationState;
use App\Exercise\ExerciseEstimate;
use App\Repository\ExerciseEntryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing the user said they did ("basketball for 2 hours", "workout, burned 600 kcal") and the
 * calories it burned on top of their baseline. Totals are always summed from the items.
 */
#[ORM\Entity(repositoryClass: ExerciseEntryRepository::class)]
#[ORM\Index(columns: ['user_id', 'performed_at'])]
class ExerciseEntry implements Estimable
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
    private \DateTimeImmutable $performedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private float $kcal = 0;

    /** @var Collection<int, ExerciseItem> */
    #[ORM\OneToMany(targetEntity: ExerciseItem::class, mappedBy: 'entry', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $items;

    public function __construct(User $user, string $rawText, \DateTimeImmutable $performedAt)
    {
        $this->user = $user;
        $this->rawText = $rawText;
        $this->setPerformedAt($performedAt);
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    /** Replaces any items with the estimate and marks the entry Estimated. */
    public function applyEstimate(ExerciseEstimate $estimate, \DateTimeImmutable $at): void
    {
        $this->items->clear();
        foreach ($estimate->items as $activity) {
            $this->items->add(new ExerciseItem($this, $activity->name, $activity->minutes, $activity->kcal, $activity->assumption));
        }
        $this->recalculateTotals();
        $this->estimationSucceeded($estimate->estimatedBy, $at);
    }

    public function recalculateTotals(): void
    {
        $this->kcal = 0;
        foreach ($this->items as $item) {
            $this->kcal += $item->getKcal();
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

    public function getPerformedAt(): \DateTimeImmutable
    {
        return $this->performedAt;
    }

    public function setPerformedAt(\DateTimeImmutable $performedAt): void
    {
        // Doctrine stores the wall-clock time without its timezone, so always normalise to UTC.
        $this->performedAt = $performedAt->setTimezone(new \DateTimeZone('UTC'));
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getKcal(): float
    {
        return $this->kcal;
    }

    /** @return Collection<int, ExerciseItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }
}
