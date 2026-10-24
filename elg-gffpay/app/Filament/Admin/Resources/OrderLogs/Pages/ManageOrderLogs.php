<?php

namespace App\Filament\Admin\Resources\OrderLogs\Pages;

use App\Filament\Admin\Resources\OrderLogs\OrderLogResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOrderLogs extends ManageRecords
{
    protected static string $resource = OrderLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('filament.actions.back'))
                ->icon('heroicon-o-arrow-left')
                ->url('#')
                ->extraAttributes(['onclick' => 'window.history.back(); return false;'])
                ->color('gray'),
            // CreateAction::make(),
        ];
    }
}
