<?php

namespace App\Tests\Unit\Entity;

use App\Entity\ExerciseEntry;
use App\Entity\User;
use App\Estimation\EstimationStatus;
use App\Exercise\EstimatedActivity;
use App\Exercise\ExerciseEstimate;
use PHPUnit\Framework\TestCase;

class ExerciseEntryTest extends TestCase
{
    public function testPerformedAtIsStoredInUtc(): void
    {
        $entry = new ExerciseEntry(new User(), 'x', new \DateTimeImmutable('2026-10-02 01:30', new \DateTimeZone('Europe/Riga')));

        self::assertSame('2026-10-01 22:30 UTC', $entry->getPerformedAt()->format('Y-m-d H:i e'));
    }

    public function testApplyEstimateSumsItemsAndMarksEstimated(): void
    {
        $entry = new ExerciseEntry(new User(), 'x', new \DateTimeImmutable());
        self::assertSame(EstimationStatus::Pending, $entry->getStatus());

        $entry->applyEstimate(new ExerciseEstimate([new EstimatedActivity('Run', 30, 300), new EstimatedActivity('Swim', 20, 180.5)], 'm'), new \DateTimeImmutable());

        self::assertSame(EstimationStatus::Estimated, $entry->getStatus());
        self::assertSame(480.5, $entry->getKcal());
        self::assertSame('m', $entry->getEstimatedBy());

        $entry->getItems()->first()->setKcal(100);
        $entry->recalculateTotals();
        self::assertSame(280.5, $entry->getKcal());
    }
}
