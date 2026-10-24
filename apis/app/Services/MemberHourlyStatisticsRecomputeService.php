<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CashFlowEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\Statistics\MemberHourlySourceEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use Illuminate\Support\Facades\DB;

/**
 * member_hourly_statistics 的「全量重算覆蓋」核心，供每小時校正排程與一次性回填腳本共用（target/tickets#4333）。
 *
 * 一個整點桶一次算出 16 個數值欄：總額 4 欄（deposit_*／withdrawal_*）＋ 六類來源明細 12 欄（{@see MemberHourlySourceEnum}）。
 * 來源與分桶欄位：
 *   - 三方充值／自助上分：orders（DEPOSIT_FINISHED，以 pay_time 分桶），依 order_types.cash_flow_id 是否 AutoDeposit 分兩類
 *   - 後台開分：transaction_logs type=4、order_id=0（以 created_at 分桶；客服 platform 0 與代理後台 platform 2 一併）
 *   - 三方提現／自助洗分：withdrawal_orders（status 24 三方代付／4 後台人工轉帳，以 payment_time＝實際打款時間分桶）
 *   - 後台洗分：transaction_logs type=5、order_id=0（以 created_at 分桶）
 *     限定 order_id = 0 是為了不與訂單來源重複計算：有訂單的洗分已由 withdrawal_orders 那一側計入。
 *
 * 各來源先各自 GROUP BY（member_id, 整點, 來源）再 UNION ALL，外層 SUM(IF(來源=..)) 一次分流成 16 欄，
 * 最後 ON DUPLICATE KEY UPDATE = VALUES(...) 整桶覆蓋：冪等、可重跑、不累加。
 *
 * 不納入 wallet_transaction_logs：活動錢包／多錢包會員的手動開洗分寫在那張表，不屬本表口徑；
 * ThirdPartyController::newTransaction() 的即時累加同樣只在帳變落 transaction_logs 時才送。
 *
 * historicalAmountFallback 只給回填開（歷史 point=0 的單改用 amount × rate），排程用純 point；兩者對同一桶會算出不同值，
 * 前提是排程窗口（最近 2 小時）永遠不會碰到 2026-07-20 之前的歷史桶——回填 --end 不可晚於排程窗口起點。
 *
 * 一律排除 member_id = 0：代理自助洗分／上分的訂單 member_id 寫 0、agent_id 帶代理，是代理的錢，
 * 不進「會員」每小時統計（過去未排除，六站皆有灌入，見 purgeAgentRows()）。
 */
class MemberHourlyStatisticsRecomputeService
{
    private const string HOUR_FORMAT = '%Y-%m-%d %H:00:00';

    /**
     * @return array<int, int>
     */
    public function withdrawalOrderStatuses(): array
    {
        return [
            WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value,
            WithdrawalOrderStatusEnum::TRANSFERRED->value,
        ];
    }

    /**
     * 代收側來源筆數（金流訂單 ＋ 手動開分），供 dry-run 預覽與命令輸出。
     *
     * @return array{orders: int, manual: int}
     */
    public function countDepositSources(string $startAt, string $endAt): array
    {
        return [
            'orders' => (int) DB::table('orders')
                ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
                ->where('member_id', '>', 0)
                ->where('pay_time', '>=', $startAt)
                ->where('pay_time', '<', $endAt)
                ->count(),
            'manual' => $this->countManualTransactions(TransactionEnum::DEPOSIT->value, $startAt, $endAt),
        ];
    }

    /**
     * 代付側來源筆數（金流訂單 ＋ 手動洗分）。
     *
     * @return array{orders: int, manual: int}
     */
    public function countWithdrawalSources(string $startAt, string $endAt): array
    {
        return [
            'orders' => (int) DB::table('withdrawal_orders')
                ->whereIn('status', $this->withdrawalOrderStatuses())
                ->where('member_id', '>', 0)
                ->where('payment_time', '>=', $startAt)
                ->where('payment_time', '<', $endAt)
                ->count(),
            'manual' => $this->countManualTransactions(TransactionEnum::WITHDRAWAL->value, $startAt, $endAt),
        ];
    }

