<?php

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Entity\WeightEntry;
use PHPUnit\Framework\TestCase;

class WeightEntryTest extends TestCase
{
    public function testKeepsOnlyTheCalendarDate(): void
    {
        $entry = new WeightEntry(new User(), new \DateTimeImmutable('2026-10-02 01:30', new \DateTimeZone('Europe/Riga')), 80);

        self::assertSame('2026-10-02 00:00 UTC', $entry->getDate()->format('Y-m-d H:i e'), 'not shifted to 1 Oct by timezone conversion');
    }

    public function testWeightIsRoundedToOneDecimal(): void
    {
        $entry = new WeightEntry(new User(), new \DateTimeImmutable(), 80.04);
        self::assertSame(80.0, $entry->getWeightKg());

        $entry->setWeightKg(79.96);
        self::assertSame(80.0, $entry->getWeightKg());
    }
}
