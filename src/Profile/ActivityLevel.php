<?php

namespace App\Profile;

/**
 * Everyday activity WITHOUT workouts: workouts are logged as exercise and added on top. Standard
 * multipliers applied to BMR.
 */
enum ActivityLevel: string
{
    case Sedentary = 'sedentary';
    case Light = 'light';
    case Moderate = 'moderate';
    case Active = 'active';
    case VeryActive = 'very_active';

    public function multiplier(): float
    {
        return match ($this) {
            self::Sedentary => 1.2,
            self::Light => 1.375,
            self::Moderate => 1.55,
            self::Active => 1.725,
            self::VeryActive => 1.9,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Sedentary => 'Mostly sitting',
            self::Light => 'Lightly active',
            self::Moderate => 'Active',
            self::Active => 'Very active',
            self::VeryActive => 'Extremely active',
        };
    }

    /** What a normal day looks like at this level, workouts not included. */
    public function description(): string
    {
        return match ($this) {
            self::Sedentary => 'Desk job, getting around by car or public transport, little walking.',
            self::Light => 'Some walking or standing during the day: errands, short walks, light housework.',
            self::Moderate => 'On your feet much of the day, or walking or cycling to get around (e.g. commuting by bike).',
            self::Active => 'Physical job: e.g. waiter, nurse, warehouse, construction.',
            self::VeryActive => 'Heavy physical work all day: e.g. farm work, moving, forestry.',
        };
    }
}