    /**
     * 重算 [startAt, endAt) 內各桶的代收：總額 2 欄＋三類明細 6 欄，整桶覆蓋。
     *
     * @param  bool  $historicalAmountFallback  歷史單 point 為 0 時改以 amount × rate 換算（回填用；排程用純 point）
     */
    public function recomputeDeposit(string $startAt, string $endAt, bool $historicalAmountFallback = false): void
    {
        $orderAmount = $historicalAmountFallback ? 'SUM(IF(o.point > 0, o.point, o.amount * o.rate))' : 'SUM(o.point)';
        $hour = self::HOUR_FORMAT;
        $auto = MemberHourlySourceEnum::AUTO_DEPOSIT->value;
        $order = MemberHourlySourceEnum::THIRD_PARTY_DEPOSIT->value;

        // 效能：各來源先分桶聚合再 UNION ALL；orders 吃 idx_orders_pay_time（order_types 小表 join 取 cash_flow_id），
        // transaction_logs 強制 created_at 範圍索引（見 manualTransactionSelect）
        DB::statement(
            'INSERT INTO member_hourly_statistics
                (member_id, summary_hour, deposit_count, deposit_amount, '
                . $this->columnList(MemberHourlySourceEnum::depositCases()) . ', created_at, updated_at)
             SELECT s.member_id, s.summary_hour, SUM(s.cnt), SUM(s.amt), '
                . $this->splitSelect(MemberHourlySourceEnum::depositCases()) . ", NOW(), NOW()
             FROM (
                SELECT o.member_id, DATE_FORMAT(o.pay_time, '{$hour}') AS summary_hour,
                       IF(ot.cash_flow_id = ?, '{$auto}', '{$order}') AS src, COUNT(*) AS cnt, {$orderAmount} AS amt
                FROM orders o
                LEFT JOIN order_types ot ON ot.id = o.order_type_id
                WHERE o.status = ? AND o.member_id > 0 AND o.pay_time >= ? AND o.pay_time < ?
                GROUP BY o.member_id, summary_hour, src
                UNION ALL
                " . $this->manualTransactionSelect(MemberHourlySourceEnum::ADMIN_DEPOSIT) . '
             ) AS s
             GROUP BY s.member_id, s.summary_hour
             ON DUPLICATE KEY UPDATE
                deposit_count = VALUES(deposit_count),
                deposit_amount = VALUES(deposit_amount), '
                . $this->duplicateKeyUpdate(MemberHourlySourceEnum::depositCases()) . ',
                updated_at = NOW()',
            [
                CashFlowEnum::DEPOSIT_AUTO_DEPOSIT,
                OrderStatusEnum::DEPOSIT_FINISHED->value, $startAt, $endAt,
                ...$this->manualTransactionBindings(TransactionEnum::DEPOSIT->value, $startAt, $endAt),
            ]
        );
    }

    /**
     * 重算 [startAt, endAt) 內各桶的代付：總額 2 欄＋三類明細 6 欄，整桶覆蓋。
     */
    public function recomputeWithdrawal(string $startAt, string $endAt, bool $historicalAmountFallback = false): void
    {
        $orderAmount = $historicalAmountFallback ? 'SUM(IF(w.point > 0, w.point, w.amount * w.rate))' : 'SUM(w.point)';
        $hour = self::HOUR_FORMAT;
        $payout = MemberHourlySourceEnum::THIRD_PARTY_WITHDRAWAL->value;
        $transfer = MemberHourlySourceEnum::AUTO_WITHDRAWAL->value;
        $statuses = $this->withdrawalOrderStatuses();
        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));

