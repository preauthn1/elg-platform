<?php

declare(strict_types=1);

namespace App\Vendors\MotivationSW;

abstract class BaseService
{
    public function getDateTime()
    {
        return now()->setTimezone(new \DateTimeZone('-04:00'))->format('c');
    }
}
