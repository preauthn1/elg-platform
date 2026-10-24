<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorManagement\VendorResource\Pages;

use App\Filament\Admin\Resources\VendorManagement\VendorResource;
use Filament\Resources\Pages\ListRecords;

class ListVendor extends ListRecords
{
    protected static string $resource = VendorResource::class;
}
