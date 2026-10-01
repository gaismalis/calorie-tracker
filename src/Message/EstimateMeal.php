<?php

namespace App\Message;

/** Background attempt to estimate a meal that is still Pending. */
final readonly class EstimateMeal
{
    public function __construct(public int $mealEntryId)
    {
    }
}
