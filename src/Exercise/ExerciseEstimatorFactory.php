<?php

namespace App\Exercise;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** Picks the ExerciseEstimator named by NUTRITION_PROVIDER (the same AI provider as for meals). */
final class ExerciseEstimatorFactory
{
    public function __construct(
        #[AutowireLocator([
            RandomExerciseEstimator::NAME => RandomExerciseEstimator::class,
            'gemini' => GeminiExerciseEstimator::class,
        ])]
        private readonly ContainerInterface $providers,
        #[Autowire(env: 'NUTRITION_PROVIDER')]
        private readonly string $provider,
    ) {
    }

    public function create(): ExerciseEstimator
    {
        if (!$this->providers->has($this->provider)) {
            throw new \InvalidArgumentException(sprintf('Unknown NUTRITION_PROVIDER "%s". Use "random" or "gemini".', $this->provider));
        }

        return $this->providers->get($this->provider);
    }
}
