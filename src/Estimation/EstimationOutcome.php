<?php

namespace App\Estimation;

enum EstimationOutcome
{
    /** Items and totals are filled in. */
    case Estimated;
    /** The AI found no food in the text. */
    case NoFood;
    /** Not estimated yet; another automatic attempt is scheduled. */
    case Retrying;
    /** Not estimated and no automatic attempts left. */
    case Failed;
}
