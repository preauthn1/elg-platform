<?php

declare(strict_types=1);

namespace App\Console\Commands\SummaryBetRecord;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 遊戲下注記錄每小時彙總指令（新版）
 *
 * ## 執行流程
 *
 * ```
 * handle()
 *   │
 *   ├─ 1. parseArguments()      ：解析參數，組出 UTC+0 時間範圍清單（$timeRanges）
 *   │
 *   ├─ 2. Runner::prepareSharedData()：載入所有廠商共用資料
 *   │       （排除遊戲、V-Jackpot 抽水比例、廠商盤費）
 *   │
 *   ├─ 3. 依 $timeRanges 逐小時、逐廠商執行 Runner::runVendorBucket()
 *   │       ├─ 取得 Lock（同一廠商同一小時防重複執行）
 *   │       ├─ 開啟 DB Transaction
 *   │       ├─ 建立 SummaryBetRecordService 並注入共用資料
 *   │       │     ├─ 未關帳：刪除舊資料後重新寫入 summary_bet_records
 *   │       │     └─ 已關帳：只寫入與現有資料的差異（調帳模式）
 *   │       └─ Commit / Rollback，解除 Lock
 *   │
 *   ├─ 4. 未關帳的時間範圍：每個範圍結束即以本次 start 執行彙總結算
 *   │       （Runner::runSummarySettlement：team ＋ 滾碼）
 *   │
 *   └─ 5. 已關帳的時間範圍：全部範圍跑完後，以關帳時間只執行一次彙總結算
 *           （調帳列一律寫在 summary_time = 關帳時間那一格，結算對該格刪除重建；
 *            逐小時跑的前 N-1 次結果都會被下一次 DELETE 掉，故集中到最後跑一次）
 * ```
 *
 * 執行核心（共用資料載入、單廠商單小時彙總、SP 結算）已抽至 {@see SummaryBetRecordRunner}，
 * 與整點排程的 queue job（SummarizeBetRecordHour，target/tickets#4257）共用；本命令保留
 * CLI 參數解析、時間範圍展開與關帳彙整編排。
 *
 * ## 參數說明（輸入時區由 `--timezone` 決定，預設 UTC+8，內部自動轉 UTC+0）
 *
 * | 參數組合 | 行為 |
 * |---|---|
 * | 無參數 | 今天整天 24 小時 |
 * | `--prev-hour` | 上一小時 —— 排程專用 |
 * | `--yesterday` | 昨天整天 24 小時 |
 * | `--date=Y-m-d` | 指定日期整天 24 小時 |
 * | `--date=Y-m-d --hour=H` | 指定日期＋小時 |
 * | `--hour=H` | 今天指定小時 |
 * | `--timezone=UTC` | 上述日期／小時改以 UTC 解讀（整天即 UTC 00:00 ～ 24:00） |
 *
 * 未指定 `--vendor`（全廠商）時，`config/summary_bet_record.php` 設為
 * `schedule_enabled => false` 的廠商會被跳過；明確指定 `--vendor` 則照跑。
 *
 * ⚠️ 關帳判斷仍以 UTC+8 日界為準（見下），不受 `--timezone` 影響——
 * 該選項只決定「輸入的日期／小時」如何換算成 UTC 時間範圍。
 *
 * ## 關帳判斷
 *
 * - 關帳時間（UTC）= 當日台北時間 00:00 減 8 小時 → 前一日 16:00 UTC
 * - start < 關帳時間 → 已關帳，使用調帳模式（correction = 1）
 * - start ≥ 關帳時間 → 未關帳，直接覆蓋
 *
 * ## Dry-run 模式（`--dry-run`）
 *
 * - 照常執行彙總邏輯以計算並輸出「異動筆數」
 * - 完成後 rollback transaction，不實際寫入 `summary_bet_records`
 * - 完全跳過彙總結算（Runner::runSummarySettlement，team ＋ 滾碼）
 *
 * ## 輸出說明
 *
 * 依關帳狀態，輸出格式分兩種模式：
 *
 * ### 未關帳模式（直接覆蓋）
 *
 * ```
 * 彙總寫入：N 筆
 * ```
 *
 * 該小時 SBR 全量刪除後重寫，N = 本次計算寫入筆數。
 *
 * ### 已關帳模式（調帳，correction = 1）
 *
 * ```
 * 調帳寫入：新增或補差值 N 筆，沖銷舊記錄 M 筆
 * ```
 *
 * | 欄位 | 觸發條件 | 寫入內容 |
 * |---|---|---|
 * | **新增或補差值** | 本次計算有該記錄，且與 DB 現有值不同（或為全新） | 直接寫入新記錄，或寫入「新值 − 舊值」的差值 |
 * | **沖銷舊記錄** | DB 現有記錄在本次計算中已不存在（例如第三方撤銷） | 補寫一筆所有欄位皆取負的記錄，使兩筆加總歸零 |
 *
 * 兩者均為 0 代表本次計算結果與 DB 完全一致，無需寫入。
 *
 * @example php artisan summary_bet_record --date=2025-12-30 --hour=14 --vendor=179
 * @example php artisan summary_bet_record --date=2025-12-30              # 整天 24 小時
 * @example php artisan summary_bet_record --prev-hour                    # 上一小時（排程用）
 * @example php artisan summary_bet_record --yesterday                    # 昨天整天 24 小時
 * @example php artisan summary_bet_record                                # 今天整天 24 小時
 * @example php artisan summary_bet_record --prev-hour --dry-run          # 試跑上一小時，只看差異不寫入
 * @example php artisan summary_bet_record --date=2025-12-30 --timezone=UTC # 該 UTC 日整天 24 小時
 *
 * @knowledge rules/summary-bet-record
 */
