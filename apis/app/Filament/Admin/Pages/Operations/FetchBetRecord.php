<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Operations;

use App\Enums\AdminOperationEnum;
use App\Enums\Vendor\VendorInfoTypeEnum;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * @knowledge rules/bet-record-pull
 */
class FetchBetRecord extends AbstractOperationPage
{
    protected static ?int $navigationSort = 2;

    public static function operation(): AdminOperationEnum
    {
        return AdminOperationEnum::FetchBetRecord;
    }

    /** 指令每片之間輪詢中斷旗標，等 rate limit 的空窗期也拆段輪詢（見 BetRecordFetchService::CANCEL_POLL_SECONDS） */
    protected function supportsCancelSignal(): bool
    {
        return true;
    }

    /**
     * 接續動作：帶著已填的時間參數跳到「重跑注單彙總」，省得再選一次
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('continueToSummary')
                ->label('接續注單彙總')
                ->icon('heroicon-o-arrow-right-circle')
                ->color('gray')
                ->action(fn () => $this->redirect(SummaryBetRecord::getUrl($this->summaryPrefill()))),
        ];
    }

    /**
     * 帶往「重跑注單彙總」頁的預填參數。
     *
     * 時區／日期／小時原樣沿用；遊戲商只在對方也支援時才帶
     * （該頁清單來自 config/summary_bet_record.php）。
     *
     * @return array<string, mixed>
     */
    private function summaryPrefill(): array
    {
        $data = $this->data ?? [];

        // 0 點是合法值，只濾掉 null 與空字串
        $prefill = array_filter(
            [
                'timezone' => $data['timezone'] ?? null,
                'date' => $data['date'] ?? null,
                'hour' => $data['hour'] ?? null,
            ],
            fn ($value): bool => $value !== null && $value !== '',
        );

        if (isset(config('summary_bet_record', [])[$data['vendor_id'] ?? 0])) {
            $prefill['vendor_id'] = $data['vendor_id'];
        }

        return $prefill;
    }

