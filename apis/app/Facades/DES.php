<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\Encryption\DesService;
use Illuminate\Support\Facades\Facade;

class DES extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DesService::class;
    }
}