class SummaryBetRecordCommand extends Command
{
    /** --vendor 指定為此值代表執行全部廠商 */
    private const int VENDOR_ALL = 0;

    /** --timezone 允許的輸入時區（與 Olympus 操作頁時區選項、config reconcile_timezone 對齊） */
    private const array TIMEZONES = ['+08:00', 'UTC', '-04:00', '+07:00'];

    /** --timezone 預設值：沿用歷來以台北時間解讀日期／小時的行為 */
    private const string DEFAULT_TIMEZONE = '+08:00';

    /**
     * --date     UTC+8 日期（Y-m-d）；未指定時預設今天
     * --hour     UTC+8 小時（0-23）；未指定時跑整天 24 小時
     * --prev-hour 旗標：執行上一個 UTC+8 小時（優先級最高，排程用）
     * --yesterday 旗標：執行昨天（UTC+8）整天 24 小時
     * --vendor   廠商 ID；0 或未指定代表全部廠商
     * --dry-run  旗標：試跑模式，只計算差異不寫入 DB，亦不執行 SP 彙總
     * --timezone 日期／小時的輸入時區（+08:00、UTC、-04:00、+07:00）；未指定時預設 +08:00
     */
    protected $signature = 'summary_bet_record {--date=} {--hour=} {--prev-hour} {--yesterday} {--vendor=} {--dry-run} {--timezone=}';

    protected $description = '遊戲記錄每個人每小時每款遊戲結算 - 新版';

    /** 彙總執行核心（與整點排程 queue job 共用；見 SummaryBetRecordRunner） */
    private SummaryBetRecordRunner $runner;

    /** 本次是否有已關帳的時間範圍——有則於所有範圍跑完後，以關帳時間補跑一次 SP */
    private bool $needsCutoffSpRun = false;

    /** @var array<int, array{0: string, 1: string}> 本次執行的 UTC+0 時間範圍清單 [[start, end], ...] */
    private array $timeRanges = [];

    private int $vendorId;

    private bool $dryRun = false;

    /** 輸入日期／小時所屬時區與 UTC 的秒差 */
    private int $timezoneOffsetSeconds;

