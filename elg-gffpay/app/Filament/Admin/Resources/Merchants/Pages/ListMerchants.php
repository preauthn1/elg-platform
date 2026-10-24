<?php

namespace App\Filament\Admin\Resources\Merchants\Pages;

use App\Filament\Admin\Resources\Merchants\MerchantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMerchants extends ListRecords
{
    protected static string $resource = MerchantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
