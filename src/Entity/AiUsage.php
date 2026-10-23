<?php

namespace App\Entity;

use App\Repository\AiUsageRepository;
use Doctrine\ORM\Mapping as ORM;

/** How many AI requests of one kind (e.g. "food_review") a user made on one local day, for daily caps. */
#[ORM\Entity(repositoryClass: AiUsageRepository::class)]
#[ORM\UniqueConstraint(name: 'ai_usage_user_feature_day', columns: ['user_id', 'feature', 'day'])]
class AiUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $count = 0;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 32)]
        private string $feature,
        /** The user's local date ('Y-m-d'). */
        #[ORM\Column(length: 10)]
        private string $day,
    ) {
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function increment(): void
    {
        ++$this->count;
    }
}