    /** @var bool 記錄執行過程中是否有失敗，用於決定 exit code */
    private bool $hasExecutionFailures = false;

    public function handle(): int
    {
        try {
            $this->parseArguments();
        } catch (ValidationException $e) {
            $this->displayValidationErrors($e);

            return 1;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        } catch (Throwable $e) {
            $this->error('解析參數失敗：' . $e->getMessage());

            return 1;
        }

        $configs = config('summary_bet_record');

        if ($this->vendorId !== self::VENDOR_ALL && !isset($configs[$this->vendorId])) {
            $this->error(sprintf('廠商 ID：%d 未在設定中定義，停止執行', $this->vendorId));

            return 1;
        }

        if ($this->dryRun) {
            $this->warn('※ Dry-run 模式：計算差異但不寫入 DB，且跳過 SP 彙總');
        }

        $this->line('');
        $this->line('  輸出說明：');
        $this->line('  ・未關帳時段（直接覆蓋模式）：');
        $this->line('    彙總寫入 N 筆 = 該小時全量重寫筆數');
        $this->line('  ・已關帳時段（調帳模式，correction = 1）：');
        $this->line('    新增或補差值：本次有、且與 DB 現值不同（全新 or 有差異）');
        $this->line('    沖銷舊記錄：DB 有、但本次已無（第三方撤銷等），補寫負值歸零');
        $this->line('    兩者均為 0 → 本次計算與 DB 完全一致，無需寫入。');
        $this->line('');
        $this->runner = app(SummaryBetRecordRunner::class)->setOutput($this->output);
        $this->runner->prepareSharedData();
        $this->needsCutoffSpRun = false;

        foreach ($this->timeRanges as [$start, $end]) {
            $this->processTimeRange($start, $end, $configs);
        }

        if ($this->needsCutoffSpRun) {
            $this->executeSpSummarySafely(SummaryBetRecordService::getDailyCutoffTime());
        }

        return $this->hasExecutionFailures ? 1 : 0;
    }

    /**
     * 輸出 Validator 所有錯誤訊息。
     */
    private function displayValidationErrors(ValidationException $e): void
    {
        foreach ($e->errors() as $messages) {
            foreach ($messages as $message) {
                $this->error($message);
            }
        }
    }

    /**
     * 處理單一時間範圍：逐廠商彙總，未關帳者結束後即執行 SP 結算（Dry-run 跳過 SP）。
     *
     * 已關帳的範圍不在此跑 SP：其調帳列全部落在 summary_time = 關帳時間同一格，
     * 只需在 handle() 全部範圍跑完後以關帳時間執行一次。
     *
     * @param  array<int, array{third_party_code: string, time_field: string, service_class?: class-string<SummaryBetRecordService>}>  $configs
     */
    private function processTimeRange(string $start, string $end, array $configs): void
    {
        $border = str_repeat('═', 60);
        $this->newLine();
        $this->info($border);
        $this->info(sprintf(' 時間範圍 (UTC)：%s ～ %s', $start, $end));
        $this->info($border);

        $this->executeVendors($start, $end, $configs);

        if ($this->dryRun) {
            return;
        }

        if (SummaryBetRecordService::hasPastCutoff($start)) {
            $this->needsCutoffSpRun = true;

            return;
        }

        $this->executeSpSummarySafely($start);
    }

    /**
     * 依 vendorId 決定執行全廠商或單一廠商。
     *
     * @param  array<int, array{third_party_code: string, time_field: string, service_class?: class-string<SummaryBetRecordService>}>  $configs
     */
    private function executeVendors(string $start, string $end, array $configs): void
    {
        $targets = $this->vendorsForRun($configs);

        // 只有全廠商執行時才需要說明誰被跳過；指定 --vendor 時「沒跑到的」不是因為未納入排程
        if ($this->vendorId === self::VENDOR_ALL) {
            $skipped = array_diff(array_keys($configs), array_keys($targets));

            if ($skipped !== []) {
                $this->line(sprintf('  跳過未納入排程（schedule_enabled = false）的廠商：%s', implode(', ', $skipped)));
            }
        }

        foreach ($targets as $vendorId => $config) {
            $this->executeVendorSafely($start, $end, $vendorId, $config);
        }
    }

