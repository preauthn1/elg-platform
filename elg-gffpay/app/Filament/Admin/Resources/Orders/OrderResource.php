<?php

namespace App\Filament\Admin\Resources\Orders;

use App\Enums\Db\OrderStatusEnum;
use App\Enums\Db\OrderTypeEnum;
use App\Filament\Admin\Resources\OrderLogs\OrderLogResource;
use App\Filament\Admin\Resources\Orders\Pages\ManageOrders;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TextInput as FilterTextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationLabel(): string
    {
        return __('filament.navigation.orders');
    }

    public static function getPluralModelLabel(): string
    {
        return __('models.order.plural_label');
    }

    public static function getModelLabel(): string
    {
        return __('models.order.label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('type')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('status')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('order_no')
                    ->required(),
                TextInput::make('merchant_order_no')
                    ->required(),
                Select::make('wallet_id')
                    ->relationship('wallet', 'id')
                    ->required(),
                TextInput::make('wallet_balance')
                    ->numeric(),
                TextInput::make('lt')
                    ->numeric(),
                TextInput::make('tx_hash'),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('fee')
                    ->numeric(),
                TextInput::make('from_address')
                    ->required(),
                TextInput::make('to_address')
                    ->required(),
                TextInput::make('callback_url')
                    ->url()
                    ->required(),
                TextInput::make('is_callback_success')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('callback_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('query_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('is_transfer_api_called')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('remark')
                    ->required(),
                TextInput::make('fail_reason')
                    ->required(),
                DateTimePicker::make('transfer_api_called_at'),
                DateTimePicker::make('last_query_at'),
                DateTimePicker::make('last_callback_at'),
                DateTimePicker::make('confirmed_at'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Fieldset::make(__('filament.infolist_sections.basic_information'))->schema([
                    TextEntry::make('type')
                        ->label(__('filament.fields.type'))
                        ->formatStateUsing(fn (int $state) => OrderTypeEnum::from($state)->name),
                    TextEntry::make('status')
                        ->label(__('filament.fields.status'))
                        ->formatStateUsing(fn (int $state) => OrderStatusEnum::from($state)->getLabel()),
                    TextEntry::make('order_no')
                        ->label(__('filament.fields.order_no')),
                    TextEntry::make('merchant_order_no')
                        ->label(__('filament.fields.merchant_order_no')),
                    TextEntry::make('amount')
                        ->label(__('filament.fields.amount'))
                        ->numeric(),
                    TextEntry::make('fee')
                        ->label(__('filament.fields.fee'))
                        ->numeric()
                        ->placeholder('-'),
                    TextEntry::make('confirmed_at')
                        ->label(__('filament.fields.confirmed_at'))
                        ->dateTime()
                        ->placeholder('-'),
                    TextEntry::make('created_at')
                        ->label(__('filament.fields.created_at'))
                        ->dateTime(),
                    TextEntry::make('remark')
                        ->label(__('filament.fields.remark')),
                ])->columnSpan(2),
                Fieldset::make(__('filament.infolist_sections.wallet_information'))->schema([
                    TextEntry::make('wallet.id')
                        ->label(__('filament.fields.wallet_id')),
                    TextEntry::make('wallet_balance')
                        ->label(__('filament.fields.wallet_balance'))
                        ->numeric()
                        ->placeholder('-'),
                    TextEntry::make('lt')
                        ->label(__('filament.fields.lt'))
                        ->numeric()
                        ->placeholder('-'),
                    TextEntry::make('tx_hash')
                        ->label(__('filament.fields.tx_hash'))
                        ->placeholder('-'),
                    TextEntry::make('from_address')
                        ->label(__('filament.fields.from_address')),
                    TextEntry::make('to_address')
                        ->label(__('filament.fields.to_address')),
                ])->columnSpan(2),

                Fieldset::make(__('filament.infolist_sections.debug_information'))->schema([
                    TextEntry::make('callback_url')
                        ->label(__('filament.fields.callback_url')),
                    TextEntry::make('is_callback_success')
                        ->label(__('filament.fields.is_callback_success'))
                        ->numeric(),
                    TextEntry::make('callback_count')
                        ->label(__('filament.fields.callback_count'))
                        ->numeric(),
                    TextEntry::make('query_count')
                        ->label(__('filament.fields.query_count'))
                        ->numeric(),
                    TextEntry::make('is_transfer_api_called')
                        ->label(__('filament.fields.is_transfer_api_called'))
                        ->numeric(),

                    TextEntry::make('fail_reason')
                        ->label(__('filament.fields.fail_reason')),
                    TextEntry::make('transfer_api_called_at')
                        ->label(__('filament.fields.transfer_api_called_at'))
                        ->dateTime()
                        ->placeholder('-'),
                    TextEntry::make('last_query_at')
                        ->label(__('filament.fields.last_query_at'))
                        ->dateTime()
                        ->placeholder('-'),
                    TextEntry::make('last_callback_at')
                        ->label(__('filament.fields.last_callback_at'))
                        ->dateTime()
                        ->placeholder('-'),
                    TextEntry::make('updated_at')
                        ->label(__('filament.fields.updated_at'))
                        ->dateTime(),
                ])->columnSpan(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordAction(null) // 取消點擊跳轉
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('filament.fields.type'))
                    ->formatStateUsing(fn (int $state) => OrderTypeEnum::from($state)->getLabel()),
                TextColumn::make('order_number')
                    ->label(new HtmlString(__('filament.fields.order_no') . ' <br /> ' . __('filament.fields.merchant_order_no')))
                    ->searchable(query: function ($query, string $search) {
                        $query->where('order_no', $search); // 精確等於
                        $query->orWhere('merchant_order_no', $search); // 精確等於
                    })
                    ->getStateUsing(fn ($record, $table): array => [
                        TextColumn::make('order_no')
                            ->table($table)
                            ->record($record)
                            ->default('-')
                            ->inline(),
                        TextColumn::make('merchant_order_no')
                            ->table($table)
                            ->record($record)
                            ->default('-')
                            ->inline(),
                    ])
                    ->listWithLineBreaks(),
                TextColumn::make('wallet_id')
                    ->label(__('filament.fields.wallet_id'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('wallet_balance')
                    ->label(__('filament.fields.wallet_balance'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->numeric(),
                TextColumn::make('lt')
                    ->label(__('filament.fields.lt'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(query: function ($query, string $search) {
                        $query->where('lt', $search); // 精確等於
                    })
                    ->numeric(),
                TextColumn::make('tx_hash')
                    ->label(__('filament.fields.tx_hash'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('order_amount')
                    ->label(new HtmlString(__('filament.fields.amount') . ' <br /> ' . __('filament.fields.fee')))
                    ->searchable(query: function ($query, string $search) {
                        $query->where('amount', $search); // 精確等於
                        $query->orWhere('fee', $search); // 精確等於
                    })
                    ->getStateUsing(fn ($record, $table): array => [
                        TextColumn::make('amount')
                            ->table($table)
                            ->record($record)
                            ->numeric(9)
                            ->default('-')
                            ->inline(),
                        TextColumn::make('fee')
                            ->table($table)
                            ->record($record)
                            ->numeric(9)
                            ->default('-')
                            ->inline(),
                    ])
                    ->listWithLineBreaks(),
                TextColumn::make('address')
                    ->label(new HtmlString(__('filament.fields.from_address') . ' <br /> ' . __('filament.fields.to_address')))
                    ->searchable(query: function ($query, string $search) {
                        $query->where('from_address', $search); // 精確等於
                        $query->orWhere('to_address', $search); // 精確等於
                    })
                    ->getStateUsing(fn ($record, $table): array => [
                        TextColumn::make('from_address')
                            ->table($table)
                            ->record($record)
                            ->default('-')
                            ->inline(),
                        TextColumn::make('to_address')
                            ->table($table)
                            ->record($record)
                            ->default('-')
                            ->inline(),
                    ])
                    ->listWithLineBreaks(),
                TextColumn::make('callback_url')
                    ->label(__('filament.fields.callback_url'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('is_callback_success')
                    ->label(__('filament.fields.is_callback_success'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->numeric(),
                TextColumn::make('callback_count')
                    ->label(__('filament.fields.callback_count'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->numeric(),
                TextColumn::make('query_count')
                    ->label(__('filament.fields.query_count'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->numeric(),
                TextColumn::make('is_transfer_api_called')
                    ->label(__('filament.fields.is_transfer_api_called'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->numeric(),
                TextColumn::make('remark')
                    ->label(__('filament.fields.remark'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('fail_reason')
                    ->label(__('filament.fields.fail_reason'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('transfer_api_called_at')
                    ->label(__('filament.fields.transfer_api_called_at'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime(),
                TextColumn::make('last_query_at')
                    ->label(__('filament.fields.last_query_at'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime(),
                TextColumn::make('last_callback_at')
                    ->label(__('filament.fields.last_callback_at'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime(),
                TextColumn::make('confirmed_at')
                    ->label(__('filament.fields.confirmed_at'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime(),
                TextColumn::make('created_at')
                    ->label(__('filament.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('filament.fields.updated_at'))
                    ->dateTime()
                    // ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('filament.fields.status'))
                    ->color(fn (int $state) => OrderStatusEnum::from($state)->getColor())
                    ->formatStateUsing(fn (int $state) => OrderStatusEnum::from($state)->getLabel()),

            ])
            ->filters([
                Filter::make('created_at')
                    ->label(__('filament.fields.created_at'))
                    ->schema([
                        DateTimePicker::make('created_from')
                            ->label('From')
                            ->displayFormat('Y-m-d H:i:s')
                            ->default(Carbon::today()->startOfDay()),
                        DateTimePicker::make('created_to')
                            ->label('To')
                            ->displayFormat('Y-m-d H:i:s')
                            ->default(Carbon::today()->endOfDay()),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn ($query, $date) => $query->where('created_at', '>=', $date)
                            )
                            ->when(
                                $data['created_to'],
                                fn ($query, $date) => $query->where('created_at', '<=', $date)
                            );
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $indicator = null;

                        if (!empty($data['created_from']) || !empty($data['created_to'])) {
                            $from = $data['created_from'] ? Carbon::parse($data['created_from'])->format('Y-m-d H:i:s') : Carbon::today()->startOfDay()->format('Y-m-d H:i:s');
                            $to = $data['created_to'] ? Carbon::parse($data['created_to'])->format('Y-m-d H:i:s') : Carbon::today()->endOfDay()->format('Y-m-d H:i:s');
                            $indicator = __('filament.fields.created_at') . ': ' . $from;
                            if ($from !== $to) {
                                $indicator .= ' ~ ' . $to;
                            }
                        }

                        return $indicator;
                    }),
                Filter::make('order_number')
                    ->schema([
                        FilterTextInput::make('order_number')
                            ->placeholder(__('filament.placeholders.enter_order_number'))
                            ->label(__('filament.fields.order_no')),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when(
                                $data['order_number'],
                                fn ($query, $orderNumber) => $query->where('order_no', $orderNumber)
                                    ->orWhere('merchant_order_no', $orderNumber)
                            );
                    }),
                SelectFilter::make('status')
                    ->label(__('filament.fields.status'))
                    ->options(OrderStatusEnum::getOptions())
                    ->multiple(),
                SelectFilter::make('type')
                    ->label(__('filament.fields.type'))
                    ->options(OrderTypeEnum::getOptions())
                    ->multiple(),
            ])
            ->recordActions([
                ViewAction::make()->slideOver(),
                ActionGroup::make([
                    Action::make('manualSuccess')
                        ->label(__('filament.actions.manual_success'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn ($record) => $record->status === OrderStatusEnum::PENDING->value)
                        ->schema([
                            Textarea::make('remark')
                                ->label(__('filament.fields.remark'))
                                ->required()
                                ->rows(4)
                                ->placeholder(__('filament.placeholders.enter_remark')),
                        ])
                        ->action(function ($record, array $data) {
                            $record->update([
                                'status' => OrderStatusEnum::SUCCESS->value,
                                'remark' => $data['remark'],
                            ]);

                            Notification::make()
                                ->title(__('filament.messages.order_status_updated'))
                                ->body(__('filament.messages.order_status_updated_to_success'))
                                ->success()
                                ->send();
                        })
                        ->modalHeading(__('filament.actions.manual_success'))
                        ->modalSubmitActionLabel(__('filament.actions.confirm'))
                        ->modalCancelActionLabel(__('filament.actions.cancel')),
                    Action::make('viewLogs')
                        ->label(__('filament.actions.view_logs'))
                        ->icon('heroicon-o-clipboard-document-list')
                        ->url(fn ($record) => OrderLogResource::getUrl('index', [
                            'order_id' => $record->id, // 傳 query string
                        ])),
                    // ->openUrlInNewTab() // 可選：開新分頁
                    // EditAction::make(),
                    // DeleteAction::make(),
                ]),
            ])
            // ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                // BulkActionGroup::make([
                //     DeleteBulkAction::make(),
                // ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOrders::route('/'),
        ];
    }
}
