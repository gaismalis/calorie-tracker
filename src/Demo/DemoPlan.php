<?php

namespace App\Demo;

use App\Exercise\EstimatedActivity;
use App\Nutrition\EstimatedItem;
use App\Nutrition\FoodCategory;
use App\Nutrition\Per100g;
use App\Recipe\RecipeItem;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The demo account's three months, made up but consistent: what "Alex" ate, the workouts, and weigh-ins that follow
 * from them (energy balance at KCAL_PER_KG, plus some water weight early on and day-to-day noise). Always the DAYS
 * days up to today, so the demo never looks stale; nothing later than $now (today's dinner shows up in the evening).
 *
 * Random but repeatable (fixed SEED): the same plan for the same dates. No database, no AI; DemoAccount stores it
 * and the landing page draws its weight curve.
 */
final class DemoPlan
{
    public const DAYS = 90;
    public const START_KG = 91.4;
    private const SEED = 20261030;
    /** What Alex really burns per day before workouts (the app works this out from the log after a few weeks). */
    private const BURN_KCAL = 2550;
    private const KCAL_PER_KG = 7700;
    /** Kilos of water lost in the first days of eating less (glycogen). */
    private const WATER_KG = 0.7;
    /** Eaten on days that weren't logged (the weight still moves). */
    private const UNLOGGED_KCAL = 2400;

    /** name => [kcal, protein, carbs, fat per 100 g, category] */
    private const FOODS = [
        'Greek yogurt 2%' => [73, 10, 3.6, 2],
        'Honey' => [304, 0.3, 82, 0],
        'Walnuts' => [654, 15, 14, 65],
        'Oatmeal (cooked with water)' => [71, 2.5, 12, 1.5],
        'Banana' => [89, 1.1, 23, 0.3],
        'Fried eggs' => [196, 13.6, 0.8, 15],
        'Rye bread' => [259, 8.5, 48, 3.3],
        'Butter' => [717, 0.9, 0.1, 81],
        'Peanut butter' => [588, 25, 20, 50],
        'Whey protein shake (with water)' => [40, 8, 1, 0.5],
        'Coffee' => [1, 0.1, 0, 0],
        'Milk 2.5%' => [52, 3.3, 4.8, 2.5],
        'Grilled chicken breast' => [165, 31, 0, 3.6],
        'Buckwheat (cooked)' => [92, 3.4, 20, 0.6],
        'Mixed salad with olive oil' => [80, 1.2, 4, 6.5],
        'Chicken caesar wrap' => [230, 13, 22, 10],
        'Vegetable soup' => [45, 1.5, 7, 1.2],
        'Cottage cheese 5%' => [121, 17, 3, 5],
        'Salmon sushi rolls' => [150, 6, 25, 3],
        'Baked salmon' => [208, 20, 0, 13],
        'Boiled potatoes' => [87, 1.9, 20, 0.1],
        'Steamed broccoli' => [35, 2.4, 7, 0.4],
        'Spaghetti bolognese' => [150, 7, 18, 5],
        'Chicken stir-fry' => [120, 11, 6, 6],
        'White rice (cooked)' => [130, 2.7, 28, 0.3],
        'Pelmeni' => [250, 11, 29, 10],
        'Sour cream 20%' => [204, 2.8, 3.2, 20],
        'Pepperoni pizza' => [270, 11, 30, 11.5],
        'Cheeseburger' => [260, 14, 22, 13],
        'French fries' => [312, 3.4, 41, 15],
        'Apple' => [52, 0.3, 14, 0.2],
        'Protein bar' => [360, 33, 33, 12],
        'Dark chocolate 70%' => [598, 7.8, 46, 43, FoodCategory::Snack],
        'Potato chips' => [536, 7, 53, 34, FoodCategory::Snack],
        'Lager beer' => [43, 0.5, 3.6, 0, FoodCategory::Alcohol],
        'Red wine' => [85, 0.1, 2.6, 0, FoodCategory::Alcohol],
    ];

    /** Saved in My recipes; logged by name (as it is, or with extras). */
    private const RECIPES = [
        'Greek yogurt bowl' => ['Greek yogurt 2%' => 200, 'Honey' => 15, 'Walnuts' => 20],
        'Coffee with milk' => ['Coffee' => 200, 'Milk 2.5%' => 60],
    ];

    /** [what was typed, meal name, [food => grams]]; a recipe: [extras typed, recipe name, [extra food => grams]]. */
    private const BREAKFASTS = [
        ['', 'Greek yogurt bowl', [], 'recipe'],
        ['a banana', 'Greek yogurt bowl', ['Banana' => 120], 'recipe'],
        ['oatmeal with a banana and honey', 'Oatmeal with banana', ['Oatmeal (cooked with water)' => 350, 'Banana' => 120, 'Honey' => 10]],
        ['2 fried eggs on rye bread with butter', 'Eggs on rye', ['Fried eggs' => 110, 'Rye bread' => 60, 'Butter' => 8]],
        ['protein shake, banana, rye toast with peanut butter', 'Protein shake and toast', ['Whey protein shake (with water)' => 300, 'Banana' => 120, 'Rye bread' => 40, 'Peanut butter' => 15]],
    ];
    private const LUNCHES = [
        ['grilled chicken with buckwheat and salad', 'Chicken with buckwheat', ['Grilled chicken breast' => 180, 'Buckwheat (cooked)' => 250, 'Mixed salad with olive oil' => 150]],
        ['chicken caesar wrap', 'Chicken caesar wrap', ['Chicken caesar wrap' => 280]],
        ['vegetable soup, 2 slices of rye bread and cottage cheese', 'Soup and cottage cheese', ['Vegetable soup' => 450, 'Rye bread' => 70, 'Cottage cheese 5%' => 150]],
        ['salmon sushi, 12 pieces', 'Salmon sushi', ['Salmon sushi rolls' => 380]],
    ];
    private const DINNERS = [
        ['baked salmon with potatoes and broccoli', 'Salmon with potatoes', ['Baked salmon' => 180, 'Boiled potatoes' => 300, 'Steamed broccoli' => 150]],
        ['spaghetti bolognese', 'Spaghetti bolognese', ['Spaghetti bolognese' => 450]],
        ['chicken stir-fry with rice', 'Chicken stir-fry with rice', ['Chicken stir-fry' => 350, 'White rice (cooked)' => 220]],
        ['pelmeni with sour cream', 'Pelmeni with sour cream', ['Pelmeni' => 300, 'Sour cream 20%' => 30]],
    ];
    private const TREAT_DINNERS = [
        ['half a pepperoni pizza', 'Pepperoni pizza', ['Pepperoni pizza' => 400]],
        ['cheeseburger and fries', 'Cheeseburger and fries', ['Cheeseburger' => 220, 'French fries' => 150]],
    ];
    private const SNACKS = [
        ['an apple', 'Apple', ['Apple' => 180]],
        ['2 squares of dark chocolate', 'Dark chocolate', ['Dark chocolate 70%' => 25]],
        ['protein bar', 'Protein bar', ['Protein bar' => 55]],
        ['cottage cheese', 'Cottage cheese', ['Cottage cheese 5%' => 200]],
    ];

    /** @var list<array{at: \DateTimeImmutable, text: string, name: string, recipe: ?string, items: list<EstimatedItem>}> */
    private array $meals = [];
    /** @var list<array{at: \DateTimeImmutable, text: string, activities: list<EstimatedActivity>}> */
    private array $exercises = [];
    /** @var array<string, float> kg by 'Y-m-d' */
    private array $weights = [];

    /**
     * @param \DateTimeImmutable $today local midnight of the last day (in the demo user's timezone)
     * @param \DateTimeImmutable $now   nothing later than this
     */
    public function __construct(\DateTimeImmutable $today, \DateTimeImmutable $now)
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $burnedOff = 0.0; // kg lost to the calorie deficit so far

        for ($index = 0; $index < self::DAYS; ++$index) {
            $day = $today->modify(sprintf('-%d days', self::DAYS - 1 - $index));
            [$meals, $exercises] = $this->day($random, $day, $index);

            // Weighed in the morning, before the day's food (a few mornings skipped; never the first or today).
            $noise = $random->getFloat(-0.35, 0.35);
            $water = self::WATER_KG * (1 - exp(-$index / 4));
            if (0 === $index || self::DAYS - 1 === $index || $random->getInt(1, 100) > 15) {
                $this->weights[$day->format('Y-m-d')] = round(self::START_KG - $burnedOff - $water + $noise, 1);
            }

            $eaten = [] === $meals ? self::UNLOGGED_KCAL : array_sum(array_map(self::kcal(...), $meals));
            $workouts = array_sum(array_map(fn ($exercise) => array_sum(array_map(fn (EstimatedActivity $activity) => $activity->kcal, $exercise['activities'])), $exercises));
            $burnedOff += (self::BURN_KCAL + $workouts - $eaten) / self::KCAL_PER_KG;

            foreach ($meals as $meal) {
                if ($meal['at'] <= $now) {
                    $this->meals[] = $meal;
                }
            }
            foreach ($exercises as $exercise) {
                if ($exercise['at'] <= $now) {
                    $this->exercises[] = $exercise;
                }
            }
        }
    }

    /** @return list<array{at: \DateTimeImmutable, text: string, name: string, recipe: ?string, items: list<EstimatedItem>}> oldest first */
    public function meals(): array
    {
        return $this->meals;
    }

    /** @return list<array{at: \DateTimeImmutable, text: string, activities: list<EstimatedActivity>}> */
    public function exercises(): array
    {
        return $this->exercises;
    }

    /** @return array<string, float> weigh-ins, kg by local 'Y-m-d', oldest first */
    public function weights(): array
    {
        return $this->weights;
    }

    /** @return array<string, list<RecipeItem>> */
    public function recipes(): array
    {
        $recipes = [];
        foreach (self::RECIPES as $name => $foods) {
            $recipes[$name] = array_map(fn (EstimatedItem $item) => new RecipeItem($item->name, $item->grams, $item->kcal, $item->protein, $item->carbs, $item->fat, $item->category), self::items($foods));
        }

        return $recipes;
    }

    /** @return array{0: list<array{at: \DateTimeImmutable, text: string, name: string, recipe: ?string, items: list<EstimatedItem>}>, 1: list<array{at: \DateTimeImmutable, text: string, activities: list<EstimatedActivity>}>} */
    private function day(Randomizer $random, \DateTimeImmutable $day, int $index): array
    {
        $weekday = (int) $day->format('N'); // 1 = Monday
        $at = fn (int $hour, int $minute) => $day->setTime($hour, $minute + $random->getInt(0, 40));
        $pick = fn (array $options) => $options[$random->getInt(0, count($options) - 1)];

        $meals = [];
        $exercises = [];
        $logged = $index < 3 || $random->getInt(1, 100) > 4; // now and then a day isn't logged
        if ($logged) {
            $meals[] = $this->meal($random, $weekday >= 6 ? $at(9, 30) : $at(7, 20), $pick(self::BREAKFASTS));
            $meals[] = $this->recipeMeal($at(10, 15), 'Coffee with milk');
            $meals[] = $this->meal($random, $at(12, 30), $pick(self::LUNCHES));
            $meals[] = $this->meal($random, $at(15, 40), $pick(self::SNACKS));
            if ($random->getInt(1, 100) <= 30) {
                $meals[] = $this->recipeMeal($at(16, 30), 'Coffee with milk');
            }
            $treat = 5 === $weekday || 6 === $weekday;
            $meals[] = $this->meal($random, $at(19, 0), $pick($treat ? self::TREAT_DINNERS : self::DINNERS));
            if (5 === $weekday) {
                $meals[] = $this->meal($random, $at(21, 0), ['2 beers', '2 beers', ['Lager beer' => 1000]]);
            } elseif (6 === $weekday) {
                $meals[] = $this->meal($random, $at(20, 30), ['a glass of red wine and some chips', 'Wine and chips', ['Red wine' => 150, 'Potato chips' => 50]]);
            }
        }

        // Runs on Monday and Wednesday (now and then skipped), a long walk or the gym on Saturday.
        if ((1 === $weekday || 3 === $weekday) && $random->getInt(1, 100) > 20) {
            $km = $random->getInt(5, 8);
            $exercises[] = ['at' => $at(18, 0), 'text' => sprintf('%d km easy run', $km), 'activities' => [new EstimatedActivity('Running', $km * 6.5, $km * 72.0)]];
        } elseif (6 === $weekday) {
            $exercises[] = 1 === $random->getInt(1, 2)
                ? ['at' => $at(11, 0), 'text' => '1 hour brisk walk', 'activities' => [new EstimatedActivity('Brisk walking', 60, 220)]]
                : ['at' => $at(11, 0), 'text' => 'gym, full body 45 min', 'activities' => [new EstimatedActivity('Strength training', 45, 230)]];
        }

        return [$meals, $exercises];
    }

    /** @param array{0: string, 1: string, 2: array<string, int>, 3?: string} $meal portions vary a little (±10%) */
    private function meal(Randomizer $random, \DateTimeImmutable $at, array $meal): array
    {
        [$text, $name, $foods] = $meal;
        $scale = $random->getFloat(0.9, 1.1);
        $items = self::items(array_map(fn (int $grams) => round($grams * $scale / 5) * 5, $foods));
        if ('recipe' === ($meal[3] ?? null)) {
            return ['at' => $at, 'text' => $text, 'name' => $name, 'recipe' => $name, 'items' => $items]; // items = the extras
        }

        return ['at' => $at, 'text' => $text, 'name' => $name, 'recipe' => null, 'items' => $items];
    }

    private function recipeMeal(\DateTimeImmutable $at, string $recipe): array
    {
        return ['at' => $at, 'text' => '', 'name' => $recipe, 'recipe' => $recipe, 'items' => []];
    }

    /**
     * @param array<string, int|float> $foods food => grams
     *
     * @return list<EstimatedItem>
     */
    private static function items(array $foods): array
    {
        $items = [];
        foreach ($foods as $food => $grams) {
            $values = self::FOODS[$food];
            $items[] = EstimatedItem::fromPer100g($food, $grams, new Per100g($values[0], $values[1], $values[2], $values[3]), category: $values[4] ?? FoodCategory::Food);
        }

        return $items;
    }

    /** A meal's kcal: its items, plus the recipe's foods for a meal from a recipe. */
    public static function kcal(array $meal): float
    {
        $foods = null === $meal['recipe'] ? [] : self::items(self::RECIPES[$meal['recipe']]);

        return array_sum(array_map(fn (EstimatedItem $item) => $item->kcal, [...$foods, ...$meal['items']]));
    }
}