    /**
     * 本次要執行的廠商清單。
     *
     * `--vendor` 明確指定時只跑該廠商（即使未納入排程）；未指定（全廠商）時，
     * `schedule_enabled => false` 的廠商不隨排程執行，僅供後台或 CLI 指定 --vendor 補跑。
     *
     * @param  array<int, array{third_party_code: string, time_field: string, service_class?: class-string<SummaryBetRecordService>, schedule_enabled?: bool}>  $configs
     * @return array<int, array{third_party_code: string, time_field: string, service_class?: class-string<SummaryBetRecordService>, schedule_enabled?: bool}>
     */
    private function vendorsForRun(array $configs): array
    {
        if ($this->vendorId !== self::VENDOR_ALL) {
            return [$this->vendorId => $configs[$this->vendorId]];
        }

        return SummaryBetRecordRunner::scheduledConfigs($configs);
    }

    /**
     * 執行單一廠商，並將例外轉換為錯誤日誌與失敗旗標，不中斷其他廠商。
     *
     * @param  array{third_party_code: string, time_field: string, service_class?: class-string<SummaryBetRecordService>}  $config
     */
    private function executeVendorSafely(string $start, string $end, int $vendorId, array $config): void
    {
        try {
            $this->runner->runVendorBucket(
                vendorId: $vendorId,
                config: $config,
                start: $start,
                end: $end,
                dryRun: $this->dryRun,
            );
        } catch (Throwable $e) {
            $this->hasExecutionFailures = true;
            $this->error(sprintf('廠商 %d 執行失敗：%s', $vendorId, $e->getMessage()));
        }
    }

    /**
     * 執行 SP 彙總結算，並將例外轉換為錯誤日誌與失敗旗標。
     */
    private function executeSpSummarySafely(string $summaryTime): void
    {
        try {
            $this->runner->runSummarySettlement($summaryTime);
        } catch (Throwable $e) {
            $this->hasExecutionFailures = true;
            $this->error('彙總結算失敗：' . $e->getMessage());
        }
    }

