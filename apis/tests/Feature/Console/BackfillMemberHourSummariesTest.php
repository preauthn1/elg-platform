<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\CashFlowEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Models\MemberHourlyStatistic;
use App\Models\MemberStatistic;
use App\Models\Order;
use App\Models\WithdrawalOrder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesManualTransactionLogs;
use Tests\TestCase;

/**
 * 驗證回填 command：同會員同整點多筆聚合成一列、count/amount 正確、重跑冪等；
 * 並驗證 2026-09-07（4299）的金額口徑——歷史 point=0 的單改以 amount × rate 換算。
 *
 * php artisan test tests/Feature/Console/BackfillMemberHourSummariesTest.php
 */
class BackfillMemberHourSummariesTest extends TestCase
{
    use CreatesManualTransactionLogs;

    public function test_backfill_aggregates_by_member_and_hour(): void
    {
        $memberId = 990201;

        // 同會員同整點：2 筆代收（point 100 + 50）、1 筆代付（point 30）
        $this->makeOrder($memberId, '100', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 03:10:00');
        $this->makeOrder($memberId, '50', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 03:40:00');
        $this->makeWithdrawal($memberId, '30', WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, '2026-01-01 03:20:00');
        // 非成功的不該被算
        $this->makeOrder($memberId, '999', OrderStatusEnum::CREATED->value, '2026-01-01 03:50:00');

        $this->artisan('patch:backfill-member-hour-summaries', [
            '--start' => '2026-01-01 00:00:00',
            '--end' => '2026-01-02 00:00:00',
        ])->assertSuccessful();

        $this->assertEquals(1, MemberHourlyStatistic::where('member_id', $memberId)->count());

        $summary = MemberHourlyStatistic::where('member_id', $memberId)->first();
        $this->assertEquals('2026-01-01 03:00:00', $summary->summary_hour);
        $this->assertEquals(2, $summary->deposit_count);
        $this->assertEquals('150.0000', $summary->deposit_amount);
        $this->assertEquals(1, $summary->withdrawal_count);
        $this->assertEquals('30.0000', $summary->withdrawal_amount);

        // 4333 六類明細：factory 訂單 order_type_id=0 → 金流商非 AutoDeposit → 三方充值；THIRD_PARTY_SUCCESS → 三方提現
        $this->assertEquals(2, $summary->third_party_deposit_count);
        $this->assertEquals('150.0000', $summary->third_party_deposit_amount);
        $this->assertEquals(0, $summary->auto_deposit_count);
        $this->assertEquals(0, $summary->admin_deposit_count);
        $this->assertEquals(1, $summary->third_party_withdrawal_count);
        $this->assertEquals('30.0000', $summary->third_party_withdrawal_amount);
        $this->assertEquals(0, $summary->auto_withdrawal_count);
        $this->assertEquals(0, $summary->admin_withdrawal_count);
    }

    public function test_backfill_is_idempotent_on_rerun(): void
    {
        $memberId = 990202;
        $this->makeOrder($memberId, '100', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 05:10:00');

        $args = ['--start' => '2026-01-01 00:00:00', '--end' => '2026-01-02 00:00:00'];
        $this->artisan('patch:backfill-member-hour-summaries', $args)->assertSuccessful();
        $this->artisan('patch:backfill-member-hour-summaries', $args)->assertSuccessful();

        // 重跑不重複累加（全量重算覆蓋）
        $summary = MemberHourlyStatistic::where('member_id', $memberId)->first();
        $this->assertEquals(1, $summary->deposit_count);
        $this->assertEquals('100.0000', $summary->deposit_amount);
    }

    /**
     * 4299：2026-07-20 代付建單補寫 point 之前的歷史單 point 結構性為 0（金額只在 amount），
     * 回填改以 amount × rate 換算，否則整段歷史提款金額會記成 0。
     */
    public function test_backfill_uses_amount_rate_when_point_is_zero(): void
    {
        $memberId = 990204;

        // 歷史單：point=0、amount=200、rate=2.5 → 應計 500
        $this->makeWithdrawal($memberId, '0', WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, '2026-01-01 07:10:00', amount: '200', rate: '2.5');
        // 新單：point 有值，直接採用（不走換算）
        $this->makeWithdrawal($memberId, '80', WithdrawalOrderStatusEnum::TRANSFERRED->value, '2026-01-01 07:40:00', amount: '999', rate: '1');

        $this->artisan('patch:backfill-member-hour-summaries', [
            '--start' => '2026-01-01 00:00:00',
            '--end' => '2026-01-02 00:00:00',
        ])->assertSuccessful();

        $summary = MemberHourlyStatistic::where('member_id', $memberId)->first();
        $this->assertEquals(2, $summary->withdrawal_count);
        $this->assertEquals('580.0000', $summary->withdrawal_amount, 'point=0 走 amount×rate（500）＋ point 有值直接採用（80）');
        // 三方代付（24）→ 三方提現、後台已轉帳（4）→ 自助洗分，各自分流
        $this->assertEquals(1, $summary->third_party_withdrawal_count);
        $this->assertEquals('500.0000', $summary->third_party_withdrawal_amount);
        $this->assertEquals(1, $summary->auto_withdrawal_count);
        $this->assertEquals('80.0000', $summary->auto_withdrawal_amount);
    }

    /**
     * --only=withdrawal：只重算代付側，代收欄位完全不碰
     * （正式站代收筆數遠多於代付，只有代付需修時可省大量掃描）。
     */
    public function test_only_withdrawal_skips_deposit_side(): void
    {
        $memberId = 990205;
        $this->makeOrder($memberId, '100', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 09:10:00');
        $this->makeWithdrawal($memberId, '70', WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, '2026-01-01 09:20:00');

        $this->artisan('patch:backfill-member-hour-summaries', [
            '--only' => 'withdrawal',
            '--start' => '2026-01-01 00:00:00',
            '--end' => '2026-01-02 00:00:00',
        ])->assertSuccessful();

        $summary = MemberHourlyStatistic::where('member_id', $memberId)->first();
        $this->assertEquals(1, $summary->withdrawal_count, '代付照常重算');
        $this->assertEquals('70.0000', $summary->withdrawal_amount);
        $this->assertEquals(0, $summary->deposit_count, '代收側被跳過（未寫入）');
        $this->assertEquals('0.0000', $summary->deposit_amount);
    }

    public function test_only_rejects_invalid_value(): void
    {
        $this->artisan('patch:backfill-member-hour-summaries', [
            '--only' => 'both',
            '--start' => '2026-01-01 00:00:00',
            '--end' => '2026-01-02 00:00:00',
        ])->assertFailed();
    }

    /**
     * 4333：客服／代理手動開分洗分沒有訂單（transaction_logs type 4/5、order_id=0），要與金流訂單相加進同一桶；
     * 有訂單的洗分（order_id>0）已由 withdrawal_orders 計入，不得重複；非成功狀態不算。
     */
    public function test_backfill_adds_manual_transactions_without_double_counting(): void
    {
        $memberId = 990206;

        // 同一整點：1 筆金流代收 100 ＋ 1 筆手動開分 250
        $this->makeOrder($memberId, '100', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 11:10:00');
        $this->makeManualTransaction($memberId, TransactionEnum::DEPOSIT->value, '250', '2026-01-01 11:20:00');
        // 1 筆有訂單的洗分 30（withdrawal_orders 計入；其對應的 type=5 帳變帶 order_id，不得再算一次）
        $this->makeWithdrawal($memberId, '30', WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, '2026-01-01 11:30:00');
        $this->makeManualTransaction($memberId, TransactionEnum::WITHDRAWAL->value, '30', '2026-01-01 11:31:00', orderId: 12345);
        // 1 筆手動洗分 40（order_id=0 → 計入）
        $this->makeManualTransaction($memberId, TransactionEnum::WITHDRAWAL->value, '40', '2026-01-01 11:40:00');
        // 非成功狀態的手動洗分 → 不算
        $this->makeManualTransaction($memberId, TransactionEnum::WITHDRAWAL->value, '999', '2026-01-01 11:45:00', status: 0);

        $this->artisan('patch:backfill-member-hour-summaries', [
            '--start' => '2026-01-01 00:00:00',
            '--end' => '2026-01-02 00:00:00',
        ])->assertSuccessful();

        $summary = MemberHourlyStatistic::where('member_id', $memberId)->where('summary_hour', '2026-01-01 11:00:00')->first();
        $this->assertEquals(2, $summary->deposit_count, '代收 1 ＋ 手動開分 1');
        $this->assertEquals('350.0000', $summary->deposit_amount);
        $this->assertEquals(2, $summary->withdrawal_count, '有單洗分 1 ＋ 手動洗分 1（帶 order_id 的帳變不重複）');
        $this->assertEquals('70.0000', $summary->withdrawal_amount);
        // 明細：訂單那筆進三方、手動那筆進後台開分／洗分；總額＝明細相加
        $this->assertEquals(1, $summary->third_party_deposit_count);
        $this->assertEquals('100.0000', $summary->third_party_deposit_amount);
        $this->assertEquals(1, $summary->admin_deposit_count);
        $this->assertEquals('250.0000', $summary->admin_deposit_amount);
        $this->assertEquals(1, $summary->third_party_withdrawal_count);
        $this->assertEquals('30.0000', $summary->third_party_withdrawal_amount);
        $this->assertEquals(1, $summary->admin_withdrawal_count);
        $this->assertEquals('40.0000', $summary->admin_withdrawal_amount);
    }

    /**
     * 4333：member_id=0 是代理自助洗分／上分，不進會員統計；--purge-agent-rows 清掉既有被灌入的列，dry-run 不刪。
     */
    public function test_backfill_excludes_agent_rows_and_purge_option_removes_existing(): void
    {
        // 來源：代理自助洗分（member_id=0）＋ member_id=0 的手動開分 → 都不該進表
        $this->makeWithdrawal(0, '500', WithdrawalOrderStatusEnum::TRANSFERRED->value, '2026-01-01 12:10:00');
        $this->makeManualTransaction(0, TransactionEnum::DEPOSIT->value, '500', '2026-01-01 12:20:00');

        // 既有被灌入的列
        MemberHourlyStatistic::insert([
            'member_id' => 0,
            'summary_hour' => '2025-12-31 08:00:00',
            'withdrawal_count' => 3,
            'withdrawal_amount' => '1500.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        MemberStatistic::insertOrIgnore(['member_id' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $args = ['--start' => '2026-01-01 00:00:00', '--end' => '2026-01-02 00:00:00', '--purge-agent-rows' => true];

        $this->artisan('patch:backfill-member-hour-summaries', $args + ['--dry-run' => true])->assertSuccessful();
        $this->assertEquals(1, MemberHourlyStatistic::where('member_id', 0)->count(), 'dry-run 不刪');

        $this->artisan('patch:backfill-member-hour-summaries', $args)->assertSuccessful();
        $this->assertEquals(0, MemberHourlyStatistic::where('member_id', 0)->count(), '既有列被清掉、來源的 member_id=0 也沒再灌入');
        $this->assertEquals(0, MemberStatistic::where('member_id', 0)->count());
    }

    /**
     * 4333：整桶覆蓋只寫「現在有來源」的桶，來源事後消失的桶舊值會殘留成多計；
     * 回填必須先把窗口內的桶歸零再重算。dry-run 不動。
     */
    public function test_backfill_zeroes_stale_buckets_without_current_sources(): void
    {
        $memberId = 990207;

        // 殘留：13:00 桶有 1 筆提款 200 與代收殘值，但該小時已無任何來源；實際來源只有一張 11:20 打款成功的單（落 11:00 桶）
        MemberHourlyStatistic::insert([
            'member_id' => $memberId,
            'summary_hour' => '2026-01-01 13:00:00',
            'withdrawal_count' => 1,
            'withdrawal_amount' => '200.0000',
            'third_party_withdrawal_count' => 1,
            'third_party_withdrawal_amount' => '200.0000',
            'deposit_count' => 5,
            'deposit_amount' => '999.0000',
            'third_party_deposit_count' => 5,
            'third_party_deposit_amount' => '999.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->makeWithdrawal($memberId, '200', WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, '2026-01-01 11:20:00');

        $args = ['--start' => '2026-01-01 00:00:00', '--end' => '2026-01-02 00:00:00'];

        $this->artisan('patch:backfill-member-hour-summaries', $args + ['--dry-run' => true])->assertSuccessful();
        $stale = MemberHourlyStatistic::where('member_id', $memberId)->where('summary_hour', '2026-01-01 13:00:00')->first();
        $this->assertEquals(1, $stale->withdrawal_count, 'dry-run 不歸零');

        $this->artisan('patch:backfill-member-hour-summaries', $args)->assertSuccessful();

        $stale = MemberHourlyStatistic::where('member_id', $memberId)->where('summary_hour', '2026-01-01 13:00:00')->first();
        $this->assertEquals(0, $stale->withdrawal_count, '沒有來源的舊桶被歸零');
        $this->assertEquals('0.0000', $stale->withdrawal_amount);
        $this->assertEquals(0, $stale->deposit_count, '代收殘留同樣歸零');
        $this->assertEquals(0, $stale->third_party_withdrawal_count, '明細欄一併歸零');
        $this->assertEquals(0, $stale->third_party_deposit_count);

        $fresh = MemberHourlyStatistic::where('member_id', $memberId)->where('summary_hour', '2026-01-01 11:00:00')->first();
        $this->assertEquals(1, $fresh->withdrawal_count);
        $this->assertEquals('200.0000', $fresh->withdrawal_amount);

        $this->assertEquals('200.0000', MemberHourlyStatistic::where('member_id', $memberId)->sum('withdrawal_amount'), '總額不重複');
    }

    /**
     * 4333：金流商 AutoDeposit（cash_flows.id 5，前台上傳憑證、後台審核）的成功單歸「自助上分」，其餘金流商歸「三方充值」；
     * 兩者相加＝deposit_*。
     */
    public function test_backfill_splits_auto_deposit_from_third_party_deposit(): void
    {
        $memberId = 990208;
        $autoOrderTypeId = (int) DB::table('order_types')->insertGetId([
            'cash_flow_id' => CashFlowEnum::DEPOSIT_AUTO_DEPOSIT,
            'parent' => 0,
            'name' => 'test auto deposit',
            'key' => 'test_auto_' . $memberId,
            'type' => 2,
            'currency_id' => 1,
            'admin' => 0,
        ]);

        $this->makeOrder($memberId, '100', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 14:10:00');
        $this->makeOrder($memberId, '300', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 14:20:00', orderTypeId: $autoOrderTypeId);

        $this->artisan('patch:backfill-member-hour-summaries', [
            '--start' => '2026-01-01 00:00:00',
            '--end' => '2026-01-02 00:00:00',
        ])->assertSuccessful();

        $summary = MemberHourlyStatistic::where('member_id', $memberId)->where('summary_hour', '2026-01-01 14:00:00')->first();
        $this->assertEquals(2, $summary->deposit_count);
        $this->assertEquals('400.0000', $summary->deposit_amount);
        $this->assertEquals(1, $summary->third_party_deposit_count);
        $this->assertEquals('100.0000', $summary->third_party_deposit_amount);
        $this->assertEquals(1, $summary->auto_deposit_count);
        $this->assertEquals('300.0000', $summary->auto_deposit_amount);
    }

    private function makeOrder(int $memberId, string $point, int $status, string $payTime, int $orderTypeId = 0): void
    {
        $order = Order::factory()->create([
            'member_id' => $memberId,
            'order_type_id' => $orderTypeId,
            'admin' => 0,
            'point' => $point,
            'status' => $status,
        ]);
        // 代收以 pay_time 分桶：設定歷史付款成功時間（updated_at 刻意設不同，驗證回填不受 updated_at 漂移影響）
        DB::table('orders')->where('id', $order->id)->update([
            'pay_time' => $payTime,
            'updated_at' => now(),
        ]);
    }

    private function makeWithdrawal(
        int $memberId,
        string $point,
        int $status,
        string $paymentTime,
        string $amount = '0',
        string $rate = '1',
    ): void {
        $order = WithdrawalOrder::factory()->create([
            'member_id' => $memberId,
            'point' => $point,
            'status' => $status,
        ]);
        // 代付以 payment_time 分桶：updated_at 刻意設不同（模擬 ON UPDATE CURRENT_TIMESTAMP 的失真），驗證回填不受影響
        DB::table('withdrawal_orders')->where('id', $order->id)->update([
            'payment_time' => $paymentTime,
            'amount' => $amount,
            'rate' => $rate,
            'updated_at' => now(),
        ]);
    }
}