    protected function formComponents(): array
    {
        // 只列具備拉注單能力的遊戲商（含未加入排程、僅能 --direct 手動補拉者）
        $fetchableVendorIds = array_keys(array_filter(
            config('vendor.driver', []),
            fn (string $driverClass): bool => is_subclass_of($driverClass, SupportsBetRecordFetch::class),
        ));

        return [
            Select::make('vendor_id')
                ->label('遊戲商')
                ->options(
                    Vendor::query()
                        ->whereIn('id', $fetchableVendorIds)
                        ->orderBy('id')
                        ->pluck('name', 'id')
                        ->map(fn (string $name, int $id): string => sprintf('(%d) %s', $id, $name)),
                )
                // Filament Select 預設 optionsLimit=50，聚合遊戲商（Titan）會撐爆清單
                ->optionsLimit(500)
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    // 換遊戲商時把時區帶成該商的 calc_time_zone
                    $set('timezone', self::vendorTimezoneOption($state));
                    // 商戶隸屬於遊戲商，換商就把已選商戶歸零
                    $set('vendor_info_id', null);
                    // 單一錢包只能乾跑，選到就直接把開關打開（下方 Toggle 同步鎖住）
                    if (self::isSingleWallet($state)) {
                        $set('dry_run', true);
                    }
                })
                ->required(),
            Select::make('vendor_info_id')
                ->label('商戶')
                ->placeholder('全部商戶')
                ->helperText('留空 = 該遊戲商全部啟用中商戶；只有單一商戶憑證出問題時可指定補拉')
                ->options(fn (Get $get): array => self::vendorInfoOptions($get('vendor_id'))),
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
                ->default(fn (): string => Carbon::now(self::DEFAULT_TIMEZONE)->subHour()->format('Y-m-d'))
                // 運維只能回溯 MAX_DAYS_AGO 天（target/tickets#2887：再舊的注單三方多半已經不回了）；
                // 管理員可回溯 ADMIN_MAX_DAYS_AGO 天（target/tickets#4472），對應三方約 60 天的保留期，
                // 個別遊戲商保留期更短時 Driver 的 betRecordMinStartTime() 會自動收斂起點
                ->minDate(fn (): Carbon => now()->subDays(self::maxDaysAgo()))
                ->maxDate(now())
                ->helperText(fn (): string => self::isDateRangeLimited()
                    ? sprintf('運維只能回溯 %d 天', self::MAX_DAYS_AGO)
                    : sprintf('管理員可回溯 %d 天；超過遊戲商的資料保留期會拉不到注單', self::ADMIN_MAX_DAYS_AGO))
                ->required(),
            Select::make('hour')
                ->label('小時')
                ->placeholder('整天（00:00 ～ 24:00）')
                ->helperText('留空即拉取該日整天；時間依上方時區換算為 UTC 後送出。整個窗口同步逐片拉完後回寫輸出（rate limit 用罄時原地等待，窗口越大耗時越久）')
                ->options(
                    collect(range(0, 23))
                        ->mapWithKeys(fn (int $hour): array => [
                            $hour => sprintf('%02d:00 ～ %02d:00', $hour, $hour + 1),
                        ])
                        ->all(),
                ),
            Toggle::make('update')
                ->label('更新既有列')
                ->helperText('金額有異動的既有注單列一併更新並重轉 TBR（取消單靠此生效）；預設只新增不動既有列'),
            Toggle::make('dry_run')
                ->label('乾跑模式')
                // 單一錢包的注單由回調即時記帳，拉單寫入會生出沒有錢包異動的孤兒注單，只放行比對
                ->disabled(fn (Get $get): bool => self::isSingleWallet($get('vendor_id')))
                ->dehydrated()
                ->helperText(fn (Get $get): string => self::isSingleWallet($get('vendor_id'))
                    ? '單一錢包遊戲商只能乾跑：注單已由回調即時記帳，拉單僅供三方 ↔ DB 比對，不可寫入'
                    : '只打三方與 DB 比對、不寫入：列出將新增的注單、（搭配「更新既有列」）金額有異動的既有列與各欄位差異，以及反向提示「DB 有、三方整個窗口沒回」的注單；每類最多列 500 筆'),
            Toggle::make('use_cache')
                ->label('使用三方回應快取')
                ->default(true)
                ->helperText('三方回應快取 1 小時：乾跑看完差異後在時限內跑正式模式直接沿用、不再打三方也不佔 rate limit；拉取失敗不會進快取'),
        ];
    }

    /**
     * 是否為單一錢包遊戲商（乾跑鎖定用）
     */
    private static function isSingleWallet(mixed $vendorId): bool
    {
        if (!$vendorId) {
            return false;
        }

        return Vendor::query()->whereKey((int) $vendorId)->value('is_single_wallet') === 1;
    }

    /**
     * 該遊戲商啟用中的一般商戶選項（活動商戶另有帳，不列）。
     *
     * @return array<int, string>
     */
    private static function vendorInfoOptions(mixed $vendorId): array
    {
        if (!$vendorId) {
            return [];
        }

        return VendorInfo::query()
            ->where('vendor_id', (int) $vendorId)
            ->where('status', 1)
            ->where('type', '!=', VendorInfoTypeEnum::EVENT_V2->value)
            ->orderBy('id')
            ->get(['id', 'name', 'currency'])
            ->mapWithKeys(fn (VendorInfo $info): array => [
                $info->id => sprintf('(%d) %s %s', $info->id, $info->name, $info->currency),
            ])
            ->all();
    }

    protected function buildParams(array $data): array
    {
        $timezone = $data['timezone'] ?? self::DEFAULT_TIMEZONE;
        $date = Carbon::parse($data['date'], $timezone)->startOfDay();

        // 0 點是合法值，不可用 empty() 判斷
        $hasHour = ($data['hour'] ?? null) !== null && $data['hour'] !== '';

        $start = $hasHour ? $date->copy()->addHours((int) $data['hour']) : $date;
        $end = $hasHour ? $start->copy()->addHour() : $date->copy()->addDay();

        // 指令的時間參數為 UTC，格式 yyyy-MM-dd@HH；
        // --direct：同步執行不入 queue，執行輸出得以回寫 admin_operation_logs 供頁面顯示；
        // 乾跑以 --dry-run 取代 --direct（同為同步執行，但整趟回滾）
        // 單一錢包一律乾跑：頁面上的開關已鎖住，這裡是最終把關（表單狀態被繞過也不會寫入）
        $dryRun = !empty($data['dry_run']) || self::isSingleWallet($data['vendor_id'] ?? null);

        $params = [
            '--vendor' => (string) $data['vendor_id'],
            '--start' => $start->setTimezone('UTC')->format('Y-m-d@H'),
            '--end' => $end->setTimezone('UTC')->format('Y-m-d@H'),
            $dryRun ? '--dry-run' : '--direct' => true,
        ];

        if (!empty($data['vendor_info_id'])) {
            $params['--vendor-info'] = (string) $data['vendor_info_id'];
        }

        if (!empty($data['update'])) {
            $params['--update'] = true;
        }

        if (!empty($data['use_cache'])) {
            $params['--use-cache'] = true;
        }

        return $params;
    }
}
