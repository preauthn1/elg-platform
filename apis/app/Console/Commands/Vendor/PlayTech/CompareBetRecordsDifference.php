<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PlayTech;

use App\Helpers\Common;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 比對 total_bet_records 和 summary_bet_records 的差異
 *
 * ## 用途
 * 自動化檢查彙總表 (summary_bet_records) 是否正確彙總了明細表 (total_bet_records)，
 * 快速找出哪些時段存在差異，適合大範圍的對帳檢查。
 *
 * ## 使用時機
 * 1. **定期對帳**: 每日執行檢查前一天的數據是否正確彙總
 * 2. **發現異常**: 當報表數據看起來不對時，用此命令快速定位問題時段
 * 3. **彙總腳本修復後**: 驗證修復是否生效
 * 4. **歷史數據檢查**: 批量檢查過去某段時間的數據完整性
 * 5. **跨日問題調查**: 檢查是否有投注因時區或邊界問題被記錄到錯誤日期
 *
 * ## 功能
 * - 比對指定時間範圍內的總投注額
 * - 按小時彙總並逐小時比對，找出具體哪個小時有差異
 * - 檢查前後一天的記錄，發現跨日問題
 * - 顯示詳細的差異分析報告
 * - 可選顯示原始明細記錄和彙總記錄
 *
 * ## 使用範例
 * ```bash
 *
 * # 交互式輸入參數
 * php artisan playtech:compare_bet
 *
 * # 檢查單日數據
 * php artisan playtech:compare_bet --vendor=144 --start='2026-01-22 16:00:00' --end='2026-01-23 16:00:00'
 * ```
 *
 * ## 輸出內容
 * 1. 查詢參數摘要
 * 2. 兩個表的總投注額對比
 * 3. 差異分析（如有差異）
 * 4. 按小時彙總的詳細對比表格
 * 5. 跨日記錄檢查結果
 * 6. 可選的明細記錄展示
 *
 * ## 建議的日常使用
 * ```bash
 * # 添加到 crontab，每日凌晨自動檢查
 * 0 3 * * * php artisan playtech:compare_bet \
 *   --start="$(date -d 'yesterday 00:00:00' '+\%Y-\%m-\%d \%H:\%M:\%S')" \
 *   --end="$(date -d 'today 00:00:00' '+\%Y-\%m-\%d \%H:\%M:\%S')"
 * ```
 *
 * ## 與其他命令的配合
 * - 發現差異後，使用 `playtech:find_missing` 深入調查具體時段
 * - 差異報告可作為修復彙總腳本的依據
 *
 * @see FindMissingBetRecords 用於精確定位特定小時的差異記錄
 */
class CompareBetRecordsDifference extends Command
{
    protected $signature = 'playtech:compare_bet
                            {--vendor= : 遊戲商ID}
                            {--start= : 開始日期時間 (YYYY-MM-DD HH:MM:SS)}
                            {--end= : 結束日期時間 (YYYY-MM-DD HH:MM:SS)}';

    protected $description = '比對 total_bet_records 和 summary_bet_records 的差異（按小時彙總分析，適合大範圍對帳）';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 1. 獲取參數
        $vendorId = $this->getVendorId();
        if ($vendorId === null) {
            return self::FAILURE;
        }

        $dateRange = $this->getDateRange();
        if ($dateRange === null) {
            return self::FAILURE;
        }

        [$startDate, $endDate] = $dateRange;

        // 2. 顯示查詢資訊
        $this->displayQueryInfo($vendorId, $startDate, $endDate);

        // 3. 執行查詢
        try {
            $totalBetSum = $this->getTotalBetRecordsSum($vendorId, $startDate, $endDate);
            $summaryBetSum = $this->getSummaryBetRecordsSum($vendorId, $startDate, $endDate);

            // 4. 獲取詳細記錄數
            $totalCount = $this->getTotalBetRecordsCount($vendorId, $startDate, $endDate);
            $summaryCount = $this->getSummaryBetRecordsCount($vendorId, $startDate, $endDate);
        } catch (Throwable $e) {
            $this->error('查詢失敗: ' . $e->getMessage());

            return self::FAILURE;
        }

