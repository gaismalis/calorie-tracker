<?php

namespace App\Entity;

use App\Repository\WeeklyFoodReviewRepository;
use App\Review\FoodReview;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The stored AI review of one user's food from $firstDay to $lastDay (7 days; 6 for the latest week, which
 * leaves out today). Reused while the food log it
 * was made from (its hash) is unchanged, so the AI is asked again only when meals in that week change.
 */
#[ORM\Entity(repositoryClass: WeeklyFoodReviewRepository::class)]
#[ORM\UniqueConstraint(name: 'weekly_food_review_user_days', columns: ['user_id', 'first_day', 'last_day'])]
class WeeklyFoodReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** First day of the week ('Y-m-d', the user's local date). */
    #[ORM\Column(length: 10)]
    private string $firstDay;

    /** Last day of the week ('Y-m-d', the user's local date). */
    #[ORM\Column(length: 10)]
    private string $lastDay;

    #[ORM\Column(length: 64)]
    private string $logHash;

    /** @var array<mixed> FoodReview::toArray() */
    #[ORM\Column(type: Types::JSON)]
    private array $review;

    #[ORM\Column(length: 100)]
    private string $reviewedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $firstDay, string $lastDay)
    {
        $this->user = $user;
        $this->firstDay = $firstDay;
        $this->lastDay = $lastDay;
    }

    public function isFor(string $logHash): bool
    {
        return $this->logHash === $logHash;
    }

    public function update(string $logHash, FoodReview $review, string $reviewedBy, \DateTimeImmutable $now): void
    {
        $this->logHash = $logHash;
        $this->review = $review->toArray();
        $this->reviewedBy = $reviewedBy;
        $this->createdAt = $now;
    }

    public function getReview(): FoodReview
    {
        return FoodReview::fromArray($this->review);
    }

    public function getReviewedBy(): string
    {
        return $this->reviewedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
