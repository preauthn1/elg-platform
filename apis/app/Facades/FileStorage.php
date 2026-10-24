<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\FileStorageService;
use Illuminate\Support\Facades\Facade;

class FileStorage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FileStorageService::class;
    }
}
