<?php

namespace App\Filament\Admin\Resources\Merchants\Schemas;

use App\Enums\Db\EnableEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class MerchantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')
                    ->label(__('filament.fields.name')),
                TextEntry::make('code')
                    ->label(__('filament.fields.code')),
                TextEntry::make('secret_key')
                    ->label(__('filament.fields.secret_key')),
                TextEntry::make('status')
                    ->label(__('filament.fields.status'))
                    ->formatStateUsing(fn (int $state) => EnableEnum::from($state)->getLabel())
                    ->color(fn (int $state) => EnableEnum::from($state)->getColor()),
                TextEntry::make('created_at')
                    ->label(__('filament.fields.created_at'))
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label(__('filament.fields.updated_at'))
                    ->dateTime(),
            ]);
    }
}
