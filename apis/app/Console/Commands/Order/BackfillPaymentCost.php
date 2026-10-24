<?php

declare(strict_types=1);

namespace App\Console\Commands\Order;

use App\Enums\OrderStatusEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 一次性回補 orders.payment_cost／withdrawal_orders.payment_cost（支付商手續費＝成本）target/tickets#4361。
 *
 * 欄位上線前的歷史單成本為 0；SP_summary_event_record_by_payment／_W 改為直接加總此欄，
 * 沒回補的區間看板成本會是 0，故上線後對每個白標各跑一次（預設回看 6 個月）。
 *
 * 口徑與即時寫入一致（AgentCashFlow::paymentCostFor）：
 *  - 代收：status=3（已付款），成本 = TRUNCATE(point × acf.cost, 4)，渠道取訂單的 agent_cash_flow_id。
 *  - 代付三方成功（status=24）：渠道取「最新一筆」is_success_payout=1 的 payout record，成本 = TRUNCATE(amount × rate × acf.cost, 4)。
 *  - 代付人工出款（status=4）：渠道取該代理組別的手動渠道（cash_flow_id=6），同上公式。
 *  只補 payment_cost = 0 的列，重跑安全；cost = 0 的渠道不動（本來就是 0）。
 *
 * 效能：以 id 區間分批 UPDATE…JOIN（非逐列），每批之間 usleep 讓出 I/O；TPower orders 6 個月約數百萬列。
 * 區間一律以 created_at 篩：起點 id 取 created_at 索引的第一筆、終點 id 取主鍵 MAX(id)，兩者都是索引一跳；
 * 禁止對整段區間做 MIN/MAX/COUNT（實測 TPower 掃幾百萬列直接逾時）。
 * 乾跑只對第一批 chunk 抽樣計數，不整段 COUNT。
 * updated_at 是 ON UPDATE CURRENT_TIMESTAMP，UPDATE 必須帶 `updated_at = updated_at` 鎖住原值，回補不算異動。
 * DB::table／DB::update 批次寫入屬 CLAUDE.md 允許的「標註效能的批次寫入」。
 *
 * 用法（每個白標各跑一次，依 whitelabel-container-exec 注入 DB_CONNECTION）：
 *   php artisan order:backfill-payment-cost --dry-run
 *   php artisan order:backfill-payment-cost --months=6
 *   php artisan order:backfill-payment-cost --type=withdrawal
 *
 * @knowledge rules/payment-cost-summary
 */
class BackfillPaymentCost extends Command
{
    protected $signature = 'order:backfill-payment-cost
        {--months=6 : 回看月數（依 created_at）}
        {--type=all : deposit＝只補代收、withdrawal＝只補代付、all＝兩者}
        {--chunk=20000 : 每批 id 區間寬度}
        {--dry-run : 只列出各類待回補筆數，不寫入}';

    protected $description = '回補 orders／withdrawal_orders 的 payment_cost（支付商手續費）';

    private const int SLEEP_MICROSECONDS = 200_000;

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $type = (string) $this->option('type');
        $chunk = max(1000, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $from = Carbon::now()->subMonths($months)->startOfDay()->toDateTimeString();

        if (!in_array($type, ['deposit', 'withdrawal', 'all'], true)) {
            $this->error('--type 必須是 deposit、withdrawal 或 all');

            return self::FAILURE;
        }

        $this->info(sprintf('連線：%s／區間起點（UTC）：%s／模式：%s', DB::getDefaultConnection(), $from, $dryRun ? '乾跑' : '寫入'));

        if ($type !== 'withdrawal') {
            $this->backfillDeposits($from, $chunk, $dryRun);
        }

        if ($type !== 'deposit') {
            $this->backfillWithdrawals($from, $chunk, $dryRun);
        }

        return self::SUCCESS;
    }

    private function backfillDeposits(string $from, int $chunk, bool $dryRun): void
    {
        $status = OrderStatusEnum::DEPOSIT_FINISHED->value;
        $range = $this->idRange('orders', $from);

        if ($range === null) {
            $this->info('代收：區間內沒有訂單。');

            return;
        }

        [$minId, $maxId] = $range;

        $update = static fn (int $lo, int $hi): int => DB::update(
            'UPDATE orders o
                JOIN agent_cash_flows acf ON acf.id = o.agent_cash_flow_id
             SET o.payment_cost = TRUNCATE(o.point * acf.cost, 4), o.updated_at = o.updated_at
             WHERE o.id BETWEEN ? AND ? AND o.status = ? AND o.payment_cost = 0 AND acf.cost > 0',
            [$lo, $hi, $status],
        );

        $sample = static fn (int $lo, int $hi): int => (int) DB::table('orders as o')
            ->join('agent_cash_flows as acf', 'acf.id', '=', 'o.agent_cash_flow_id')
            ->whereBetween('o.id', [$lo, $hi])
            ->where('o.status', $status)
            ->where('o.payment_cost', 0)
            ->where('acf.cost', '>', 0)
            ->count();

        $this->report('代收', $minId, $maxId, $chunk, $sample);

        if (!$dryRun) {
            $this->runInIdRanges($minId, $maxId, $chunk, $update);
        }
    }

