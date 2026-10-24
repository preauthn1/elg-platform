<?php

namespace App\Filament\Admin\Resources\Merchants\Schemas;

use App\Enums\Db\EnableEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MerchantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('filament.fields.name'))
                    ->required(),
                TextInput::make('code')
                    ->label(__('filament.fields.code'))
                    ->required(),
                TextInput::make('secret_key')
                    ->label(__('filament.fields.secret_key'))
                    ->required(),
                Select::make('status')
                    ->label(__('filament.fields.status'))
                    ->options(EnableEnum::getOptions())
                    ->default(1)
                    ->required(),
            ]);
    }
}
