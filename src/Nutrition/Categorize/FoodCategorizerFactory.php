<?php

namespace App\Nutrition\Categorize;

use App\Nutrition\RandomNutritionEstimator;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** Same provider as meal estimates (NUTRITION_PROVIDER): Gemini, or keywords with the fake provider. */
final class FoodCategorizerFactory
{
    public function __construct(
        #[AutowireLocator([
            RandomNutritionEstimator::NAME => KeywordFoodCategorizer::class,
            'gemini' => GeminiFoodCategorizer::class,
        ])]
        private readonly ContainerInterface $categorizers,
        #[Autowire(env: 'NUTRITION_PROVIDER')]
        private readonly string $provider,
    ) {
    }

    public function create(): FoodCategorizer
    {
        if (!$this->categorizers->has($this->provider)) {
            throw new \InvalidArgumentException(sprintf('Unknown NUTRITION_PROVIDER "%s". Use "random" or "gemini".', $this->provider));
        }

        return $this->categorizers->get($this->provider);
    }
}
