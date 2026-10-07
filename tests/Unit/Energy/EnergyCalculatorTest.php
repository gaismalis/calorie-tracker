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

    public function testMissingActivityLevelDefaultsToMostlySitting(): void
    {
        $estimate = EnergyCalculator::calculate($this->user(Sex::Male, '1996-01-01', 180, null), 80, new \DateTimeImmutable(self::ON));

        self::assertSame(1780.0, $estimate->bmr);
        self::assertSame(2136.0, $estimate->formulaTdee, '1780 × 1.2');
        self::assertTrue($estimate->isComplete());
        self::assertSame(['activity level'], $estimate->missing);
    }

    public function testWeightAloneGivesARoughEstimateWithDefaults(): void
    {
        // 10×80 + 6.25×170 − 5×35 − 78 (between male +5 and female −161) = 1609.5 → 1610; × 1.2 = 1932
        $estimate = EnergyCalculator::calculate(new User(), 80);

        self::assertSame(1610.0, $estimate->bmr);
        self::assertSame(1932.0, $estimate->formulaTdee);
        self::assertSame(['sex', 'birth date', 'height', 'activity level'], $estimate->missing);
        self::assertTrue($estimate->isRough());
    }

    public function testEachProfileDetailReplacesItsDefault(): void
    {
        $user = (new User())->setHeightCm(190);

        // 10×80 + 6.25×190 − 5×35 − 78 = 1734.5 → 1735 (PHP rounds half up)
        self::assertSame(1735.0, EnergyCalculator::calculate($user, 80)->bmr);
        self::assertSame(1818.0, EnergyCalculator::calculate($user->setSex(Sex::Male), 80)->bmr, 'male constant +5 instead of −78');
    }

    public function testNoEstimateWithoutAnyWeight(): void
    {
        $estimate = EnergyCalculator::calculate(new User(), null);

        self::assertNull($estimate->bmr);
        self::assertNull($estimate->formulaTdee);
        self::assertFalse($estimate->isComplete());
        self::assertSame(['sex', 'birth date', 'height', 'activity level', 'weight'], $estimate->missing);
    }

    private function user(Sex $sex, string $birthDate, float $heightCm, ?ActivityLevel $activity): User
    {
        return (new User())->setSex($sex)->setBirthDate(new \DateTimeImmutable($birthDate))->setHeightCm($heightCm)->setActivityLevel($activity);
    }
}
