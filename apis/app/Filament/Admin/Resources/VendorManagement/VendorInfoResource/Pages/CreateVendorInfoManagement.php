<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorManagement\VendorInfoResource\Pages;

use App\Filament\Admin\Resources\VendorManagement\VendorInfoResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVendorInfoManagement extends CreateRecord
{
    protected static string $resource = VendorInfoResource::class;
}
