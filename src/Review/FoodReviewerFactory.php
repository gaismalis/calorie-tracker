<?php

namespace App\Review;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** Picks the FoodReviewer named by NUTRITION_PROVIDER ("random" or "gemini"). */
final class FoodReviewerFactory
{
    public function __construct(
        #[AutowireLocator([
            RandomFoodReviewer::NAME => RandomFoodReviewer::class,
            'gemini' => GeminiFoodReviewer::class,
        ])]
        private readonly ContainerInterface $providers,
        #[Autowire(env: 'NUTRITION_PROVIDER')]
        private readonly string $provider,
    ) {
    }

    public function create(): FoodReviewer
    {
        if (!$this->providers->has($this->provider)) {
            throw new \InvalidArgumentException(sprintf('Unknown NUTRITION_PROVIDER "%s". Use "random" or "gemini".', $this->provider));
        }

        return $this->providers->get($this->provider);
    }
}
