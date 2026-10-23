<?php

namespace App\Entity;

use App\Nutrition\MealEstimate;
use App\Recipe\RecipeItem;
use App\Repository\RecipeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A meal the user eats often, with their own exact numbers ("My recipes"). Logging it makes a meal with
 * these items straight away, without asking the AI.
 */
#[ORM\Entity(repositoryClass: RecipeRepository::class)]
class Recipe
{
    /** What meals logged from a recipe are "estimated by". */
    public const ESTIMATED_BY = 'recipe';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    private string $name;

    /** @var list<array<string, mixed>> RecipeItem::toArray() of each food */
    #[ORM\Column(type: Types::JSON)]
    private array $items = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @param list<RecipeItem> $items */
    public function __construct(User $user, string $name, array $items)
    {
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->update($name, $items);
    }

    /** @param list<RecipeItem> $items */
    public function update(string $name, array $items): void
    {
        $this->name = $name;
        $this->items = array_map(fn (RecipeItem $item) => $item->toArray(), $items);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<RecipeItem> */
    public function getItems(): array
    {
        return array_map(fn (array $item) => RecipeItem::fromArray($item), $this->items);
    }

    public function getKcal(): float
    {
        return $this->sum('kcal');
    }

    public function getProtein(): float
    {
        return $this->sum('protein');
    }

    public function getCarbs(): float
    {
        return $this->sum('carbs');
    }

    public function getFat(): float
    {
        return $this->sum('fat');
    }

    /** The meal it makes when logged. */
    public function toMealEstimate(): MealEstimate
    {
        return new MealEstimate(array_map(fn (RecipeItem $item) => $item->toEstimatedItem(), $this->getItems()), self::ESTIMATED_BY, $this->name);
    }

    private function sum(string $field): float
    {
        return round(array_sum(array_column($this->items, $field)), 1);
    }
}