        DB::statement(
            'INSERT INTO member_hourly_statistics
                (member_id, summary_hour, withdrawal_count, withdrawal_amount, '
                . $this->columnList(MemberHourlySourceEnum::withdrawalCases()) . ', created_at, updated_at)
             SELECT s.member_id, s.summary_hour, SUM(s.cnt), SUM(s.amt), '
                . $this->splitSelect(MemberHourlySourceEnum::withdrawalCases()) . ", NOW(), NOW()
             FROM (
                SELECT w.member_id, DATE_FORMAT(w.payment_time, '{$hour}') AS summary_hour,
                       IF(w.status = ?, '{$payout}', '{$transfer}') AS src, COUNT(*) AS cnt, {$orderAmount} AS amt
                FROM withdrawal_orders w
                WHERE w.status IN ({$statusPlaceholders}) AND w.member_id > 0 AND w.payment_time >= ? AND w.payment_time < ?
                GROUP BY w.member_id, summary_hour, src
                UNION ALL
                " . $this->manualTransactionSelect(MemberHourlySourceEnum::ADMIN_WITHDRAWAL) . '
             ) AS s
             GROUP BY s.member_id, s.summary_hour
             ON DUPLICATE KEY UPDATE
                withdrawal_count = VALUES(withdrawal_count),
                withdrawal_amount = VALUES(withdrawal_amount), '
                . $this->duplicateKeyUpdate(MemberHourlySourceEnum::withdrawalCases()) . ',
                updated_at = NOW()',
            [
                WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value,
                ...$statuses, $startAt, $endAt,
                ...$this->manualTransactionBindings(TransactionEnum::WITHDRAWAL->value, $startAt, $endAt),
            ]
        );
    }

