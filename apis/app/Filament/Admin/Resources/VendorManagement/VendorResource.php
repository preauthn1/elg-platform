<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorManagement;

use App\Enums\StatusEnum;
use App\Filament\Admin\Resources\VendorManagement\VendorResource\Pages\EditVendor;
use App\Filament\Admin\Resources\VendorManagement\VendorResource\Pages\ListVendor;
use App\Helpers\Common;
use App\Models\Vendor;
use App\Services\VendorManagementService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * 遊戲商管理：維護 `vendors` 表（target/tickets#4415），三種角色都可進入（授權見 VendorPolicy）。
 *
 * 只有修改、停用、啟用，沒有新增與刪除（理由見 VendorPolicy）。寫入一律經
 * VendorManagementService，與操作紀錄同交易、commit 後清遊戲商快取。
 * 編輯表單刻意不放 status：狀態只能用停用／啟用動作改，操作紀錄才分得出是哪一種操作。
 */
class VendorResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string|\UnitEnum|null $navigationGroup = '遊戲商';

    protected static ?string $modelLabel = '遊戲商';

    protected static ?string $pluralModelLabel = '遊戲商';

    protected static ?string $navigationLabel = '遊戲商管理';

    public static function form(Schema $schema): Schema
    {
        return $schema
            // 編輯頁最外層預設兩欄，Section 會兩兩並排、高度對不齊；改單欄讓每個區塊佔滿整列
            ->columns(1)
            ->components([
                Section::make('基本資料')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('名稱')->required()->maxLength(50)->unique(ignoreRecord: true),
                        TextInput::make('third_party_code')->label('三方代碼')->maxLength(20),
                        TextInput::make('image')->label('圖片')->required()->maxLength(191),
                        TextInput::make('app_icon')->label('App 圖示')->maxLength(191),
                        TextInput::make('sort')->label('排序')->integer(),
                        TextInput::make('remark')->label('備註')->maxLength(191),
                        Textarea::make('memo')->label('Memo')->rows(3)->columnSpanFull(),
                    ]),
                // 顯示開關與結算參數不屬於維運權責，暫不在 Olympus 開放修改；要恢復時取消註解，並補上依角色的修改權限
                // Section::make('顯示開關')
                //     ->columns(3)
                //     ->schema([
                //         Toggle::make('is_new')->label('新遊戲商標籤'),
                //         Toggle::make('is_hot')->label('熱門標籤'),
                //         Toggle::make('is_event')->label('活動標籤'),
                //         Toggle::make('show_on_mobile')->label('Mobile 版顯示'),
                //         Toggle::make('show_on_pc')->label('PC 版顯示'),
                //         Toggle::make('show_on_app')->label('App 版顯示'),
                //         Toggle::make('show_on_profit_share')->label('佔成會員顯示'),
                //         Toggle::make('open_window')->label('另開視窗'),
                //         Toggle::make('need_login')->label('需另外登入'),
                //         Toggle::make('only_one_game')->label('只有一款遊戲'),
                //     ]),
                // Section::make('結算與比例')
                //     ->columns(3)
                //     ->schema([
                //         // decimal(10,2)：整數 8 位、小數 2 位
                //         TextInput::make('valid_bet_rate')->label('有效投注轉換比例')->required()->numeric()->rules(['decimal:0,2'])->minValue(0)->maxValue(99999999.99),
                //         Select::make('summary_daily')
                //             ->label('彙總時間')
                //             ->options([0 => '不計算', 1 => '每小時', 2 => '每天'])
                //             ->required(),
                //         TextInput::make('calc_time_zone')->label('計算時區（UTC 偏移）')->required()->integer()->minValue(-12)->maxValue(14),
                //         // 開關另起一列集中排，不接在數值欄位後面
                //         Grid::make(3)
                //             ->columnSpanFull()
                //             ->schema([
                //                 Toggle::make('calc_on_profit_share')->label('計入佔成'),
                //                 Toggle::make('calc_flow_limit')->label('計算稽核流水'),
                //                 Toggle::make('event_lock')->label('可參加鎖分活動'),
                //                 Toggle::make('is_free')->label('免盤費商戶'),
                //                 Toggle::make('unbind')->label('可解綁'),
                //             ]),
                //     ]),
                Section::make('API 設定')
                    ->schema([
                        Toggle::make('is_slow')->label('API 回應較慢'),
                    ]),
                Section::make('白名單')
                    ->schema([
                        Textarea::make('whitelist')
                            ->label('回調來源 IP 白名單')
                            ->helperText('逗號分隔，每一筆都必須是單一 IP，不接受網段（系統是整串比對，網段不會生效）。留空＝不檢查來源 IP。')
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
                TextColumn::make('name')->label('名稱')->sortable()->searchable(),
                TextColumn::make('code')->label('遊戲商代號')->sortable()->searchable(),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => (int) $state === StatusEnum::ENABLE->value ? '正常' : '關閉')
                    ->color(fn (mixed $state): string => (int) $state === StatusEnum::ENABLE->value ? 'success' : 'gray'),
                IconColumn::make('is_single_wallet')->label('單一錢包')->boolean(),
                TextColumn::make('whitelist')->label('白名單')->wrap()->placeholder('—'),
            ])
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options([StatusEnum::ENABLE->value => '正常', StatusEnum::DISABLE->value => '關閉']),
                TernaryFilter::make('is_single_wallet')->label('單一錢包'),
            ])
            ->recordActions([
                EditAction::make(),
                self::statusAction(StatusEnum::DISABLE),
                self::statusAction(StatusEnum::ENABLE),
            ]);
    }

    /**
     * 停用／啟用動作：列表與編輯頁共用。只顯示「能切過去」的那一個。
     */
    public static function statusAction(StatusEnum $to): Action
    {
        $isEnable = $to === StatusEnum::ENABLE;

        return Action::make($isEnable ? 'enable' : 'disable')
            ->label($isEnable ? '啟用' : '停用')
            ->icon($isEnable ? 'heroicon-o-play' : 'heroicon-o-pause')
            ->color($isEnable ? 'success' : 'danger')
            ->requiresConfirmation()
            ->visible(fn (Vendor $record): bool => (int) $record->status !== $to->value)
            ->action(function (Vendor $record) use ($to, $isEnable): void {
                app(VendorManagementService::class)->setStatus($record, $to, (int) Filament::auth()->id());
                $record->refresh();

                Notification::make()->success()->title(($isEnable ? '已啟用 ' : '已停用 ') . $record->name)->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendor::route('/'),
            'edit' => EditVendor::route('/{record}/edit'),
        ];
    }
}
