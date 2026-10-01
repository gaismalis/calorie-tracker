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
            self::Sedentary => 'Mostly sitting (desk job, little walking)',
            self::Light => 'Lightly active (some walking or standing)',
            self::Moderate => 'Active (on your feet most of the day)',
            self::Active => 'Very active (physical job)',
            self::VeryActive => 'Extremely active (heavy physical work all day)',
        };
    }
}
