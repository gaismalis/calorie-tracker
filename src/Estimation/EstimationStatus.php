<?php

namespace App\Estimation;

enum EstimationStatus: string
{
    /** Saved, waiting for the AI (first try timed out or failed; retrying in the background). */
    case Pending = 'pending';
    /** Items and totals are known. */
    case Estimated = 'estimated';
    /** Gave up after all automatic retries; the user can retry manually. */
    case Failed = 'failed';
}
