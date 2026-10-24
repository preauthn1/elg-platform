<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\BotNotifyService;
use Illuminate\Support\Facades\Facade;

class BotNotify extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BotNotifyService::class;
    }
}
