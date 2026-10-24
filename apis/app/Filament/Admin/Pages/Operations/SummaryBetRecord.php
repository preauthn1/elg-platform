<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Operations;

use App\Enums\AdminOperationEnum;
use App\Filament\Admin\Pages\Queries\BetRecordCorrection;
use App\Models\Vendor;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;

/**
 * 重跑指定日期的注單彙總，可只跑其中一個小時。
 *
 * 時間輸入可選時區（預設 UTC+8），連同日期／小時原樣交給指令的 --timezone 解讀；
 * 「整天」即該時區的 00:00 ～ 24:00。
 *
 * 舊專案 vendor_summary_daily_rerun 的後台版：逐小時迴圈與各遊戲商時區差異
 * 已內建於 summary_bet_record 指令，這裡只負責帶入遊戲商與日期。
 *
 * @knowledge rules/summary-bet-record
 */
class SummaryBetRecord extends AbstractOperationPage
{
    /** 本頁時區選項 → 補帳查詢頁的時差小時數 */
    private const array CORRECTION_TIMEZONE_OFFSETS = [
        '-04:00' => -4,
        'UTC' => 0,
        '+07:00' => 7,
        '+08:00' => 8,
    ];

    protected static ?int $navigationSort = 3;

    public static function operation(): AdminOperationEnum
    {
        return AdminOperationEnum::SummaryBetRecord;
    }

    /**
     * 由「拉取注單」頁接續過來時，沿用網址帶的時區／日期／小時（與遊戲商，若對方帶得過來）
     */
    protected function prefillableKeys(): array
    {
        return ['vendor_id', 'timezone', 'date', 'hour'];
    }

    /**
     * 接續動作：帶著已填的參數跳到「補帳查詢」，省得再選一次
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('continueToCorrection')
                ->label('接續補帳查詢')
                ->icon('heroicon-o-arrow-right-circle')
                ->color('gray')
                ->action(fn () => $this->redirect(BetRecordCorrection::getUrl($this->correctionPrefill()))),
        ];
    }

    /**
     * 帶往「補帳查詢」頁的預填參數。
     *
     * 日期原樣沿用、時區換成該頁的時差小時數；小時不帶（該頁以日為單位）；
     * 遊戲商只在對方也列出時才帶（該頁清單來自 config/vendor.php 的 driver）。
     *
     * @return array<string, mixed>
     */
    private function correctionPrefill(): array
    {
        $data = $this->data ?? [];

        $prefill = array_filter(
            [
                'timezone' => self::CORRECTION_TIMEZONE_OFFSETS[$data['timezone'] ?? ''] ?? null,
                'date' => $data['date'] ?? null,
            ],
            fn ($value): bool => $value !== null && $value !== '',
        );

        if (isset(config('vendor.driver', [])[$data['vendor_id'] ?? 0])) {
            $prefill['vendor_id'] = $data['vendor_id'];
        }

        return $prefill;
    }

    protected function formComponents(): array
    {
        return [
            Select::make('vendor_id')
                ->label('遊戲商')
                ->options(
                    Vendor::query()
                        // 僅列 config/summary_bet_record.php 有定義的遊戲商，
                        // 其餘遊戲商走各自專屬補帳服務，選了也會被指令擋下
                        ->whereIn('id', array_keys(config('summary_bet_record', [])))
                        ->where('name', '!=', '')
                        ->orderBy('id')
                        ->pluck('name', 'id')
                        ->map(fn (string $name, int $id): string => sprintf('(%d) %s', $id, $name)),
                )
                ->searchable()
                // 換遊戲商時把時區帶成該商的 calc_time_zone
                ->live()
                ->afterStateUpdated(fn (Set $set, mixed $state): mixed => $set('timezone', self::vendorTimezoneOption($state)))
                ->required(),
            Select::make('timezone')
                ->label('時區')
                ->helperText('選擇遊戲商時自動帶該商的 calc_time_zone，可自行改選')
                ->options(self::TIMEZONES)
                ->default(self::DEFAULT_TIMEZONE)
                ->selectablePlaceholder(false)
                ->required(),
            DatePicker::make('date')
                ->label('日期')
                // 原生 date input 只有右側圖示能開月曆，改用 Filament 自己的 picker 讓點欄位就跳出
                ->native(false)
                // 預設顯示 'M j, Y'（Aug 17, 2026），改成 Y-m-d 比較直觀
                ->displayFormat('Y-m-d')
                ->default(fn (): string => Carbon::now(self::DEFAULT_TIMEZONE)->subDay()->format('Y-m-d'))
                // 運維只能回溯 MAX_DAYS_AGO 天（target/tickets#2887）：更舊的日期重跑成本高，走人工評估；
                // 管理員可回溯 ADMIN_MAX_DAYS_AGO 天（target/tickets#4472，補舊月份對帳用）；兩者仍會經 CONFIRM_DAYS_AGO 的確認視窗。
                ->minDate(fn (): Carbon => now()->subDays(self::maxDaysAgo()))
                ->helperText(fn (): string => self::isDateRangeLimited()
                    ? sprintf('運維只能回溯 %d 天', self::MAX_DAYS_AGO)
                    : sprintf('管理員可回溯 %d 天；重跑一個月前的日期成本高，送出前請確認小時與時區', self::ADMIN_MAX_DAYS_AGO))
                ->required(),
            Select::make('hour')
                ->label('小時')
                ->placeholder('整天（00:00 ～ 24:00）')
                ->helperText('留空即重跑該日整天 24 小時（依上方時區的日界）')
                ->options(
                    collect(range(0, 23))
                        ->mapWithKeys(fn (int $hour): array => [
                            $hour => sprintf('%02d:00 ～ %02d:00', $hour, $hour + 1),
                        ])
                        ->all(),
                ),
            Toggle::make('dry_run')
                ->label('乾跑模式（只算差異不寫入，且跳過 SP 彙總）'),
        ];
    }

    protected function buildParams(array $data): array
    {
        // 日期／小時原樣送出，由指令依 --timezone 解讀
        $params = [
            '--vendor' => (string) $data['vendor_id'],
            '--date' => Carbon::parse($data['date'])->format('Y-m-d'),
            '--timezone' => $data['timezone'] ?? self::DEFAULT_TIMEZONE,
        ];

        // 0 點是合法值，不可用 empty() 判斷
        if (($data['hour'] ?? null) !== null && $data['hour'] !== '') {
            $params['--hour'] = (string) $data['hour'];
        }

        if ($data['dry_run'] ?? false) {
            $params['--dry-run'] = true;
        }

        return $params;
    }
}
