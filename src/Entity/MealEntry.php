<?php

namespace App\Entity;

use App\Estimation\Estimable;
use App\Estimation\EstimationState;
use App\Nutrition\EstimatedItem;
use App\Nutrition\MealEstimate;
use App\Recipe\RecipeItem;
use App\Repository\MealEntryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing the user said they ate ("400g yogurt, 50g jam..."), plus the AI's per-item breakdown.
 * Totals are always recomputed from the items, never taken from the AI.
 *
 * A meal can start from one of the user's recipes: its foods are kept as they are (base items), and the
 * text is only what was added this time ("40 g granola"), which the AI estimates. No text: no AI.
 */
#[ORM\Entity(repositoryClass: MealEntryRepository::class)]
#[ORM\Index(columns: ['user_id', 'eaten_at'])]
class MealEntry implements Estimable
{
    public const TITLE_LENGTH = 60;

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

    /** Short name of the meal (from the AI, or the item names), e.g. "Spaghetti bolognese with salad". */
    #[ORM\Column(length: self::TITLE_LENGTH, nullable: true)]
    private ?string $title = null;

    /** Logged with a photo (the photo itself is only kept until it's estimated, see PendingMealPhoto). */
    #[ORM\Column(options: ['default' => false])]
    private bool $fromPhoto = false;

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

    /** Name of the recipe this meal started from, if any. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $recipeName = null;

    /** @var list<array<string, mixed>>|null RecipeItem::toArray() of the recipe's foods, kept whenever the extras are (re-)estimated */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $baseItems = null;

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

    /** Starts from a recipe: its foods are always part of the meal; the text (if any) is what was added. */
    public function startFromRecipe(Recipe $recipe): void
    {
        $this->recipeName = $recipe->getName();
        $this->baseItems = array_map(fn (RecipeItem $item) => $item->toArray(), $recipe->getItems());
    }

    /** Replaces any items with the recipe's foods (if any) plus the estimate, and marks the entry Estimated. */
    public function applyEstimate(MealEstimate $estimate, \DateTimeImmutable $at): void
    {
        $this->items->clear();
        $base = array_map(fn (array $item) => RecipeItem::fromArray($item)->toEstimatedItem(), $this->baseItems ?? []);
        foreach ([...$base, ...$estimate->items] as $item) {
            $this->items->add(new MealItem(
                $this, $item->name, $item->grams, $item->kcal, $item->protein, $item->carbs, $item->fat, $item->assumption, $item->per100g, $item->category,
            ));
        }
        $this->recalculateTotals();
        $added = implode(', ', array_map(fn (EstimatedItem $item) => $item->name, $estimate->items));
        $this->title = self::shortTitle(null !== $this->recipeName
            ? $this->recipeName.('' === $added ? '' : ' + '.$added)
            : $estimate->mealName ?? $added);
        $this->estimationSucceeded($estimate->estimatedBy, $at);
    }

    public function getRecipeName(): ?string
    {
        return $this->recipeName;
    }

    public function hasBaseItems(): bool
    {
        return [] !== ($this->baseItems ?? []);
    }

    /** Logged from a recipe as it is, without anything added (nothing to save as a new recipe). */
    public function isPlainRecipe(): bool
    {
        return null !== $this->recipeName && '' === trim($this->rawText) && !$this->fromPhoto;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    /** At most TITLE_LENGTH characters, cut with "…" (multibyte-safe); null when empty. */
    public static function shortTitle(string $title): ?string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title));

        return '' === $title ? null : mb_strimwidth($title, 0, self::TITLE_LENGTH, '…');
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

    public function isFromPhoto(): bool
    {
        return $this->fromPhoto;
    }

    public function markFromPhoto(): void
    {
        $this->fromPhoto = true;
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
