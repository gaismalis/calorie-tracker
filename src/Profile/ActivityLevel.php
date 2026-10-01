<?php

namespace App\Profile;

/** Standard multipliers applied to BMR to estimate total daily energy expenditure. */
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
            self::Sedentary => 'Sedentary (desk job, little exercise)',
            self::Light => 'Light (exercise 1–3 days/week)',
            self::Moderate => 'Moderate (exercise 3–5 days/week)',
            self::Active => 'Active (exercise 6–7 days/week)',
            self::VeryActive => 'Very active (physical job or training twice a day)',
        };
    }
}
