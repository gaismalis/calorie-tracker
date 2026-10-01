<?php

namespace App\Tests\Unit\Energy;

use App\Energy\EnergyCalculator;
use App\Entity\User;
use App\Profile\ActivityLevel;
use App\Profile\Sex;
use PHPUnit\Framework\TestCase;

class EnergyCalculatorTest extends TestCase
{
    private const ON = '2026-10-01';

    public function testMaleMifflinStJeor(): void
    {
        // 10×80 + 6.25×180 − 5×30 + 5 = 1780; × 1.55 = 2759
        $estimate = EnergyCalculator::calculate($this->user(Sex::Male, '1996-01-01', 180, ActivityLevel::Moderate), 80, new \DateTimeImmutable(self::ON));

        self::assertSame(1780.0, $estimate->bmr);
        self::assertSame(2759.0, $estimate->formulaTdee);
        self::assertTrue($estimate->isComplete());
        self::assertSame([], $estimate->missing);
    }

    public function testFemaleMifflinStJeor(): void
    {
        // 10×65 + 6.25×165 − 5×40 − 161 = 1320.25 → 1320; × 1.2 = 1584
        $estimate = EnergyCalculator::calculate($this->user(Sex::Female, '1986-06-15', 165, ActivityLevel::Sedentary), 65, new \DateTimeImmutable(self::ON));

        self::assertSame(1320.0, $estimate->bmr);
        self::assertSame(1584.0, $estimate->formulaTdee);
    }

    public function testAllActivityMultipliers(): void
    {
        $expected = ['sedentary' => 2136.0, 'light' => 2448.0, 'moderate' => 2759.0, 'active' => 3071.0, 'very_active' => 3382.0];

        foreach (ActivityLevel::cases() as $level) {
            $estimate = EnergyCalculator::calculate($this->user(Sex::Male, '1996-01-01', 180, $level), 80, new \DateTimeImmutable(self::ON));
            self::assertSame($expected[$level->value], $estimate->formulaTdee, $level->value);
        }
    }

    public function testWithoutActivityLevelOnlyBmrIsKnown(): void
    {
        $estimate = EnergyCalculator::calculate($this->user(Sex::Male, '1996-01-01', 180, null), 80, new \DateTimeImmutable(self::ON));

        self::assertSame(1780.0, $estimate->bmr);
        self::assertNull($estimate->formulaTdee);
        self::assertFalse($estimate->isComplete());
        self::assertSame(['activity level'], $estimate->missing);
    }

    public function testListsEverythingMissing(): void
    {
        $estimate = EnergyCalculator::calculate(new User(), null);

        self::assertNull($estimate->bmr);
        self::assertSame(['sex', 'birth date', 'height', 'activity level', 'weight'], $estimate->missing);
    }

    private function user(Sex $sex, string $birthDate, float $heightCm, ?ActivityLevel $activity): User
    {
        return (new User())->setSex($sex)->setBirthDate(new \DateTimeImmutable($birthDate))->setHeightCm($heightCm)->setActivityLevel($activity);
    }
}
