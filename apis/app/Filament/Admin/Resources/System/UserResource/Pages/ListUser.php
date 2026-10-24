<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\System\UserResource\Pages;

use App\Filament\Admin\Resources\System\UserResource;
use Filament\Resources\Pages\ListRecords;

class ListUser extends ListRecords
{
    protected static string $resource = UserResource::class;
}
