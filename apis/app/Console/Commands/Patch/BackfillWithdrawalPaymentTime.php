<?php

declare(strict_types=1);

namespace App\Console\Commands\Patch;

use App\Enums\WithdrawalOrderStatusEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 一次性回填 withdrawal_orders.payment_time（僅 status=24 且目前為 NULL 的訂單）。
 *
 * apis 的三方回調成功路徑（WithdrawalCallbackTrait::updateWithdrawalOrderStatus）過去從未寫入
 * payment_time（只有後台人工轉帳 TRANSFERRED(4) 有寫，見 API_Agent WithdrawalController）；已補上
 * 「沒值才寫」的邏輯供之後即時新增，但既有歷史單仍缺這個欄位，需一次性回填。
 *
 * 來源優先序：
 *  - 有對應 withdrawal_payout_records（is_success_payout=1）：取「第一筆」（id 最小）的 callback_time。
 *    ⚠️ 不可用 transaction_time——該欄位為三方回傳原始字串，格式/時區依代付商不同（部分代付商為 +8，
 *    部分為 UTC 已排查證實），直接使用會造成時區污染；callback_time 是我方系統寫入的正規 UTC
 *    datetime，一致可靠。
 *  - 無對應 payout 記錄（少量邊界情況）：退而用 withdrawal_orders.created_at。
 *
 * 效能：以 bulk UPDATE...JOIN 分批處理（非逐列更新），每批 2 條 SQL 涵蓋整批 id。
 * 冪等性：WHERE payment_time IS NULL，只補未寫過的列，重跑安全。
 *
 * 升級用法：
 *   php artisan patch:backfill-withdrawal-payment-time --dry-run
 *   php artisan patch:backfill-withdrawal-payment-time
 *
 * target/tickets#3967
 */
class BackfillWithdrawalPaymentTime extends Command
{
    protected $signature = 'patch:backfill-withdrawal-payment-time
        {--chunk-size=5000 : 每批處理筆數}
        {--dry-run : 只預覽將回填的筆數，不寫入}';

    protected $description = '一次性回填 withdrawal_orders.payment_time（status=24 且目前為 NULL）';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk-size'));
        $successStatus = WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value;

        $totalTarget = DB::table('withdrawal_orders')
            ->where('status', $successStatus)
            ->whereNull('payment_time')
            ->count();

        if ($totalTarget === 0) {
            $this->info('沒有需要回填的訂單。');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $withPayout = DB::table('withdrawal_orders as wo')
                ->join('withdrawal_payout_records as wpr', 'wpr.withdrawal_order_id', 'wo.id')
                ->where('wo.status', $successStatus)
                ->whereNull('wo.payment_time')
                ->where('wpr.is_success_payout', 1)
                ->distinct()
                ->count('wo.id');

            $this->warn('⚠️  DRY RUN：不寫入資料庫');
            $this->info(sprintf(
                '待回填訂單數：%d（可用 payout 記錄回填 %d 筆，其餘 %d 筆將退用 created_at）',
                $totalTarget,
                $withPayout,
                $totalTarget - $withPayout
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf('待回填訂單數：%d（每批 %d 筆）', $totalTarget, $chunkSize));

        $processed = 0;
        $lastId = 0;

        while (true) {
            $ids = DB::table('withdrawal_orders')
                ->where('status', $successStatus)
                ->whereNull('payment_time')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $idList = $ids->all();
            $placeholders = implode(',', array_fill(0, count($idList), '?'));

            // 有對應成功 payout 記錄：取第一筆（id 最小）的 callback_time
            DB::update(
                "UPDATE withdrawal_orders wo
                    JOIN (
                        SELECT withdrawal_order_id, MIN(id) AS first_id
                        FROM withdrawal_payout_records
                        WHERE is_success_payout = 1 AND withdrawal_order_id IN ({$placeholders})
                        GROUP BY withdrawal_order_id
                    ) fp ON fp.withdrawal_order_id = wo.id
                    JOIN withdrawal_payout_records wpr ON wpr.id = fp.first_id
                 SET wo.payment_time = wpr.callback_time
                 WHERE wo.id IN ({$placeholders}) AND wo.status = ? AND wo.payment_time IS NULL",
                [...$idList, ...$idList, $successStatus]
            );

            // 無對應 payout 記錄：退用 created_at
            DB::update(
                "UPDATE withdrawal_orders wo
                 SET wo.payment_time = wo.created_at
                 WHERE wo.id IN ({$placeholders})
                    AND wo.status = ?
                    AND wo.payment_time IS NULL",
                [...$idList, $successStatus]
            );

            $processed += count($idList);
            $lastId = end($idList);

            $this->line(sprintf('  已處理至 id=%d（累計 %d 筆）', $lastId, $processed));
        }

        $this->info(sprintf('完成：共回填 %d 筆。', $processed));

        return self::SUCCESS;
    }
}
