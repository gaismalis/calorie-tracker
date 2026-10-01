<?php

namespace App\Tests\Unit\Nutrition;

use App\Nutrition\NutritionEstimatorFactory;
use App\Nutrition\RandomNutritionEstimator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

class NutritionEstimatorFactoryTest extends TestCase
{
    public function testReturnsConfiguredProvider(): void
    {
        $random = new RandomNutritionEstimator();
        $factory = new NutritionEstimatorFactory(new ServiceLocator(['random' => fn () => $random]), 'random');

        self::assertSame($random, $factory->create());
    }

    public function testUnknownProviderFailsWithHelpfulMessage(): void
    {
        $factory = new NutritionEstimatorFactory(new ServiceLocator([]), 'openai');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown NUTRITION_PROVIDER "openai"');

        $factory->create();
    }
}
