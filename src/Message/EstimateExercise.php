<?php

namespace App\Message;

/** Background attempt to estimate exercise that is still Pending. */
final readonly class EstimateExercise
{
    public function __construct(public int $exerciseEntryId)
    {
    }
}
