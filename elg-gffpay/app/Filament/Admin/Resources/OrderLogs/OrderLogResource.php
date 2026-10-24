<?php

namespace App\Filament\Admin\Resources\OrderLogs;

use App\Enums\Db\OrderLogTypeEnum;
use App\Filament\Admin\Resources\OrderLogs\Pages\ManageOrderLogs;
use App\Models\OrderLog;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderLogResource extends Resource
{
    protected static bool $shouldRegisterNavigation = false; // 不顯示在菜單

    protected static ?string $model = OrderLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationLabel(): string
    {
        return __('filament.navigation.order_logs');
    }

    public static function getPluralModelLabel(): string
    {
        return __('models.order_log.plural_label');
    }

    public static function getModelLabel(): string
    {
        return __('models.order_log.label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('order_id')
                    ->label(__('filament.fields.order_id'))
                    ->required()
                    ->numeric(),
                TextInput::make('type')
                    ->label(__('filament.fields.log_type'))
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('request_url')
                    ->label(__('filament.fields.request_url'))
                    ->url(),
                Textarea::make('request_data')
                    ->label(__('filament.fields.request_data'))
                    ->columnSpanFull(),
                Textarea::make('response_data')
                    ->label(__('filament.fields.response_data'))
                    ->columnSpanFull(),
                TextInput::make('http_code')
                    ->label(__('filament.fields.http_code'))
                    ->required()
                    ->numeric()
                    ->default(0),
                Textarea::make('exception')
                    ->label(__('filament.fields.exception'))
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')
                    ->label(__('filament.fields.id'))
                    ->numeric(),
                TextEntry::make('order_id')
                    ->label(__('filament.fields.order_id'))
                    ->numeric(),
                TextEntry::make('type')
                    ->label(__('filament.fields.log_type'))
                    ->formatStateUsing(fn ($state) => OrderLogTypeEnum::from($state)->getLabel()),
                TextEntry::make('request_url')
                    ->label(__('filament.fields.request_url'))
                    ->placeholder('-'),
                TextEntry::make('request_data')
                    ->label(__('filament.fields.request_data'))
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('response_data')
                    ->label(__('filament.fields.response_data'))
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('http_code')
                    ->label(__('filament.fields.http_code'))
                    ->numeric(),
                TextEntry::make('exception')
                    ->label(__('filament.fields.exception'))
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->label(__('filament.fields.created_at'))
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label(__('filament.fields.updated_at'))
                    ->dateTime(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label(__('filament.fields.id'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('order_id')
                    ->label(__('filament.fields.order_id'))
                    ->numeric(),
                TextColumn::make('type')
                    ->label(__('filament.fields.log_type'))
                    ->formatStateUsing(fn ($state) => OrderLogTypeEnum::from($state)->getLabel()),
                TextColumn::make('request_url')
                    ->label(__('filament.fields.request_url')),
                TextColumn::make('http_code')
                    ->label(__('filament.fields.http_code'))
                    ->numeric(),
                TextColumn::make('created_at')
                    ->label(__('filament.fields.created_at'))
                    ->dateTime(),
                TextColumn::make('updated_at')
                    ->label(__('filament.fields.updated_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->filters([
                SelectFilter::make('order_id')
                    ->label(__('filament.filters.order_id'))
                    ->default(request('order_id')),
            ])
            ->recordActions([
                ViewAction::make(),
                // EditAction::make(),
                // DeleteAction::make(),
            ])
            ->toolbarActions([
                // BulkActionGroup::make([
                //     DeleteBulkAction::make(),
                // ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOrderLogs::route('/'),
        ];
    }
}
