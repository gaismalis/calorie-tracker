<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // All timestamps are stored and compared in UTC; convert to the user's timezone only for display and "today".
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
