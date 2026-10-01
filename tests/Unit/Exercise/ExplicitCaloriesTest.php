<?php

namespace App\Tests\Unit\Exercise;

use App\Exercise\ExplicitCalories;
use PHPUnit\Framework\TestCase;

class ExplicitCaloriesTest extends TestCase
{
    /** @return iterable<string, array{string, float|null}> */
    public static function texts(): iterable
    {
        yield 'kcal' => ['I had a workout and burned 600kcal', 600.0];
        yield 'with space' => ['gym, 450 kcal', 450.0];
        yield 'calories' => ['Watch says 512 calories', 512.0];
        yield 'cal' => ['spin class 380 cal', 380.0];
        yield 'decimal comma' => ['walk 120,5 kcal', 120.5];
        yield 'Latvian' => ['treniņš 500 kalorijas', 500.0];
        yield 'no number' => ['played basketball for 2 hours', null];
        yield 'duration is not calories' => ['ran 5 km in 30 min', null];
        yield 'two numbers is ambiguous' => ['run 300 kcal and swim 200 kcal', null];
        yield 'thousands separator dot' => ['burned 1.200 kcal', 1200.0];
        yield 'thousands separator comma' => ['marathon 2,650 kcal', 2650.0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('texts')]
    public function testFind(string $text, ?float $expected): void
    {
        self::assertSame($expected, ExplicitCalories::find($text));
    }
}
