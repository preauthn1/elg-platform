<?php

namespace App\Filament\Admin\Resources\Admins\Tables;

use App\Enums\Db\EnableEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdminsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('username')
                    ->label(__('filament.fields.username'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('filament.fields.status'))
                    ->formatStateUsing(fn (int $state) => EnableEnum::from($state)->getLabel())
                    ->color(fn (int $state) => EnableEnum::from($state)->getColor()),
                TextColumn::make('email')
                    ->label(__('filament.fields.email'))
                    ->searchable(),
                TextColumn::make('email_verified_at')
                    ->label(__('filament.fields.email_verified_at'))
                    ->dateTime(),
                TextColumn::make('created_at')
                    ->label(__('filament.fields.created_at'))
                    ->dateTime()
                    // ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('filament.fields.updated_at'))
                    ->dateTime()
                    // ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                // BulkActionGroup::make([
                //     DeleteBulkAction::make(),
                // ]),
            ]);
    }
}