    /**
     * 解析命令列參數，結果寫入 `$this->timeRanges`（UTC+0 時間範圍清單）。
     *
     * 日期／小時一律以 `--timezone`（預設 +08:00）解讀，內部轉為 UTC+0。
     *
     * 優先級（高→低）：
     * 1. `--prev-hour`：上一個小時
     * 2. `--yesterday`：昨天整天 24 小時
     * 3. `--date` + `--hour`：指定日期＋小時
     * 4. `--date` 單獨：指定日期整天 24 小時
     * 5. `--hour` 單獨：今天指定小時
     * 6. 無參數：今天整天 24 小時
     */
    private function parseArguments(): void
    {
        // 收集所有參數
        $options = [
            'vendor' => $this->option('vendor'),
            'date' => $this->option('date'),
            'hour' => $this->option('hour'),
            'prev-hour' => $this->option('prev-hour'),
            'yesterday' => $this->option('yesterday'),
            'dry-run' => $this->option('dry-run'),
            'timezone' => $this->option('timezone'),
        ];

        // 使用 Laravel Validator 驗證參數
        $validator = Validator::make($options, [
            'vendor' => 'nullable|integer|min:0',
            'date' => [
                'nullable',
                'date_format:Y-m-d',
                'before_or_equal:' . date('Y-m-d', strtotime('+1 year')), // 防止過未來的日期
                'after_or_equal:2020-01-01', // 防止過去太久的日期
            ],
            'hour' => 'nullable|integer|between:0,23',
            'prev-hour' => 'nullable|boolean',
            'yesterday' => 'nullable|boolean',
            'dry-run' => 'nullable|boolean',
            'timezone' => 'nullable|in:' . implode(',', self::TIMEZONES),
        ], [
            'vendor.integer' => '--vendor 參數必須為整數',
            'vendor.min' => '--vendor 參數不能為負數',
            'date.date_format' => '--date 參數格式錯誤，請使用 Y-m-d 格式（例如：2025-12-30）',
            'date.before_or_equal' => '--date 參數不能超過一年後',
            'date.after_or_equal' => '--date 參數不能早於 2020-01-01',
            'hour.integer' => '--hour 參數必須為整數',
            'hour.between' => '--hour 參數必須介於 0-23 之間',
            'timezone.in' => '--timezone 參數只接受：' . implode('、', self::TIMEZONES),
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // 互斥旗標驗證（Validator 無法直接表達跨欄位互斥，手動處理）
        $mutuallyExclusiveFlags = array_filter([
            $options['prev-hour'] ? '--prev-hour' : null,
            $options['yesterday'] ? '--yesterday' : null,
            ($options['date'] !== null) ? '--date' : null,
        ]);

        if (count($mutuallyExclusiveFlags) > 1) {
            throw new \InvalidArgumentException(
                '--prev-hour、--yesterday、--date 為互斥參數，只能擇一使用（傳入：' . implode('、', $mutuallyExclusiveFlags) . '）'
            );
        }

        // 驗證通過後設定屬性
        $this->vendorId = $options['vendor'] ? (int) $options['vendor'] : self::VENDOR_ALL;
        $this->dryRun = (bool) $options['dry-run'];
        $this->timezoneOffsetSeconds = (new \DateTimeZone($options['timezone'] ?: self::DEFAULT_TIMEZONE))
            ->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));

        // 根據優先級處理時間參數
        if ($options['prev-hour']) {
            $localPrevHour = date('Y-m-d H:00:00', strtotime('-1 hour', time() + $this->timezoneOffsetSeconds));
            $this->timeRanges = [$this->buildUtcRange($localPrevHour)];

            return;
        }

        if ($options['yesterday']) {
            $yesterday = date('Y-m-d', strtotime('-1 day', time() + $this->timezoneOffsetSeconds));
            $this->timeRanges = $this->buildDayRanges($yesterday);

            return;
        }

        $dateOption = $options['date'] ?? date('Y-m-d', time() + $this->timezoneOffsetSeconds);
        $hourOption = $options['hour'];

        if ($hourOption !== null) {
            $hour = str_pad((string) (int) $hourOption, 2, '0', STR_PAD_LEFT);
            $this->timeRanges = [$this->buildUtcRange("{$dateOption} {$hour}:00:00")];

            return;
        }

        $this->timeRanges = $this->buildDayRanges($dateOption);
    }

    /**
     * 將輸入時區的時間轉換為單一小時的 UTC+0 時間範圍。
     *
     * @param  string  $localStart  輸入時區的時間，格式 `Y-m-d H:00:00`
     * @return array{0: string, 1: string}
     */
    private function buildUtcRange(string $localStart): array
    {
        $startUtc = date('Y-m-d H:i:s', strtotime($localStart) - $this->timezoneOffsetSeconds);
        $endUtc = date('Y-m-d H:i:s', strtotime($startUtc . ' +1 hour'));

        return [$startUtc, $endUtc];
    }

    /**
     * 建立指定日期（輸入時區）的 24 個小時 UTC+0 時間範圍清單。
     *
     * @param  string  $localDate  輸入時區的日期，格式 `Y-m-d`
     * @return array<int, array{0: string, 1: string}>
     */
    private function buildDayRanges(string $localDate): array
    {
        $ranges = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $localStart = $localDate . ' ' . str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':00:00';
            $ranges[] = $this->buildUtcRange($localStart);
        }

        return $ranges;
    }
}
