<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\Encryption\AesService;
use Illuminate\Support\Facades\Facade;

class AES extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AesService::class;
    }
}
