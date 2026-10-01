<?php

namespace App\Nutrition;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** Picks the NutritionEstimator implementation named by the NUTRITION_PROVIDER env var. */
final class NutritionEstimatorFactory
{
    public function __construct(
        #[AutowireLocator([
            RandomNutritionEstimator::NAME => RandomNutritionEstimator::class,
            'gemini' => GeminiNutritionEstimator::class,
        ])]
        private readonly ContainerInterface $providers,
        #[Autowire(env: 'NUTRITION_PROVIDER')]
        private readonly string $provider,
    ) {
    }

    public function create(): NutritionEstimator
    {
        if (!$this->providers->has($this->provider)) {
            throw new \InvalidArgumentException(sprintf('Unknown NUTRITION_PROVIDER "%s". Use "random" or "gemini".', $this->provider));
        }

        return $this->providers->get($this->provider);
    }
}
