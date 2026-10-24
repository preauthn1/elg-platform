<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentManagement;

use App\Enums\CashFlowTypeEnum;
use App\Enums\StatusEnum;
use App\Filament\Admin\Resources\PaymentManagement\CashFlowResource\Pages\EditCashFlow;
use App\Filament\Admin\Resources\PaymentManagement\CashFlowResource\Pages\ListCashFlow;
use App\Helpers\Common;
use App\Models\CashFlow;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * 金流商管理：維護 `cash_flows` 的回調來源 IP 白名單（target/tickets#4446），
 * 三種角色都可進入（授權見 {@see \App\Policies\CashFlowPolicy}）。
 *
 * 🔴 **只有白名單可以改，其他欄位一律唯讀**，而且唯讀是兩層的：表單欄位 `disabled()`
 * 不會被送出，服務層 {@see \App\Services\CashFlowManagementService} 也只取 whitelist。
 * 為什麼其他欄位不能開：`third_party_code` 是 Driver 的解析鍵（改了整家金流商找不到實作）、
 * `type` 決定代收／代付分流、`rate`／`percentage` 會進金額換算——改錯都不會馬上被發現。
 *
 * 沒有新增、刪除、停用／啟用：cash_flows 由 `cash-flow:sync` 從 Data/cash_flows.php 建立，
 * id 被訂單與代理設定引用；而且關掉 status 擋不住已經送到三方那邊的在途訂單
 * （見 {@see \App\Http\Controllers\External\CashFlow\DepositNotifyController} 的類別註解），
 * 給一個看起來有效、實際沒止血的按鈕比沒有更危險。
 */
class CashFlowResource extends Resource
{
    protected static ?string $model = CashFlow::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = '金流商';

    protected static ?string $modelLabel = '金流商';

    protected static ?string $pluralModelLabel = '金流商';

    protected static ?string $navigationLabel = '金流商管理';

    public static function form(Schema $schema): Schema
    {
        return $schema
            // 編輯頁最外層預設兩欄，Section 會兩兩並排、高度對不齊；改單欄讓每個區塊佔滿整列
            ->columns(1)
            ->components([
                Section::make('基本資料（唯讀）')
                    ->description('這些欄位由 cash-flow:sync 維護，後台不開放修改')
                    ->columns(2)
                    ->schema([
                        TextInput::make('id')->label('編號')->disabled(),
                        TextInput::make('third_party_code')->label('三方代碼')->disabled(),
                        TextInput::make('name')->label('名稱')->disabled(),
                        TextInput::make('type')
                            ->label('支付類別')
                            ->formatStateUsing(fn (mixed $state): string => self::typeLabel($state))
                            ->disabled(),
                        TextInput::make('rate')->label('轉換比例')->disabled(),
                        TextInput::make('percentage')->label('百分比')->disabled(),
                        TextInput::make('status')
                            ->label('狀態')
                            ->formatStateUsing(fn (mixed $state): string => self::statusLabel($state))
                            ->disabled(),
                        TextInput::make('remark')->label('備註')->disabled(),
                    ]),
                Section::make('白名單')
                    ->schema([
                        Textarea::make('whitelist')
                            ->label('回調來源 IP 白名單')
                            ->helperText(
                                '逗號分隔，每一筆都必須是單一 IP，不接受網段（系統是整串比對，網段不會生效）。'
                                . '⚠️ 這欄是給沒有驗簽的金流商擋偽造回調用的，而且即時生效：留空代表那家的回調'
                                . '一律拒絕、該渠道的儲值當場中斷，不是「關掉檢查」。'
                                . '目前 Ap8Pay、EasyPay、MaxPay、TngPin 的代收與代付回調會讀這份名單。'
                            )
                            ->rows(3)
                            ->rules([
                                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    if (!is_string($value) || trim($value) === '') {
                                        return;
                                    }
                                    $invalid = Common::invalidWhitelistEntries($value);
                                    if ($invalid !== []) {
                                        $fail('以下不是合法的單一 IP：' . implode('、', array_map(fn (string $entry): string => $entry === '' ? '（空白）' : $entry, $invalid)));
                                    }
                                },
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('編號')->sortable()->searchable(),
                TextColumn::make('third_party_code')->label('三方代碼')->sortable()->searchable(),
                TextColumn::make('name')->label('名稱')->sortable()->searchable(),
                TextColumn::make('type')
                    ->label('支付類別')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::typeLabel($state)),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::statusLabel($state))
                    ->color(fn (mixed $state): string => (int) $state === StatusEnum::ENABLE->value ? 'success' : 'gray'),
                TextColumn::make('whitelist')->label('白名單')->wrap()->placeholder('—'),
            ])
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('type')
                    ->label('支付類別')
                    ->options([
                        CashFlowTypeEnum::DEPOSIT->value => '線上支付',
                        CashFlowTypeEnum::AUTO_DEPOSIT->value => '自助開分',
                        CashFlowTypeEnum::WITHDRAW->value => '代付',
                        CashFlowTypeEnum::AUTO_WITHDRAW->value => '自助洗分',
                    ]),
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options([StatusEnum::ENABLE->value => '正常', StatusEnum::DISABLE->value => '關閉']),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    private static function typeLabel(mixed $state): string
    {
        return match (CashFlowTypeEnum::tryFrom((int) $state)) {
            CashFlowTypeEnum::DEPOSIT => '線上支付',
            CashFlowTypeEnum::AUTO_DEPOSIT => '自助開分',
            CashFlowTypeEnum::WITHDRAW => '代付',
            CashFlowTypeEnum::AUTO_WITHDRAW => '自助洗分',
            default => (string) $state,
        };
    }

    private static function statusLabel(mixed $state): string
    {
        return (int) $state === StatusEnum::ENABLE->value ? '正常' : '關閉';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashFlow::route('/'),
            'edit' => EditCashFlow::route('/{record}/edit'),
        ];
    }
}
