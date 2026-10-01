<?php

namespace App\Tests\Unit\Entity;

use App\Entity\MealEntry;
use App\Entity\MealItem;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class MealEntryTest extends TestCase
{
    public function testEatenAtIsStoredInUtc(): void
    {
        $entry = new MealEntry(new User(), 'x', new \DateTimeImmutable('2026-10-02 01:30', new \DateTimeZone('Europe/Riga')), 'test');

        self::assertSame('2026-10-01 22:30 UTC', $entry->getEatenAt()->format('Y-m-d H:i e'));
    }

    public function testTotalsAreSummedFromItems(): void
    {
        $entry = new MealEntry(new User(), 'x', new \DateTimeImmutable(), 'test');
        $entry->addItem(new MealItem($entry, 'a', 100, 150, 10, 20, 3));
        $entry->addItem(new MealItem($entry, 'b', 50, 50.5, 1.5, 2, 4));

        self::assertSame(200.5, $entry->getKcal());
        self::assertSame(11.5, $entry->getProtein());
        self::assertSame(22.0, $entry->getCarbs());
        self::assertSame(7.0, $entry->getFat());
    }
}