    /**
     * 補 member_statistics.last_withdraw_at（實際打款時間）：後台轉帳單由 API_Agent 寫入、不經即時管道，只能靠排程補。
     * GREATEST 取較晚者，不會被本次時間窗的舊值蓋掉。
     */
    public function touchLastWithdrawAt(string $startAt, string $endAt): void
    {
        $statuses = $this->withdrawalOrderStatuses();
        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));

        DB::statement(
            "INSERT INTO member_statistics (member_id, last_withdraw_at, created_at, updated_at)
             SELECT member_id, MAX(payment_time), NOW(), NOW()
             FROM withdrawal_orders
             WHERE status IN ({$statusPlaceholders}) AND member_id > 0 AND payment_time >= ? AND payment_time < ?
             GROUP BY member_id
             ON DUPLICATE KEY UPDATE
                last_withdraw_at = GREATEST(COALESCE(last_withdraw_at, '1970-01-01 00:00:00'), VALUES(last_withdraw_at)),
                updated_at = NOW()",
            [...$statuses, $startAt, $endAt]
        );
    }

    /**
     * 常駐防護：補回 status=24 卻缺 payment_time 的提款單（target/tickets#4333）。
     *
     * apis 的三方回調成功路徑過去不寫 payment_time，已補上即時寫入，但仍可能因回調在極端路徑
     * 漏寫而留下缺口；缺 payment_time 的單在本服務的代付重算中會被整筆漏掉（分桶欄位為 NULL），
     * 統計就永久少計。一次性回填腳本 patch:backfill-withdrawal-payment-time 只掃歷史，
     * 本方法掛在每小時校正排程、於重算之前跑，讓新增的缺口在同一輪就被補進當輪匯總。
     *
     * 時間來源：對應 withdrawal_payout_records（is_success_payout=1）第一筆的 callback_time，
     * 與一次性回填腳本同一套口徑。
     *   ⚠️ 不可用 transaction_time——三方回傳原始字串，時區依代付商而異（部分 +8、部分 UTC）。
     *   ⚠️ 不可用 payout 記錄的 created_at——那是「送出代付請求」的時間，比實際打款早 1～3 分鐘
     *      （正式庫實測 49～150 秒），跨整點時會落錯匯總桶。正常單的 payment_time 與 callback_time
     *      實測秒差為 0，callback_time 才是系統既有的打款時間口徑。
     *
     * 🔴 SET 必須顯式寫 updated_at = callback_time：withdrawal_orders.updated_at 帶
     * `ON UPDATE CURRENT_TIMESTAMP`，不顯式指定就會被 MySQL 蓋成腳本執行當下時間，
     * 讓這批歷史單看起來像剛被改過，污染以 updated_at 為準的稽核與排查。
     *
     * 倒敘處理（ORDER BY id DESC）：新資料優先補齊，確保當輪重算能吃到；舊資料由後續輪次逐批消化。
     * 單輪上限預設 500 筆，避免長交易與排程超時；缺口持續增加時仍是每輪 500 筆的穩定節奏。
     *
     * @param  int  $limit  單輪最多回填筆數
     * @return int 實際回填列數
     */
    public function backfillMissingWithdrawalPaymentTime(int $limit = 500): int
    {
        $ids = DB::table('withdrawal_orders')
            ->where('status', WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value)
            ->whereNull('payment_time')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return DB::update(
            "UPDATE withdrawal_orders wo
                JOIN (
                    SELECT withdrawal_order_id, MIN(id) AS first_id
                    FROM withdrawal_payout_records
                    WHERE is_success_payout = 1 AND withdrawal_order_id IN ({$placeholders})
                    GROUP BY withdrawal_order_id
                ) fp ON fp.withdrawal_order_id = wo.id
                JOIN withdrawal_payout_records wpr ON wpr.id = fp.first_id
             SET wo.payment_time = wpr.callback_time,
                 wo.updated_at = wpr.callback_time
             WHERE wo.id IN ({$placeholders})
               AND wo.status = ?
               AND wo.payment_time IS NULL
               AND wpr.callback_time IS NOT NULL
               AND wpr.callback_time > '2000-01-01 00:00:00'",
            [...$ids, ...$ids, WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value]
        );
    }

    /**
     * 把窗口內所有桶的代收 8 欄歸零，供回填在重算前呼叫（與 recomputeDeposit 包在同一個 transaction）。
     *
     * 整桶覆蓋只會寫「現在有來源」的桶；來源事後消失（訂單被改離成功狀態、資料修正）的桶不會被任何來源命中、
     * 舊值就一直留著，變成多計。先歸零再重算才是真正的全量重算。吃 summary_hour 索引；只動有值的列。
     *
     * @return int 歸零列數
     */
    public function resetDepositWindow(string $startAt, string $endAt): int
    {
        return $this->resetWindow($startAt, $endAt, 'deposit_count', 'deposit_amount', MemberHourlySourceEnum::depositCases());
    }

    /**
     * 同 resetDepositWindow()，代付 8 欄。
     */
    public function resetWithdrawalWindow(string $startAt, string $endAt): int
    {
        return $this->resetWindow($startAt, $endAt, 'withdrawal_count', 'withdrawal_amount', MemberHourlySourceEnum::withdrawalCases());
    }

    /**
     * 既有被灌入的 member_id = 0 列數（代理自助洗分／上分），供清理前預覽。
     *
     * @return array{hourly: int, statistics: int}
     */
    public function countAgentRows(): array
    {
        return [
            'hourly' => (int) DB::table('member_hourly_statistics')->where('member_id', 0)->count(),
            'statistics' => (int) DB::table('member_statistics')->where('member_id', 0)->count(),
        ];
    }

    /**
     * 清掉 member_id = 0 的列。只動這一個 member_id，不影響任何真實會員。
     *
     * @return array{hourly: int, statistics: int} 各表實際刪除列數
     */
    public function purgeAgentRows(): array
    {
        return [
            'hourly' => DB::table('member_hourly_statistics')->where('member_id', 0)->delete(),
            'statistics' => DB::table('member_statistics')->where('member_id', 0)->delete(),
        ];
    }

    /**
     * @param  list<MemberHourlySourceEnum>  $sources
     */
    private function resetWindow(string $startAt, string $endAt, string $totalCount, string $totalAmount, array $sources): int
    {
        $zeroed = [$totalCount => 0, $totalAmount => 0, 'updated_at' => now()];
        foreach ($sources as $source) {
            $zeroed[$source->countColumn()] = 0;
            $zeroed[$source->amountColumn()] = 0;
        }

        return DB::table('member_hourly_statistics')
            ->where('summary_hour', '>=', $startAt)
            ->where('summary_hour', '<', $endAt)
            ->where(fn ($q) => $q->where($totalCount, '>', 0)->orWhere($totalAmount, '>', 0))
            ->update($zeroed);
    }

    private function countManualTransactions(int $type, string $startAt, string $endAt): int
    {
        return (int) DB::table('transaction_logs')
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->where('type', $type)
            ->where(fn ($q) => $q->where('order_id', 0)->orWhereNull('order_id'))
            ->where('member_id', '>', 0)
            ->where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->count();
    }

    /**
     * 手動帳變的分桶子查詢（與外層 UNION ALL 對齊欄位：member_id, summary_hour, src, cnt, amt）。
     * 綁定順序見 manualTransactionBindings()。
     *
     * 效能：時間窗一拉大（回填的 30 天切片）optimizer 會改走 intersect(idx_type, idx_status)，
     * 每個切片都重掃全表歷年的 type=4/5 列——TPower 實測單切片 >30s 逾時；強制走 created_at 範圍索引後 9s、
     * VWorld 9.2s→2.1s。排程的 2 小時窗口本來就選 created_at，加 hint 不影響。
     * hint 列兩個索引名：正式／dev 庫是原始 migration 的 `idx_transaction_logs_created_at`，
     * 現行 migration（裸 `->index()`）建出來的是 `transaction_logs_created_at_index`（測試庫、新白標）。
     * MySQL 對不存在的索引名只發 warning 不報錯，只列一個會在另一種環境靜默失效退回慢路徑。
     */
    private function manualTransactionSelect(MemberHourlySourceEnum $source): string
    {
        $hour = self::HOUR_FORMAT;

        return "SELECT /*+ INDEX(transaction_logs idx_transaction_logs_created_at, transaction_logs_created_at_index) */
                    member_id, DATE_FORMAT(created_at, '{$hour}') AS summary_hour, '{$source->value}' AS src, COUNT(*) AS cnt, SUM(point) AS amt
                FROM transaction_logs
                WHERE status = ? AND type = ? AND (order_id = 0 OR order_id IS NULL) AND member_id > 0
                  AND created_at >= ? AND created_at < ?
                GROUP BY member_id, DATE_FORMAT(created_at, '{$hour}')";
    }

    /**
     * @return array<int, int|string>
     */
    private function manualTransactionBindings(int $type, string $startAt, string $endAt): array
    {
        return [TransactionStatusEnum::STATUS_SUCCESS->value, $type, $startAt, $endAt];
    }

    /**
     * 明細欄名清單（INSERT 欄位段）。欄名來自 Enum，非外部輸入。
     *
     * @param  list<MemberHourlySourceEnum>  $sources
     */
    private function columnList(array $sources): string
    {
        $columns = [];
        foreach ($sources as $source) {
            $columns[] = $source->countColumn();
            $columns[] = $source->amountColumn();
        }

        return implode(', ', $columns);
    }

    /**
     * 外層 SELECT 的來源分流段：SUM(IF(s.src = '<source>', s.cnt, 0)), SUM(IF(..., s.amt, 0)) ...
     *
     * @param  list<MemberHourlySourceEnum>  $sources
     */
    private function splitSelect(array $sources): string
    {
        $parts = [];
        foreach ($sources as $source) {
            $parts[] = "SUM(IF(s.src = '{$source->value}', s.cnt, 0))";
            $parts[] = "SUM(IF(s.src = '{$source->value}', s.amt, 0))";
        }

        return implode(', ', $parts);
    }

    /**
     * @param  list<MemberHourlySourceEnum>  $sources
     */
    private function duplicateKeyUpdate(array $sources): string
    {
        $parts = [];
        foreach ($sources as $source) {
            $parts[] = "{$source->countColumn()} = VALUES({$source->countColumn()})";
            $parts[] = "{$source->amountColumn()} = VALUES({$source->amountColumn()})";
        }

        return implode(', ', $parts);
    }
}
