<?php

namespace App\Export;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Repository\MealEntryRepository;

/**
 * Everything the user has eaten, for download from the profile: day (in the user's timezone) → the foods of that day,
 * oldest first, each with its nutrition. Only estimated meals (the others have no foods yet).
 */
final class FoodExport
{
    public function __construct(private readonly MealEntryRepository $meals)
    {
    }

    /** @return array<string, list<array<string, string|float>>> foods by 'Y-m-d' */
    public function forUser(User $user): array
    {
        $timezone = $user->getDateTimeZone();
        $days = [];
        foreach ($this->meals->findEstimatedForUser($user) as $meal) {
            $eatenAt = $meal->getEatenAt()->setTimezone($timezone);
            foreach ($meal->getItems() as $item) {
                $days[$eatenAt->format('Y-m-d')][] = [
                    'food' => $item->getName(),
                    'meal' => self::mealName($meal),
                    'time' => $eatenAt->format('H:i'),
                    'category' => $item->getCategory()->value,
                    'grams' => round($item->getGrams(), 1),
                    'kcal' => round($item->getKcal(), 1),
                    'protein_g' => round($item->getProtein(), 1),
                    'carbs_g' => round($item->getCarbs(), 1),
                    'fat_g' => round($item->getFat(), 1),
                ];
            }
        }

        return $days;
    }

    /** The meal as it's shown in the log: the recipe or AI name, else what was typed. */
    private static function mealName(MealEntry $meal): string
    {
        return $meal->getTitle() ?? $meal->getRecipeName() ?? $meal->getRawText();
    }
}
