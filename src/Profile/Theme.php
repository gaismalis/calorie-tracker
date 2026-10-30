<?php

namespace App\Profile;

/**
 * The app's colour theme, picked in the profile. Every colour in the CSS comes from the theme's variables
 * (assets/styles/themes.css, <html data-theme="…">).
 */
enum Theme: string
{
    case Garden = 'garden';
    case Olive = 'olive';
    case Beach = 'beach';
    case Plum = 'plum';
    case Blossom = 'blossom';

    public function label(): string
    {
        return match ($this) {
            self::Garden => 'Garden',
            self::Olive => 'Olive',
            self::Beach => 'Beach',
            self::Plum => 'Plum',
            self::Blossom => 'Blossom',
        };
    }

    /** @return list<string> the colours shown in the picker: main colour, "ate more", page background */
    public function swatches(): array
    {
        return match ($this) {
            self::Garden => ['#2f855a', '#e9a85a', '#f7f3ee'],
            self::Olive => ['#606c38', '#dda15e', '#fefae0'],
            self::Beach => ['#2a9d8f', '#f4a261', '#264653'],
            self::Plum => ['#9d5c63', '#e4bb97', '#d6e3f8'],
            self::Blossom => ['#d4607c', '#f2b66d', '#ffced8'],
        };
    }

    /** Browser/phone toolbar colour (meta theme-color): the main colour. */
    public function browserColor(): string
    {
        return $this->swatches()[0];
    }
}