    private function backfillWithdrawals(string $from, int $chunk, bool $dryRun): void
    {
        $thirdParty = WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value;
        $manual = WithdrawalOrderStatusEnum::TRANSFERRED->value;
        $range = $this->idRange('withdrawal_orders', $from);

        if ($range === null) {
            $this->info('代付：區間內沒有訂單。');

            return;
        }

        [$minId, $maxId] = $range;

        $update = static function (int $lo, int $hi) use ($thirdParty, $manual): int {
            // 三方成功：渠道取最新一筆成功 payout record
            $affected = DB::update(
                'UPDATE withdrawal_orders wo
                    JOIN (
                        SELECT withdrawal_order_id, MAX(id) AS id
                        FROM withdrawal_payout_records
                        WHERE is_success_payout = 1 AND withdrawal_order_id BETWEEN ? AND ?
                        GROUP BY withdrawal_order_id
                    ) last ON last.withdrawal_order_id = wo.id
                    JOIN withdrawal_payout_records wpr ON wpr.id = last.id
                    JOIN agent_cash_flows acf ON acf.id = wpr.agent_cash_flow_id
                 SET wo.payment_cost = TRUNCATE(wo.amount * wo.rate * acf.cost, 4), wo.updated_at = wo.updated_at
                 WHERE wo.id BETWEEN ? AND ? AND wo.status = ? AND wo.payment_cost = 0 AND acf.cost > 0',
                [$lo, $hi, $lo, $hi, $thirdParty],
            );

            // 人工出款：渠道取該代理組別的手動渠道（cash_flow_id=6，未軟刪）
            $affected += DB::update(
                'UPDATE withdrawal_orders wo
                    JOIN agent_cash_flows acf
                      ON acf.agent_group_id = wo.agent_group_id AND acf.cash_flow_id = 6 AND acf.deleted_at IS NULL
                 SET wo.payment_cost = TRUNCATE(wo.amount * wo.rate * acf.cost, 4), wo.updated_at = wo.updated_at
                 WHERE wo.id BETWEEN ? AND ? AND wo.status = ? AND wo.payment_cost = 0 AND acf.cost > 0',
                [$lo, $hi, $manual],
            );

            return $affected;
        };

        $sample = static fn (int $lo, int $hi): int => (int) DB::table('withdrawal_orders')
            ->whereBetween('id', [$lo, $hi])
            ->whereIn('status', [$thirdParty, $manual])
            ->where('payment_cost', 0)
            ->count();

        $this->report('代付', $minId, $maxId, $chunk, $sample);

        if (!$dryRun) {
            $this->runInIdRanges($minId, $maxId, $chunk, $update);
        }
    }

    /**
     * 回補的 id 範圍：起點走 created_at 索引取第一筆、終點走主鍵 MAX(id)，兩者都是索引一跳，不掃區間。
     *
     * @return array{0: int, 1: int}|null 區間內沒有列時回 null
     */
    private function idRange(string $table, string $createdFrom): ?array
    {
        $first = DB::table($table)->where('created_at', '>=', $createdFrom)->orderBy('created_at')->value('id');

        if ($first === null) {
            return null;
        }

        return [(int) $first, (int) DB::table($table)->max('id')];
    }

    /**
     * 乾跑與正式執行前的概況：id 範圍、列數（依 id 差估）、第一批 chunk 抽樣的待回補筆數。
     *
     * @param  callable(int, int): int  $sample
     */
    private function report(string $label, int $minId, int $maxId, int $chunk, callable $sample): void
    {
        $sampled = $sample($minId, min($minId + $chunk - 1, $maxId));

        $this->info(sprintf(
            '%s：id %d〜%d（約 %s 列，分 %d 批）；第一批 %d 筆內待回補 %d 筆',
            $label,
            $minId,
            $maxId,
            number_format($maxId - $minId + 1),
            (int) ceil(($maxId - $minId + 1) / $chunk),
            $chunk,
            $sampled,
        ));
    }

    /**
     * 以 id 區間分批執行，每批回報影響筆數並讓出 I/O。
     *
     * @param  callable(int, int): int  $update  回傳該批影響筆數
     */
    private function runInIdRanges(int $minId, int $maxId, int $chunk, callable $update): void
    {
        $total = 0;

        for ($lo = $minId; $lo <= $maxId; $lo += $chunk) {
            $hi = min($lo + $chunk - 1, $maxId);
            $affected = $update($lo, $hi);
            $total += $affected;

            $this->line(sprintf('  id %d〜%d：回補 %d 筆（累計 %d）', $lo, $hi, $affected, $total));
            usleep(self::SLEEP_MICROSECONDS);
        }

        $this->info(sprintf('完成：共回補 %d 筆。', $total));
    }
}
