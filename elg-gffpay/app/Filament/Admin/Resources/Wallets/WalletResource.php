<?php

namespace App\Filament\Admin\Resources\Wallets;

use App\Enums\Db\EnableEnum;
use App\Enums\Db\SubWalletIdEnum;
use App\Enums\Db\WalletDeploymentStatusEnum;
use App\Enums\Db\WalletStatusEnum;
use App\Filament\Admin\Resources\Wallets\Pages\ManageWallets;
use App\Models\Wallet;
use App\Services\TonService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WalletResource extends Resource
{
    protected static ?string $model = Wallet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationLabel(): string
    {
        return __('filament.navigation.wallets');
    }

    public static function getPluralModelLabel(): string
    {
        return __('models.wallet.plural_label');
    }

    public static function getModelLabel(): string
    {
        return __('models.wallet.label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sub_wallet_id')
                    ->label(__('filament.fields.sub_wallet_id'))
                    ->required()
                    ->numeric(),
                // TextInput::make('address')
                //     ->required(),
                TextInput::make('balance')
                    ->label(__('filament.fields.balance'))
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('status')
                    ->label(__('filament.fields.status'))
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('sub_wallet_id')
                    ->label(__('filament.fields.sub_wallet_id'))
                    ->numeric(),
                // TextEntry::make('address'),
                // TextEntry::make('balance')
                //     ->numeric(),
                TextEntry::make('is_enabled')
                    ->label(__('filament.fields.is_enabled'))
                    ->color(fn (int $state) => EnableEnum::from($state)->getColor())
                    ->formatStateUsing(fn (int $state) => EnableEnum::from($state)->getLabel()),
                TextEntry::make('deployment_status')
                    ->label(__('filament.fields.deployment_status'))
                    ->color(fn (int $state) => WalletDeploymentStatusEnum::from($state)->getColor())
                    ->formatStateUsing(fn (int $state) => WalletDeploymentStatusEnum::from($state)->getLabel()),
                TextEntry::make('status')
                    ->label(__('filament.fields.status'))
                    ->formatStateUsing(fn (int $state) => WalletStatusEnum::from($state)->getLabel()),
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
            ->columns([
                TextColumn::make('sub_wallet_id')
                    ->numeric()
                    ->sortable()
                    ->label(__('filament.fields.sub_wallet_id')),
                TextColumn::make('address')
                    ->searchable(query: function ($query, string $search) {
                        $query->where('address', $search); // 精確等於
                    })
                    ->copyable()
                    ->label(__('filament.fields.address')),
                TextColumn::make('balance')
                    ->numeric(9)
                    ->label(__('filament.fields.balance')),
                TextColumn::make('is_enabled')
                    ->formatStateUsing(fn (int $state) => EnableEnum::from($state)->getLabel())
                    ->color(fn (int $state) => EnableEnum::from($state)->getColor())
                    ->label(__('filament.fields.is_enabled')),
                TextColumn::make('deployment_status')
                    ->formatStateUsing(fn (int $state) => WalletDeploymentStatusEnum::from($state)->getLabel())
                    ->color(fn (int $state) => WalletDeploymentStatusEnum::from($state)->getColor())
                    ->label(__('filament.fields.deployment_status')),
                TextColumn::make('status')
                    ->formatStateUsing(fn (int $state) => WalletStatusEnum::from($state)->getLabel())
                    ->label(__('filament.fields.status')),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('filament.fields.created_at')),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('filament.fields.updated_at')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(WalletStatusEnum::getOptions())
                    ->multiple()
                    ->label(__('filament.fields.status')),
                SelectFilter::make('deployment_status')
                    ->options(WalletDeploymentStatusEnum::getOptions())
                    ->label(__('filament.fields.deployment_status')),
                SelectFilter::make('is_enabled')
                    ->options(EnableEnum::getOptions())
                    ->label(__('filament.fields.is_enabled')),
            ])
            ->recordActions([
                ViewAction::make(),
                // 啟用停用
                ActionGroup::make([
                    Action::make('toggleEnabled')
                        ->icon(fn ($record) => $record->is_enabled ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                        ->label(fn ($record) => $record->is_enabled ? EnableEnum::DISABLE->getLabel() : EnableEnum::ENABLE->getLabel()) // 動態文字
                        ->color(fn ($record) => $record->is_enabled ? 'danger' : 'success') // 動態顏色
                        ->action(function ($record) {
                            $record->update([
                                'is_enabled' => $record->is_enabled ? 0 : 1, // 切換狀態
                            ]);
                            Notification::make()
                                ->title('toggle enabled success')
                                ->body($record->is_enabled ? 'enable' : 'disable')
                                ->success()
                                ->send();
                        }),
                    // 查詢並更新餘額
                    Action::make('refreshBalance')
                        ->icon('heroicon-o-arrow-path')
                        ->label(__('filament.actions.refresh_balance'))
                        ->action(function ($record, TonService $tonService) {
                            try {
                                $balance = $tonService->refreshWalletBalance($record);

                                Notification::make()
                                    ->title('refresh balance success')
                                    ->body($balance)
                                    ->success()
                                    ->send();

                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('refresh balance failed')
                                    ->body($e->getMessage()) // 可選：顯示錯誤訊息
                                    ->danger()
                                    ->send();
                            }
                        }),
                    // 查詢並更新部屬狀態
                    Action::make('refreshDeployed')
                        ->icon('heroicon-o-arrow-path')
                        ->label(__('filament.actions.refresh_deployed'))
                        ->action(function ($record, TonService $tonService) {
                            try {
                                $state = $tonService->refreshWalletDeployed($record);

                                Notification::make()
                                    ->title('refresh deployed success')
                                    ->body($state->value)
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('refresh deployed failed')
                                    ->body($e->getMessage()) // 可選：顯示錯誤訊息
                                    ->danger()
                                    ->send();
                            }
                        }),
                    // 部屬錢包
                    Action::make('deployWallet')
                        ->visible(fn ($record) => $record->deployment_status == WalletDeploymentStatusEnum::NOT_DEPLOYED->value)
                        ->icon('heroicon-o-plus-circle')
                        ->label(__('filament.actions.deploy_wallet'))
                        ->action(function ($record, TonService $tonService) {
                            try {
                                $tonService->deployWallet($record);

                                Notification::make()
                                    ->title('deploy wallet success')
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('deploy wallet failed')
                                    ->body($e->getMessage()) // 可選：顯示錯誤訊息
                                    ->danger()
                                    ->send();
                            }
                        }),
                    // 歸集錢包
                    Action::make('sweep')
                        ->visible(fn ($record) => $record->sub_wallet_id != SubWalletIdEnum::MAIN_WALLET->value)
                        ->icon('heroicon-o-arrow-up')
                        ->label(__('filament.actions.sweep'))
                        ->action(function ($record, TonService $tonService) {
                            try {
                                $amount = $tonService->sweepWallet($record);

                                Notification::make()
                                    ->title('sweep wallet success')
                                    ->body('+ ' . $amount)
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('sweep wallet failed')
                                    ->body($e->getMessage()) // 可選：顯示錯誤訊息
                                    ->danger()
                                    ->send();
                            }
                        }),
                    // 到外網查看錢包交易紀錄
                    Action::make('viewExternalLogs')
                        ->icon('heroicon-o-link')
                        ->label(__('filament.actions.external_logs'))
                        ->url(function ($record, TonService $tonService) {
                            return $tonService->getExternalLogsUrl($record);
                        }, shouldOpenInNewTab: true),
                ]),
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
            'index' => ManageWallets::route('/'),
        ];
    }
}
