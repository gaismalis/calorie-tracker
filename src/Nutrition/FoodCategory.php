<?php

namespace App\Nutrition;

/** What kind of food an item is, so alcohol and snacks can be shown separately (e.g. on the chart). */
enum FoodCategory: string
{
    /** Everything that's a meal or ordinary food or drink: home cooking, fast food, bread, fruit, coffee, juice. */
    case Food = 'food';
    /** Sweets, desserts and snack foods: chocolate, candy, cookies, cake, ice cream, tiramisu, chips. */
    case Snack = 'snack';
    /** Any alcoholic drink: beer, wine, spirits, cider, cocktails. */
    case Alcohol = 'alcohol';

    public function label(): string
    {
        return match ($this) {
            self::Food => 'Food',
            self::Snack => 'Snack',
            self::Alcohol => 'Alcohol',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Food => '🍽',
            self::Snack => '🍫',
            self::Alcohol => '🍷',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
