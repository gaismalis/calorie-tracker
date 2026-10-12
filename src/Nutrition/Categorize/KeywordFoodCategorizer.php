<?php

namespace App\Nutrition\Categorize;

use App\Nutrition\FoodCategoryGuesser;

/** No AI: keyword lists (used with the fake "random" provider and in tests). */
final class KeywordFoodCategorizer implements FoodCategorizer
{
    public function categorize(array $names): array
    {
        $categories = [];
        foreach ($names as $name) {
            $categories[$name] = FoodCategoryGuesser::guess($name);
        }

        return $categories;
    }
}
