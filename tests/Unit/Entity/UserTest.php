<?php

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testNewUsersDefaultToRigaTimezone(): void
    {
        self::assertSame('Europe/Riga', (new User())->getTimezone());
    }

    public function testTodayIsMidnightInTheUsersTimezone(): void
    {
        $user = (new User())->setTimezone('Europe/Riga');

        // 22:30 UTC on 1 Oct is already 01:30 on 2 Oct in Riga (UTC+3 in summer time)
        $today = $user->today(new \DateTimeImmutable('2026-10-01 22:30:00', new \DateTimeZone('UTC')));

        self::assertSame('2026-10-02 00:00:00 Europe/Riga', $today->format('Y-m-d H:i:s e'));
    }

    public function testAgeIsCompletedYears(): void
    {
        $user = (new User())->setBirthDate(new \DateTimeImmutable('1990-10-02'));

        self::assertSame(35, $user->getAge(new \DateTimeImmutable('2026-10-01')));
        self::assertSame(36, $user->getAge(new \DateTimeImmutable('2026-10-02')));
        self::assertNull((new User())->getAge());
    }
}