        // 5. 顯示結果
        $this->displayResults($totalBetSum, $summaryBetSum, $totalCount, $summaryCount);

        // 6. 如果有差異，顯示詳細分析
        if ($this->hasDifference($totalBetSum, $summaryBetSum)) {
            $this->analyzeDetailedDifference($vendorId, $startDate, $endDate);
        }

        return self::SUCCESS;
    }

    /**
     * 獲取遊戲商ID
     */
    private function getVendorId(): ?int
    {
        $vendorId = $this->option('vendor');

        if ($vendorId === null) {
            $vendorId = $this->ask('請輸入遊戲商ID (vendor_id)', '144');
        }

        if (!is_numeric($vendorId) || $vendorId <= 0) {
            $this->error('遊戲商ID必須是正整數');

            return null;
        }

        return (int) $vendorId;
    }

    /**
     * 獲取日期範圍
     */
    private function getDateRange(): ?array
    {
        $startDate = $this->option('start');
        $endDate = $this->option('end');

        if ($startDate === null) {
            $startDate = $this->ask('請輸入開始日期時間 (YYYY-MM-DD HH:MM:SS)', '2026-01-30 16:00:00');
        }

        if ($endDate === null) {
            $endDate = $this->ask('請輸入結束日期時間 (YYYY-MM-DD HH:MM:SS)', '2026-01-31 16:00:00');
        }

        // 驗證日期格式
        if (!$this->isValidDateTime($startDate) || !$this->isValidDateTime($endDate)) {
            $this->error('日期時間格式錯誤，請使用 YYYY-MM-DD HH:MM:SS 格式');

            return null;
        }

        return [$startDate, $endDate];
    }

    /**
     * 驗證日期時間格式
     */
    private function isValidDateTime(string $dateTime): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dateTime);
    }

    /**
     * 顯示查詢資訊
     */
    private function displayQueryInfo(int $vendorId, string $startDate, string $endDate): void
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 查詢參數');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info(sprintf('遊戲商ID: %d', $vendorId));
        $this->info(sprintf('開始時間: %s', $startDate));
        $this->info(sprintf('結束時間: %s', $endDate));
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('');
    }

    /**
     * 獲取 total_bet_records 的投注總額
     */
    private function getTotalBetRecordsSum(int $vendorId, string $startDate, string $endDate): string
    {
        $result = DB::table('total_bet_records')
            ->where('vendor_id', $vendorId)
            ->where('payout_time', '>=', $startDate)
            ->where('payout_time', '<', $endDate)
            ->selectRaw('COALESCE(SUM(`bet`), 0) as total')
            ->first();

        return (string) ($result->total ?? '0');
    }

    /**
     * 獲取 summary_bet_records 的投注總額
     */
    private function getSummaryBetRecordsSum(int $vendorId, string $startDate, string $endDate): string
    {
        $result = DB::table('summary_bet_records')
            ->where('vendor_id', $vendorId)
            ->where('data_time', '>=', $startDate)
            ->where('data_time', '<', $endDate)
            ->selectRaw('COALESCE(SUM(`bet`), 0) as total')
            ->first();

        return (string) ($result->total ?? '0');
    }

    /**
     * 獲取 total_bet_records 的記錄數
     */
    private function getTotalBetRecordsCount(int $vendorId, string $startDate, string $endDate): int
    {
        return DB::table('total_bet_records')
            ->where('vendor_id', $vendorId)
            ->where('payout_time', '>=', $startDate)
            ->where('payout_time', '<', $endDate)
            ->count();
    }

    /**
     * 獲取 summary_bet_records 的記錄數
     */
    private function getSummaryBetRecordsCount(int $vendorId, string $startDate, string $endDate): int
    {
        return DB::table('summary_bet_records')
            ->where('vendor_id', $vendorId)
            ->where('data_time', '>=', $startDate)
            ->where('data_time', '<', $endDate)
            ->count();
    }

    /**
     * 顯示查詢結果
     */
    private function displayResults(string $totalBetSum, string $summaryBetSum, int $totalCount, int $summaryCount): void
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📈 查詢結果');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info(sprintf('total_bet_records 投注總額: %s (明細記錄數: %d)', $totalBetSum, $totalCount));
        $this->info(sprintf('summary_bet_records 投注總額: %s (彙總記錄數: %d 小時)', $summaryBetSum, $summaryCount));
        $this->warn('💡 註: summary_bet_records 是按小時彙總，記錄數不同是正常的');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // 計算差異
        $difference = Common::numSub($totalBetSum, $summaryBetSum, 4);

        $this->info('');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🔍 差異分析');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if (Common::numCompare($difference, '0', 4) === 0) {
            $this->info('✅ 投注總額完全一致');
        } else {
            $sign = Common::numCompare($difference, '0', 4) > 0 ? '+' : '';
            $this->error(sprintf('❌ 投注總額差異: %s%s (total_bet_records - summary_bet_records)', $sign, $difference));
            $this->warn('⚠️  可能原因: 部分記錄因跨日問題被記錄到其他日期');
        }

        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('');
    }

    /**
     * 檢查是否有差異
     */
    private function hasDifference(string $totalBetSum, string $summaryBetSum): bool
    {
        return Common::numCompare($totalBetSum, $summaryBetSum, 4) !== 0;
    }

    /**
     * 分析詳細差異
     */
    private function analyzeDetailedDifference(int $vendorId, string $startDate, string $endDate): void
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🔬 詳細差異分析');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // 1. 按小時彙總 total_bet_records 並與 summary_bet_records 比對
        $this->compareHourlySummary($vendorId, $startDate, $endDate);

        // 2. 檢查跨日問題
        $this->checkCrossDayRecords($vendorId, $startDate, $endDate);

        // 3. 顯示原始記錄
        if ($this->confirm('是否顯示前 20 筆 total_bet_records 明細記錄？', false)) {
            $this->displayTotalBetRecords($vendorId, $startDate, $endDate);
        }

        if ($this->confirm('是否顯示所有 summary_bet_records 彙總記錄？', false)) {
            $this->displaySummaryBetRecords($vendorId, $startDate, $endDate);
        }
    }

    /**
     * 按小時彙總比對
     */
    private function compareHourlySummary(int $vendorId, string $startDate, string $endDate): void
    {
        $this->info('');
        $this->info('📊 按小時彙總比對');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // 從 total_bet_records 按小時彙總
        $totalHourly = DB::table('total_bet_records')
            ->where('vendor_id', $vendorId)
            ->where('payout_time', '>=', $startDate)
            ->where('payout_time', '<', $endDate)
            ->selectRaw("DATE_FORMAT(payout_time, '%Y-%m-%d %H:00:00') as hour, SUM(bet) as total_bet, COUNT(*) as count")
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        // 從 summary_bet_records 獲取
        $summaryHourly = DB::table('summary_bet_records')
            ->where('vendor_id', $vendorId)
            ->where('data_time', '>=', $startDate)
            ->where('data_time', '<', $endDate)
            ->selectRaw("DATE_FORMAT(data_time, '%Y-%m-%d %H:00:00') as hour, SUM(bet) as total_bet, COUNT(*) as count")
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        // 比對每小時的數據
        $allHours = collect($totalHourly->keys()->merge($summaryHourly->keys())->unique()->sort()->values());

        $tableData = [];
        $hasDifference = false;

        foreach ($allHours as $hour) {
            $totalBet = $totalHourly->get($hour)?->total_bet ?? 0;
            $summaryBet = $summaryHourly->get($hour)?->total_bet ?? 0;
            $totalCount = $totalHourly->get($hour)?->count ?? 0;
            $summaryCount = $summaryHourly->get($hour)?->count ?? 0;

            $diff = Common::numSub((string) $totalBet, (string) $summaryBet, 4);
            $isDifferent = Common::numCompare($diff, '0', 4) !== 0;

            if ($isDifferent) {
                $hasDifference = true;
            }

            $tableData[] = [
                'hour' => $hour,
                'total_bet' => number_format((float) $totalBet, 2),
                'total_count' => $totalCount,
                'summary_bet' => number_format((float) $summaryBet, 2),
                'summary_count' => $summaryCount,
                'diff' => $isDifferent ? sprintf('❌ %s', $diff) : '✅ 0',
            ];
        }

        $this->table(
            ['時間（小時）', 'Total Bet', 'Total 筆數', 'Summary Bet', 'Summary 筆數', '差異'],
            $tableData
        );

        if (!$hasDifference) {
            $this->info('✅ 所有小時的投注額都完全一致');
        }

        $this->info('');
    }

    /**
     * 檢查跨日記錄
     */
    private function checkCrossDayRecords(int $vendorId, string $startDate, string $endDate): void
    {
        $this->info('🔍 檢查跨日記錄');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // 檢查前一天和後一天的記錄
        $previousDayStart = date('Y-m-d H:i:s', strtotime($startDate) - 86400);
        $previousDayEnd = $startDate;
        $nextDayStart = $endDate;
        $nextDayEnd = date('Y-m-d H:i:s', strtotime($endDate) + 86400);

        // 檢查前一天 summary_bet_records
        $previousDaySum = DB::table('summary_bet_records')
            ->where('vendor_id', $vendorId)
            ->where('data_time', '>=', $previousDayStart)
            ->where('data_time', '<', $previousDayEnd)
            ->selectRaw('COALESCE(SUM(`bet`), 0) as total, COUNT(*) as count')
            ->first();

        // 檢查後一天 summary_bet_records
        $nextDaySum = DB::table('summary_bet_records')
            ->where('vendor_id', $vendorId)
            ->where('data_time', '>=', $nextDayStart)
            ->where('data_time', '<', $nextDayEnd)
            ->selectRaw('COALESCE(SUM(`bet`), 0) as total, COUNT(*) as count')
            ->first();

        $this->info(sprintf('前一天 (%s ~ %s): 投注額 %s, 記錄數 %d',
            $previousDayStart,
            $previousDayEnd,
            $previousDaySum->total,
            $previousDaySum->count
        ));

        $this->info(sprintf('後一天 (%s ~ %s): 投注額 %s, 記錄數 %d',
            $nextDayStart,
            $nextDayEnd,
            $nextDaySum->total,
            $nextDaySum->count
        ));

        $this->info('');
    }

    /**
     * 顯示 total_bet_records 記錄
     */
    private function displayTotalBetRecords(int $vendorId, string $startDate, string $endDate): void
    {
        $totalRecords = DB::table('total_bet_records')
            ->where('vendor_id', $vendorId)
            ->where('payout_time', '>=', $startDate)
            ->where('payout_time', '<', $endDate)
            ->select(['id', 'member_id', 'bet', 'payout_time', 'created_at'])
            ->orderBy('id')
            ->limit(20)
            ->get();

        if ($totalRecords->isEmpty()) {
            $this->warn('total_bet_records 沒有找到記錄');

            return;
        }

        $this->info('');
        $this->info('total_bet_records 前 20 筆記錄：');
        $this->table(
            ['ID', 'Member ID', 'Bet', 'Bet Time', 'Created At'],
            $totalRecords->map(fn ($record) => [
                $record->id,
                $record->member_id,
                $record->bet,
                $record->payout_time,
                $record->created_at,
            ])->toArray()
        );
    }

    /**
     * 顯示 summary_bet_records 記錄
     */
    private function displaySummaryBetRecords(int $vendorId, string $startDate, string $endDate): void
    {
        $summaryRecords = DB::table('summary_bet_records')
            ->where('vendor_id', $vendorId)
            ->where('data_time', '>=', $startDate)
            ->where('data_time', '<', $endDate)
            ->select(['id', 'member_id', 'bet', 'data_time', 'created_at'])
            ->orderBy('data_time')
            ->get();

        if ($summaryRecords->isEmpty()) {
            $this->warn('summary_bet_records 沒有找到記錄');

            return;
        }

        $this->info('');
        $this->info(sprintf('summary_bet_records 所有 %d 筆記錄：', $summaryRecords->count()));
        $this->table(
            ['ID', 'Member ID', 'Bet', 'Data Time', 'Created At'],
            $summaryRecords->map(fn ($record) => [
                $record->id,
                $record->member_id,
                $record->bet,
                $record->data_time,
                $record->created_at,
            ])->toArray()
        );
    }
}
