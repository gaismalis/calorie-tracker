<?php

namespace App\Recipe;

use App\Nutrition\FoodCategory;
use App\Nutrition\Per100g;

/**
 * Checks the recipe form: a name and at least one food, each with grams, kcal, protein, carbs and fat for
 * that amount. The numbers must be physically possible for the grams (at most 900 kcal and 100 g of each
 * macro per 100 g), which catches most typos. Rows left completely empty are ignored.
 */
final readonly class RecipeInput
{
    public const MAX_NAME_LENGTH = 100;
    public const MAX_ITEM_NAME_LENGTH = 255;
    public const MAX_ITEMS = 30;
    public const MAX_GRAMS = 5000;
    private const NUMBERS = ['grams', 'kcal', 'protein', 'carbs', 'fat'];

    /**
     * @param list<RecipeItem>      $items
     * @param array<string, string> $errors by field: 'name', 'items', or the row index
     */
    private function __construct(
        public string $name,
        public array $items,
        public array $errors,
    ) {
    }

    /** @param array<mixed> $rows the submitted items[i][name|grams|kcal|protein|carbs|fat|category] */
    public static function parse(string $name, array $rows): self
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $errors = [];
        if ('' === $name) {
            $errors['name'] = 'Give the recipe a name.';
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = sprintf('Please keep the name under %d characters.', self::MAX_NAME_LENGTH);
        }

        $items = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = array_map(fn ($value) => is_scalar($value) ? trim((string) $value) : '', $row);
            $itemName = trim(preg_replace('/\s+/u', ' ', $row['name'] ?? ''));
            if ('' === $itemName && [] === array_filter(self::NUMBERS, fn (string $field) => '' !== ($row[$field] ?? ''))) {
                continue; // an empty row
            }

            $error = self::check($itemName, $row, $numbers);
            if (null !== $error) {
                $errors[(string) $index] = $error;
                continue;
            }
            $items[] = new RecipeItem($itemName, $numbers['grams'], $numbers['kcal'], $numbers['protein'], $numbers['carbs'], $numbers['fat'],
                FoodCategory::tryFrom($row['category'] ?? '') ?? FoodCategory::Food);
        }

        if ([] === $items && !array_filter(array_keys($errors), fn ($key) => 'name' !== $key)) {
            $errors['items'] = 'Add at least one food.';
        } elseif (count($items) > self::MAX_ITEMS) {
            $errors['items'] = sprintf('At most %d foods per recipe.', self::MAX_ITEMS);
        }

        return new self($name, $items, $errors);
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }

    /**
     * @param array<string, string>      $row
     * @param array<string, float>|null  $numbers set to the parsed numbers when valid
     */
    private static function check(string $name, array $row, ?array &$numbers): ?string
    {
        if ('' === $name) {
            return 'What food is it?';
        }
        if (mb_strlen($name) > self::MAX_ITEM_NAME_LENGTH) {
            return sprintf('Please keep the food name under %d characters.', self::MAX_ITEM_NAME_LENGTH);
        }

        $numbers = [];
        foreach (self::NUMBERS as $field) {
            $value = str_replace(',', '.', $row[$field] ?? '');
            if (!is_numeric($value) || (float) $value < 0) {
                return 'Fill in grams, kcal, protein, carbs and fat (0 is fine).';
            }
            $numbers[$field] = round((float) $value, 1);
        }

        $grams = $numbers['grams'];
        if ($grams <= 0 || $grams > self::MAX_GRAMS) {
            return sprintf('Grams between 1 and %d.', self::MAX_GRAMS);
        }
        if ($numbers['kcal'] > $grams * Per100g::MAX_KCAL / 100) {
            return sprintf("%s kcal in %s g isn't possible (at most %d kcal per 100 g). Check the grams.", self::format($numbers['kcal']), self::format($grams), Per100g::MAX_KCAL);
        }
        foreach (['protein', 'carbs', 'fat'] as $macro) {
            if ($numbers[$macro] > $grams) {
                return sprintf("%s g of %s in %s g isn't possible. Check the grams.", self::format($numbers[$macro]), $macro, self::format($grams));
            }
        }

        return null;
    }

    private static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
