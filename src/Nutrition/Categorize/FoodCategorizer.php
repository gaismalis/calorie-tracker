<?php

namespace App\Nutrition\Categorize;

use App\Nutrition\FoodCategory;

/** Categorizes food names after the fact (e.g. items logged before categories existed). */
interface FoodCategorizer
{
    /**
     * @param list<string> $names distinct item names
     *
     * @return array<string, FoodCategory> category by name
     *
     * @throws \RuntimeException when the names couldn't be categorized (try again later)
     */
    public function categorize(array $names): array;
}
