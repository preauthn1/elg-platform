<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorManagement\VendorInfoResource\Pages;

use App\Filament\Admin\Resources\VendorManagement\VendorInfoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVendorInfoManagement extends ListRecords
{
    protected static string $resource = VendorInfoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
