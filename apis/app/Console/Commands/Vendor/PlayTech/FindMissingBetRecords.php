<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PlayTech;

use App\Helpers\Common;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 找出 summary_bet_records 中遺失的記錄
 *
 * ## 用途
 * 當發現 total_bet_records 和 summary_bet_records 之間有差異時，
 * 此命令可以精確定位到具體是哪些記錄遺失或彙總錯誤。
 *
 * ## 使用時機
 * 1. 執行 playtech:compare_bet 發現特定小時有差異時
 * 2. 需要查看某個小時的明細記錄和彙總記錄對比時
 * 3. 調查特定會員的投注是否被正確彙總時
 * 4. 驗證彙總腳本修復後的結果時
 *
 * ## 功能
 * - 顯示指定小時的所有 total_bet_records 明細記錄
 * - 顯示對應的 summary_bet_records 彙總記錄
 * - 計算並顯示金額差異
 * - 按會員分組比對，找出具體哪個會員的投注有問題
 *
 * ## 使用範例
 * ```bash
 * # 查詢特定小時的差異
 * php artisan playtech:find_missing --hour='2026-01-31 15:00:00'
 *
 * # 指定遊戲商ID
 * php artisan playtech:find_missing --vendor=144 --hour='2026-01-31 15:00:00'
 * ```
 *
 * ## 輸出內容
 * 1. total_bet_records 明細記錄表格
 * 2. summary_bet_records 彙總記錄表格
 * 3. 金額差異分析
 * 4. 按會員分組的差異對比（如有差異）
 *
 * @see CompareBetRecordsDifference 用於大範圍的差異檢查
 */
class FindMissingBetRecords extends Command
{
    protected $signature = 'playtech:find_missing
                            {--vendor=144 : 遊戲商ID}
                            {--hour= : 查詢的小時 (YYYY-MM-DD HH:00:00)}';

    protected $description = '找出 summary_bet_records 中遺失的記錄（精確定位特定小時的差異）';

    public function handle(): int
    {
        $vendorId = (int) $this->option('vendor');
        $hour = $this->option('hour') ?? $this->ask('請輸入要查詢的小時 (YYYY-MM-DD HH:00:00)', '2026-01-31 15:00:00');

        $startTime = $hour;
        $endTime = date('Y-m-d H:i:s', strtotime($hour . ' +1 hour'));

        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info(sprintf('🔍 查詢時段: %s ~ %s', $startTime, $endTime));
        $this->info(sprintf('🎮 遊戲商ID: %d', $vendorId));
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('');

        // 1. 查詢 total_bet_records
        $totalRecords = DB::table('total_bet_records')
            ->where('vendor_id', $vendorId)
            ->where('payout_time', '>=', $startTime)
            ->where('payout_time', '<', $endTime)
            ->orderBy('payout_time')
            ->get(['id', 'member_id', 'bet', 'payout_time', 'created_at']);

        $this->info('📋 total_bet_records 明細記錄:');
        $this->table(
            ['ID', 'Member ID', 'Bet', 'Payout Time', 'Created At'],
            $totalRecords->map(fn ($r) => [
                $r->id,
                $r->member_id,
                number_format((float) $r->bet, 4),
                $r->payout_time,
                $r->created_at,
            ])->toArray()
        );

        $totalAmount = $totalRecords->sum('bet');
        $totalCount = $totalRecords->count();
        $this->warn(sprintf('小計: %s 元 (%d 筆)', number_format($totalAmount, 4), $totalCount));
        $this->info('');

        // 2. 查詢 summary_bet_records
        $summaryRecords = DB::table('summary_bet_records')
            ->where('vendor_id', $vendorId)
            ->where('data_time', $startTime)
            ->get(['id', 'member_id', 'bet', 'data_time', 'created_at']);

        $this->info('📊 summary_bet_records 彙總記錄:');
        $this->table(
            ['ID', 'Member ID', 'Bet', 'Data Time', 'Created At'],
            $summaryRecords->map(fn ($r) => [
                $r->id,
                $r->member_id,
                number_format((float) $r->bet, 4),
                $r->data_time,
                $r->created_at,
            ])->toArray()
        );

        $summaryAmount = $summaryRecords->sum('bet');
        $summaryCount = $summaryRecords->count();
        $this->warn(sprintf('小計: %s 元 (%d 筆)', number_format($summaryAmount, 4), $summaryCount));
        $this->info('');

        // 3. 計算差異
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 差異分析');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $diff = Common::numSub((string) $totalAmount, (string) $summaryAmount, 4);

        if (Common::numCompare($diff, '0', 4) === 0) {
            $this->info('✅ 金額完全一致');
        } else {
            $this->error(sprintf('❌ 金額差異: %s 元', $diff));
        }

        $this->info(sprintf('Total 筆數: %d', $totalCount));
        $this->info(sprintf('Summary 筆數: %d', $summaryCount));
        $this->info('');

        // 4. 按會員分組比對
        if (Common::numCompare($diff, '0', 4) !== 0) {
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->info('🔬 按會員分組比對');
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

            // 按會員分組統計 total_bet_records
            $totalByMember = $totalRecords->groupBy('member_id')->map(function ($records) {
                return [
                    'count' => $records->count(),
                    'amount' => $records->sum('bet'),
                ];
            });

            // 按會員分組統計 summary_bet_records
            $summaryByMember = $summaryRecords->groupBy('member_id')->map(function ($records) {
                return [
                    'count' => $records->count(),
                    'amount' => $records->sum('bet'),
                ];
            });

            // 找出差異
            $allMembers = $totalByMember->keys()->merge($summaryByMember->keys())->unique();

            $tableData = [];
            foreach ($allMembers as $memberId) {
                $totalData = $totalByMember->get($memberId, ['count' => 0, 'amount' => 0]);
                $summaryData = $summaryByMember->get($memberId, ['count' => 0, 'amount' => 0]);

                $memberDiff = Common::numSub((string) $totalData['amount'], (string) $summaryData['amount'], 4);
                $hasDiff = Common::numCompare($memberDiff, '0', 4) !== 0;

                if ($hasDiff) {
                    $tableData[] = [
                        'member_id' => $memberId,
                        'total_amount' => number_format((float) $totalData['amount'], 4),
                        'total_count' => $totalData['count'],
                        'summary_amount' => number_format((float) $summaryData['amount'], 4),
                        'summary_count' => $summaryData['count'],
                        'diff' => sprintf('❌ %s', $memberDiff),
                    ];
                }
            }

            if (!empty($tableData)) {
                $this->table(
                    ['Member ID', 'Total Amount', 'Total Count', 'Summary Amount', 'Summary Count', 'Diff'],
                    $tableData
                );

                $this->info('');
                $this->error('⚠️  以上會員的投注額在 summary 中有遺失或差異');
            } else {
                $this->info('✅ 所有會員的金額都一致（雖然總額有差異，可能是精度問題）');
            }
        }

        $this->info('');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        return self::SUCCESS;
    }
}
