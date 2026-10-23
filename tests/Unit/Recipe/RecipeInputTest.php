<?php

namespace App\Tests\Unit\Recipe;

use App\Nutrition\FoodCategory;
use App\Recipe\RecipeInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RecipeInputTest extends TestCase
{
    public function testAValidRecipe(): void
    {
        $input = RecipeInput::parse('  My  yogurt bowl ', [
            ['name' => 'Greek yogurt 2%', 'grams' => '200', 'kcal' => '146', 'protein' => '20', 'carbs' => '8', 'fat' => '4', 'category' => 'food'],
            ['name' => '', 'grams' => '', 'kcal' => '', 'protein' => '', 'carbs' => '', 'fat' => ''], // empty row: ignored
            ['name' => 'Honey', 'grams' => '15', 'kcal' => '46', 'protein' => '0', 'carbs' => '12,4', 'fat' => '0', 'category' => 'snack'],
        ]);

        self::assertTrue($input->isValid());
        self::assertSame('My yogurt bowl', $input->name);
        self::assertCount(2, $input->items);
        self::assertSame(12.4, $input->items[1]->carbs, 'a decimal comma works');
        self::assertSame(FoodCategory::Snack, $input->items[1]->category);
    }

    public function testNameAndAtLeastOneFoodAreNeeded(): void
    {
        $input = RecipeInput::parse('', [['name' => '', 'grams' => '']]);

        self::assertSame(['name' => 'Give the recipe a name.', 'items' => 'Add at least one food.'], $input->errors);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function badRows(): iterable
    {
        $row = ['name' => 'Whey', 'grams' => '30', 'kcal' => '120', 'protein' => '24', 'carbs' => '2', 'fat' => '1.5'];
        yield 'no name' => [['name' => ''] + $row, 'What food is it?'];
        yield 'missing number' => [['fat' => ''] + $row, 'Fill in grams, kcal, protein, carbs and fat (0 is fine).'];
        yield 'negative' => [['carbs' => '-1'] + $row, 'Fill in grams, kcal, protein, carbs and fat (0 is fine).'];
        yield 'no grams' => [['grams' => '0'] + $row, 'Grams between 1 and 5000.'];
        yield 'too many kcal for the grams' => [['grams' => '3'] + $row, "120 kcal in 3 g isn't possible (at most 900 kcal per 100 g). Check the grams."];
        yield 'more protein than grams' => [['protein' => '40'] + $row, "40 g of protein in 30 g isn't possible. Check the grams."];
    }

    /** @param array<string, string> $row */
    #[DataProvider('badRows')]
    public function testEachFoodIsChecked(array $row, string $error): void
    {
        $input = RecipeInput::parse('Shake', [3 => $row]);

        self::assertSame(['3' => $error], $input->errors, 'keyed by the row, to show it there');
    }

    public function testAtMost30Foods(): void
    {
        $row = ['name' => 'Coffee', 'grams' => '200', 'kcal' => '2', 'protein' => '0.2', 'carbs' => '0', 'fat' => '0'];

        self::assertSame(['items' => 'At most 30 foods per recipe.'], RecipeInput::parse('Lots', array_fill(0, 31, $row))->errors);
    }
}
