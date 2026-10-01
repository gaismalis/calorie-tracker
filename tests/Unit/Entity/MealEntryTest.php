<?php

namespace App\Tests\Unit\Entity;

use App\Entity\MealEntry;
use App\Entity\MealItem;
use App\Entity\User;
use App\Estimation\EstimationStatus;
use App\Nutrition\EstimatedItem;
use App\Nutrition\MealEstimate;
use PHPUnit\Framework\TestCase;

class MealEntryTest extends TestCase
{
    public function testEatenAtIsStoredInUtc(): void
    {
        $entry = new MealEntry(new User(), 'x', new \DateTimeImmutable('2026-10-02 01:30', new \DateTimeZone('Europe/Riga')));

        self::assertSame('2026-10-01 22:30 UTC', $entry->getEatenAt()->format('Y-m-d H:i e'));
    }

    public function testTotalsAreSummedFromItems(): void
    {
        $entry = new MealEntry(new User(), 'x', new \DateTimeImmutable());
        $entry->addItem(new MealItem($entry, 'a', 100, 150, 10, 20, 3));
        $entry->addItem(new MealItem($entry, 'b', 50, 50.5, 1.5, 2, 4));

        self::assertSame(200.5, $entry->getKcal());
        self::assertSame(11.5, $entry->getProtein());
        self::assertSame(22.0, $entry->getCarbs());
        self::assertSame(7.0, $entry->getFat());
    }

    public function testNewEntryIsPendingWithoutNumbers(): void
    {
        $entry = new MealEntry(new User(), 'x', new \DateTimeImmutable());

        self::assertSame(EstimationStatus::Pending, $entry->getStatus());
        self::assertNull($entry->getEstimatedBy());
        self::assertSame(0, $entry->getEstimationAttempts());
        self::assertSame(0.0, $entry->getKcal());
    }

    public function testApplyEstimateReplacesItemsAndCountsTheAttempt(): void
    {
        $entry = new MealEntry(new User(), 'x', new \DateTimeImmutable());
        $entry->estimationFailed('down', new \DateTimeImmutable('2026-10-01 12:00'), giveUp: false);
        $entry->applyEstimate(new MealEstimate([new EstimatedItem('Old', 10, 10, 1, 1, 1)], 'm1'), new \DateTimeImmutable());
        $entry->applyEstimate(new MealEstimate([new EstimatedItem('Rice', 150, 195, 4, 42, 0.4)], 'm2'), new \DateTimeImmutable('2026-10-01 12:05'));

        self::assertSame(EstimationStatus::Estimated, $entry->getStatus());
        self::assertSame(['Rice'], $entry->getItems()->map(fn ($i) => $i->getName())->getValues());
        self::assertSame(195.0, $entry->getKcal());
        self::assertSame('m2', $entry->getEstimatedBy());
        self::assertSame(3, $entry->getEstimationAttempts());
        self::assertNull($entry->getLastEstimationError());
        self::assertSame('2026-10-01 12:05', $entry->getLastEstimationAttemptAt()->format('Y-m-d H:i'));
    }

    public function testEstimationFailedStaysPendingOrGivesUp(): void
    {
        $entry = new MealEntry(new User(), 'x', new \DateTimeImmutable());

        $entry->estimationFailed('503', new \DateTimeImmutable(), giveUp: false);
        self::assertTrue($entry->isPending());
        self::assertSame('503', $entry->getLastEstimationError());

        $entry->estimationFailed('timeout', new \DateTimeImmutable(), giveUp: true);
        self::assertTrue($entry->isFailed());
        self::assertSame(2, $entry->getEstimationAttempts());
    }
}
