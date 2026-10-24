<?php

namespace App\Filament\Admin\Resources\Admins\Schemas;

use App\Enums\Db\EnableEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AdminInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('username')
                    ->label(__('filament.fields.username')),
                TextEntry::make('status')
                    ->label(__('filament.fields.status'))
                    ->formatStateUsing(fn (int $state) => EnableEnum::from($state)->getLabel())
                    ->color(fn (int $state) => EnableEnum::from($state)->getColor()),
                TextEntry::make('email')
                    ->label(__('filament.fields.email'))
                    ->placeholder('-'),
                TextEntry::make('email_verified_at')
                    ->label(__('filament.fields.email_verified_at'))
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
