<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Services\MemberHourlyStatisticsRecomputeService;
use Illuminate\Console\Command;

/**
 * 每小時校正 member_hourly_statistics 的代收 / 代付匯總。
 *
 * 即時管道（OrderObserver / WithdrawalOrderObserver / ThirdPartyController::newTransaction → UpdateMemberHourSummary）
 * 以累加方式寫入，在 worker 異常重跑 / 遺漏等邊界情況下可能與來源有飄移。本指令定時「全量重算覆蓋」近幾個
 * 完整整點的匯總，把數據拉回與來源一致。
 *
 * 來源（見 MemberHourlyStatisticsRecomputeService）：
 *   - 金流訂單 orders / withdrawal_orders（原有）
 *   - 手動開分／洗分 transaction_logs type 4/5 且 order_id = 0（target/tickets#4333）：代理後台那條走 internal 服務寫入、
 *     apis 沒有即時事件可掛，只能靠本排程兜底；客服路徑雖有即時 dispatch，這裡同時是它的保險。
 *
 * 常駐防護（target/tickets#4333）：重算之前先補 status=24 卻缺 payment_time 的提款單，每輪上限 500 筆、
 * 由新到舊。缺這個欄位的單在代付重算中會被整筆漏掉（分桶欄位為 NULL），補在重算之前，本輪即可納入匯總。
 * 歷史存量另由一次性腳本 patch:backfill-withdrawal-payment-time 處理，兩者來源口徑相同。
 *
 * 範圍：對齊整點、只重算「往前 N 個完整整點」（排除進行中的當前小時，留時間給晚到的回調落定）。
 *   例：10:45 執行、hours=2 → 重算 08:00、09:00 兩個整點桶。
 *
 * 分桶時間欄位（須與即時管道同基準，否則覆蓋語意會把同筆算到不同桶）：
 *   - 代收：orders.pay_time；代付：withdrawal_orders.payment_time；手動帳變：transaction_logs.created_at。
 *   ⚠️ 不可用 updated_at——該欄位為 `ON UPDATE CURRENT_TIMESTAMP`，任何後續 UPDATE 都會使其失真。
 *
 * 冪等性：ON DUPLICATE KEY UPDATE = VALUES(...)（全量覆蓋，非累加）。因為是覆蓋語意，時間窗必須對齊整點，
 * 否則半桶會被覆蓋成錯誤的少計值——故一律以 startOfHour 對齊。
 *
 * target/tickets#3967、target/tickets#4333
 */
class CorrectMemberHourlyStatistics extends Command
{
    protected $signature = 'member:correct-hourly-statistics
        {--hours=2 : 往前重算幾個完整整點}
        {--payment-time-limit=500 : 單輪最多回填幾筆缺 payment_time 的提款單（0 表示略過回填）}
        {--dry-run : 只預覽來源筆數與範圍，不寫入}';

    protected $description = '每小時校正 member_hourly_statistics 近幾個整點的代收/代付匯總（含手動開分洗分，全量重算覆蓋）';

    public function __construct(private readonly MemberHourlyStatisticsRecomputeService $recompute)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $hours = max(1, (int) $this->option('hours'));

        // 對齊整點：end 為當前整點（排除進行中的小時），start 往前推 N 個整點
        $end = now()->startOfHour();
        $start = $end->copy()->subHours($hours);
        $startAt = $start->toDateTimeString();
        $endAt = $end->toDateTimeString();

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN：不寫入資料庫');
        }
        $this->info(sprintf('校正範圍：%s ~ %s（%d 個整點）', $startAt, $endAt, $hours));

        // 先補 payment_time 再重算：缺這個欄位的提款單在代付重算中會被整筆漏掉（分桶欄位為 NULL），
        // 補在重算之前，本輪就能把剛補好的單一併算進當輪匯總。
        $backfillLimit = (int) $this->option('payment-time-limit');
        $backfilled = 0;

        if ($backfillLimit > 0 && !$dryRun) {
            $backfilled = $this->recompute->backfillMissingWithdrawalPaymentTime($backfillLimit);

            if ($backfilled > 0) {
                $this->info(sprintf('已回填 payment_time：%d 筆。', $backfilled));
            }
        }

        $deposit = $this->recompute->countDepositSources($startAt, $endAt);
        $withdrawal = $this->recompute->countWithdrawalSources($startAt, $endAt);

        if (!$dryRun) {
            if ($deposit['orders'] + $deposit['manual'] > 0) {
                $this->recompute->recomputeDeposit($startAt, $endAt);
            }

            if ($withdrawal['orders'] + $withdrawal['manual'] > 0) {
                $this->recompute->recomputeWithdrawal($startAt, $endAt);
            }

            if ($withdrawal['orders'] > 0) {
                $this->recompute->touchLastWithdrawAt($startAt, $endAt);
            }
        }

        $this->info(sprintf(
            '%s：代收 訂單 %d／手動開分 %d 筆、代付 訂單 %d／手動洗分 %d 筆、回填 payment_time %d 筆。',
            $dryRun ? 'DRY RUN 完成' : '完成',
            $deposit['orders'],
            $deposit['manual'],
            $withdrawal['orders'],
            $withdrawal['manual'],
            $backfilled,
        ));

        return self::SUCCESS;
    }
}
