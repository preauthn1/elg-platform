<?php

namespace App\Filament\Admin\Resources\Admins\Schemas;

use App\Enums\Db\EnableEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AdminForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('username')
                    ->label(__('filament.fields.username'))
                    ->required(),
                TextInput::make('password')
                    ->label(__('filament.fields.password'))
                    // ->password()
                    ->required(),
                Select::make('status')
                    ->label(__('filament.fields.status'))
                    ->options(EnableEnum::getOptions())
                    ->default(1)
                    ->required(),
                TextInput::make('email')
                    ->label(__('filament.fields.email'))
                    ->email(),
                DateTimePicker::make('email_verified_at')
                    ->label(__('filament.fields.email_verified_at')),
            ]);
    }
}
