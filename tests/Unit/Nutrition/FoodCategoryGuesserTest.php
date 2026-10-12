<?php

namespace App\Tests\Unit\Nutrition;

use App\Nutrition\FoodCategory;
use App\Nutrition\FoodCategoryGuesser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FoodCategoryGuesserTest extends TestCase
{
    /** @return iterable<string, array{string, FoodCategory}> */
    public static function names(): iterable
    {
        yield 'beer' => ['Heineken beer 0.5 l', FoodCategory::Alcohol];
        yield 'Latvian beer' => ['Aldaris alus', FoodCategory::Alcohol];
        yield 'wine' => ['glass of red wine', FoodCategory::Alcohol];
        yield 'balsam' => ['Rīgas Melnais balzams', FoodCategory::Alcohol];
        yield 'short word' => ['Gin & tonic', FoodCategory::Alcohol];
        yield 'mixed drink' => ['rum and coke', FoodCategory::Alcohol];
        yield 'alcohol-free beer' => ['alcohol-free beer', FoodCategory::Food];
        yield 'Latvian alcohol-free' => ['bezalkoholiskais alus', FoodCategory::Food];
        yield 'gin inside a word' => ['ginger tea', FoodCategory::Food];
        yield 'rum inside a word' => ['Rumbas', FoodCategory::Food];
        yield 'chocolate bar' => ['Snickers bar', FoodCategory::Snack];
        yield 'Latvian curd snack' => ['Kārums biezpiena sieriņš', FoodCategory::Snack];
        yield 'dessert' => ['tiramisu', FoodCategory::Snack];
        yield 'chips' => ["Lay's chips", FoodCategory::Snack];
        yield 'Latvian ice cream' => ['saldējums', FoodCategory::Snack];
        yield 'meal' => ['chicken caesar salad', FoodCategory::Food];
        yield 'soft drink' => ['Coca-Cola Zero', FoodCategory::Food];
        yield 'pancakes' => ['pancakes with jam', FoodCategory::Food];
    }

    #[DataProvider('names')]
    public function testGuessesFromTheName(string $name, FoodCategory $expected): void
    {
        self::assertSame($expected, FoodCategoryGuesser::guess($name));
    }
}
