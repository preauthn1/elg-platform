<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\OrderStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Models\MemberHourlyStatistic;
use App\Models\Order;
use App\Models\WithdrawalOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesManualTransactionLogs;
use Tests\TestCase;

/**
 * 驗證每小時校正 command：對齊整點只重算「往前 N 個完整整點」（排除進行中的當前小時與窗口外），
 * 且以全量重算覆蓋語意把飄移的匯總拉回與來源訂單一致。
 *
 * php artisan test tests/Feature/Console/CorrectMemberHourlyStatisticsTest.php
 */
class CorrectMemberHourlyStatisticsTest extends TestCase
{
    use CreatesManualTransactionLogs;

    /** @var array<int, int> 本測試自行建立的 payout 記錄 id，tearDown 逐筆刪除 */
    private array $payoutRecordIds = [];

    protected function tearDown(): void
    {
        if ($this->payoutRecordIds !== []) {
            DB::table('withdrawal_payout_records')->whereIn('id', $this->payoutRecordIds)->delete();
            $this->payoutRecordIds = [];
        }

        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_recomputes_completed_hours_in_window(): void
    {
        // 現在 = 10:45，hours=2 → 窗口 [08:00, 10:00)，只含 08:00 / 09:00 兩桶
        Carbon::setTestNow('2026-01-01 10:45:00');
        $memberId = 990301;

        // 08:00 桶：2 筆代收（100 + 50）
        $this->makeOrder($memberId, '100', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 08:10:00');
        $this->makeOrder($memberId, '50', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 08:40:00');
        // 09:00 桶：1 筆代收（70）、1 筆代付（30）
        $this->makeOrder($memberId, '70', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 09:15:00');
        $this->makeWithdrawal($memberId, '30', WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, '2026-01-01 09:20:00');
        // 進行中的當前小時（10:xx）→ 不該被算
        $this->makeOrder($memberId, '999', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 10:05:00');
        // 窗口外（07:xx）→ 不該被算
        $this->makeOrder($memberId, '888', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 07:50:00');

        $this->artisan('member:correct-hourly-statistics', ['--hours' => 2])->assertSuccessful();

        // 只建 08:00 / 09:00 兩桶
        $this->assertEquals(2, MemberHourlyStatistic::where('member_id', $memberId)->count());

        $h08 = MemberHourlyStatistic::where('member_id', $memberId)->where('summary_hour', '2026-01-01 08:00:00')->first();
        $this->assertEquals(2, $h08->deposit_count);
        $this->assertEquals('150.0000', $h08->deposit_amount);

        $h09 = MemberHourlyStatistic::where('member_id', $memberId)->where('summary_hour', '2026-01-01 09:00:00')->first();
        $this->assertEquals(1, $h09->deposit_count);
        $this->assertEquals('70.0000', $h09->deposit_amount);
        $this->assertEquals(1, $h09->withdrawal_count);
        $this->assertEquals('30.0000', $h09->withdrawal_amount);
    }

    public function test_overwrites_drifted_summary(): void
    {
        Carbon::setTestNow('2026-01-01 10:45:00');
        $memberId = 990302;

        // 模擬即時累加飄移：09:00 桶被灌成錯誤的多計值
        MemberHourlyStatistic::insert([
            'member_id' => $memberId,
            'summary_hour' => '2026-01-01 09:00:00',
            'deposit_count' => 99,
            'deposit_amount' => '9999.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 實際來源只有 1 筆代收 100
        $this->makeOrder($memberId, '100', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 09:10:00');

        $this->artisan('member:correct-hourly-statistics', ['--hours' => 2])->assertSuccessful();

        // 全量重算覆蓋回正確值
        $summary = MemberHourlyStatistic::where('member_id', $memberId)->where('summary_hour', '2026-01-01 09:00:00')->first();
        $this->assertEquals(1, $summary->deposit_count);
        $this->assertEquals('100.0000', $summary->deposit_amount);
    }

    public function test_dry_run_does_not_write(): void
    {
        Carbon::setTestNow('2026-01-01 10:45:00');
        $memberId = 990303;
        $this->makeOrder($memberId, '100', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 09:10:00');

        $this->artisan('member:correct-hourly-statistics', ['--hours' => 2, '--dry-run' => true])->assertSuccessful();

        $this->assertEquals(0, MemberHourlyStatistic::where('member_id', $memberId)->count());
    }

    /**
     * 4333：手動開分洗分（transaction_logs type 4/5、order_id=0）與訂單相加重算；只算窗口內、只算 member_id>0。
     * 這條排程是代理後台→internal 路徑唯一的補入管道，也是客服路徑即時 dispatch 的保險。
     */
    public function test_recomputes_manual_transactions_in_window_and_excludes_agent_rows(): void
    {
        Carbon::setTestNow('2026-01-01 10:45:00');
        $memberId = 990304;

        $this->makeOrder($memberId, '100', OrderStatusEnum::DEPOSIT_FINISHED->value, '2026-01-01 09:10:00');
        $this->makeManualTransaction($memberId, TransactionEnum::DEPOSIT->value, '60', '2026-01-01 09:20:00');
        $this->makeManualTransaction($memberId, TransactionEnum::WITHDRAWAL->value, '25', '2026-01-01 09:30:00');
        // 進行中的當前小時 → 不算
        $this->makeManualTransaction($memberId, TransactionEnum::DEPOSIT->value, '777', '2026-01-01 10:05:00');
        // 代理自助上分（member_id=0）→ 不算、不建列
        $this->makeManualTransaction(0, TransactionEnum::DEPOSIT->value, '500', '2026-01-01 09:40:00');

        $this->artisan('member:correct-hourly-statistics', ['--hours' => 2])->assertSuccessful();

        $h09 = MemberHourlyStatistic::where('member_id', $memberId)->where('summary_hour', '2026-01-01 09:00:00')->first();
        $this->assertEquals(2, $h09->deposit_count, '代收 1 ＋ 手動開分 1');
        $this->assertEquals('160.0000', $h09->deposit_amount);
        $this->assertEquals(1, $h09->withdrawal_count);
        $this->assertEquals('25.0000', $h09->withdrawal_amount);
        // 六類明細
        $this->assertEquals(1, $h09->third_party_deposit_count);
        $this->assertEquals('100.0000', $h09->third_party_deposit_amount);
        $this->assertEquals(1, $h09->admin_deposit_count);
        $this->assertEquals('60.0000', $h09->admin_deposit_amount);
        $this->assertEquals(1, $h09->admin_withdrawal_count);
        $this->assertEquals('25.0000', $h09->admin_withdrawal_amount);
        $this->assertEquals(0, $h09->third_party_withdrawal_count);

        $this->assertEquals(0, MemberHourlyStatistic::where('member_id', $memberId)->where('summary_hour', '2026-01-01 10:00:00')->count());
        $this->assertEquals(0, MemberHourlyStatistic::where('member_id', 0)->where('summary_hour', '2026-01-01 09:00:00')->count());
    }

    /**
     * 缺 payment_time 的 status=24 單，以對應 payout 記錄的 callback_time 補齊，
     * 且 updated_at 必須一併寫成同一個打款時間，不可留下腳本執行當下時間。
     */
    public function test_backfills_missing_payment_time_from_callback_time(): void
    {
        Carbon::setTestNow('2026-01-01 10:45:00');
        $memberId = 990305;

        $orderId = $this->makeWithdrawalMissingPaymentTime($memberId, '80', '2026-01-01 09:20:00');

        $this->artisan('member:correct-hourly-statistics', ['--hours' => 2])->assertSuccessful();

        $row = DB::table('withdrawal_orders')->where('id', $orderId)->first();

        $this->assertSame('2026-01-01 09:20:00', (string) $row->payment_time);
        // 🔴 updated_at 帶 ON UPDATE CURRENT_TIMESTAMP，未顯式指定會被蓋成 10:45（腳本執行當下）
        $this->assertSame('2026-01-01 09:20:00', (string) $row->updated_at);
    }

    /** 回填排在重算之前，補好的單本輪就要被算進匯總，不必等下一輪 */
    public function test_backfilled_order_is_included_in_same_run_summary(): void
    {
        Carbon::setTestNow('2026-01-01 10:45:00');
        $memberId = 990306;

        $this->makeWithdrawalMissingPaymentTime($memberId, '65', '2026-01-01 09:30:00');

        $this->artisan('member:correct-hourly-statistics', ['--hours' => 2])->assertSuccessful();

        $h09 = MemberHourlyStatistic::where('member_id', $memberId)
            ->where('summary_hour', '2026-01-01 09:00:00')
            ->first();

        $this->assertNotNull($h09, '補上 payment_time 的單應於同一輪納入匯總');
        $this->assertEquals(1, $h09->withdrawal_count);
        $this->assertEquals('65.0000', $h09->withdrawal_amount);
    }

    /** dry-run 不得寫入任何資料，回填也一樣 */
    public function test_dry_run_does_not_backfill_payment_time(): void
    {
        Carbon::setTestNow('2026-01-01 10:45:00');
        $memberId = 990307;

        $orderId = $this->makeWithdrawalMissingPaymentTime($memberId, '40', '2026-01-01 09:10:00');

        $this->artisan('member:correct-hourly-statistics', ['--hours' => 2, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertNull(DB::table('withdrawal_orders')->where('id', $orderId)->value('payment_time'));
    }

    /** payment-time-limit=0 表示略過回填，讓排程可在必要時單獨關閉這段 */
    public function test_zero_limit_skips_backfill(): void
    {
        Carbon::setTestNow('2026-01-01 10:45:00');
        $memberId = 990308;

        $orderId = $this->makeWithdrawalMissingPaymentTime($memberId, '40', '2026-01-01 09:10:00');

        $this->artisan('member:correct-hourly-statistics', ['--hours' => 2, '--payment-time-limit' => 0])
            ->assertSuccessful();

        $this->assertNull(DB::table('withdrawal_orders')->where('id', $orderId)->value('payment_time'));
    }

    /**
     * callback_time 不可用（NULL 或零日期）時整筆跳過，不得寫入。
     *
     * 🔴 關鍵：withdrawal_orders.updated_at 帶 ON UPDATE CURRENT_TIMESTAMP，只要該列被 UPDATE 命中，
     * updated_at 就會被壓成排程執行當下時間。因此「取不到打款時間」的單必須連 UPDATE 都匹配不到，
     * 而不是寫入 NULL 或退用其他時間——否則會留下 payment_time 仍為空、updated_at 卻被改動的錯亂資料。
     */
    #[DataProvider('unusableCallbackTimeProvider')]
    public function test_skips_order_when_callback_time_unusable(?string $callbackTime): void
    {
        Carbon::setTestNow('2026-01-01 10:45:00');
        $memberId = 990309;

        $orderId = $this->makeWithdrawalMissingPaymentTime($memberId, '55', '2026-01-01 09:20:00');

        DB::table('withdrawal_payout_records')
            ->where('withdrawal_order_id', $orderId)
            ->update(['callback_time' => $callbackTime]);

        $before = DB::table('withdrawal_orders')->where('id', $orderId)->value('updated_at');

        $this->artisan('member:correct-hourly-statistics', ['--hours' => 2])->assertSuccessful();

        $after = DB::table('withdrawal_orders')->where('id', $orderId)->first();

        $this->assertNull($after->payment_time, '取不到打款時間時不得寫入 payment_time');
        $this->assertSame(
            (string) $before,
            (string) $after->updated_at,
            'updated_at 不得被 ON UPDATE 壓成排程執行當下時間',
        );
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function unusableCallbackTimeProvider(): array
    {
        return [
            'NULL' => [null],
            // 測試庫 sql_mode 含 NO_ZERO_DATE 寫不進 '0000-00-00'，改以同樣落在下界之下的荒謬舊值驗同一道防護
            '荒謬舊日期' => ['1970-01-01 00:00:00'],
        ];
    }

    /**
     * 建立一筆 status=24 但 payment_time 為 NULL 的提款單，並配上成功的 payout 記錄。
     *
     * payout 的 created_at 刻意早於 callback_time（模擬正式庫「送出請求」與「回調確認」的落差），
     * 用來驗證回填取的是 callback_time 而非 created_at。
     *
     * @return int 提款單 id
     */
    private function makeWithdrawalMissingPaymentTime(int $memberId, string $point, string $callbackTime): int
    {
        $order = WithdrawalOrder::factory()->create([
            'member_id' => $memberId,
            'point' => $point,
            'status' => WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value,
        ]);

        DB::table('withdrawal_orders')->where('id', $order->id)->update(['payment_time' => null]);

        $payoutId = DB::table('withdrawal_payout_records')->insertGetId([
            'withdrawal_order_id' => $order->id,
            'is_success_payout' => 1,
            'callback_time' => $callbackTime,
            // transaction_time 刻意寫成 +8 的字串：驗證回填取的是 callback_time，不受三方原始時區字串影響
            'transaction_time' => Carbon::parse($callbackTime)->addHours(8)->toDateTimeString(),
            'transaction_no' => 'T' . $order->id,
            'third_party_transaction_no' => 'TP' . $order->id,
            'result_amount' => $point,
            'result_code' => 'success',
            'sign' => '',
            'payout_order_request_body' => '',
            'payout_order_response' => '',
            'callback_request_body' => '',
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
            'created_at' => Carbon::parse($callbackTime)->subMinutes(3)->toDateTimeString(),
            'updated_at' => $callbackTime,
        ]);

        $this->payoutRecordIds[] = $payoutId;

        return $order->id;
    }

    private function makeOrder(int $memberId, string $point, int $status, string $payTime): void
    {
        $order = Order::factory()->create([
            'member_id' => $memberId,
            'order_type_id' => 0,
            'admin' => 0,
            'point' => $point,
            'status' => $status,
        ]);
        // 代收以 pay_time 分桶：設定歷史付款成功時間（updated_at 刻意設不同，驗證校正不受 updated_at 漂移影響）
        DB::table('orders')->where('id', $order->id)->update([
            'pay_time' => $payTime,
            'updated_at' => now(),
        ]);
    }

    private function makeWithdrawal(int $memberId, string $point, int $status, string $paymentTime): void
    {
        $order = WithdrawalOrder::factory()->create([
            'member_id' => $memberId,
            'point' => $point,
            'status' => $status,
        ]);
        // 代付以 payment_time 分桶：updated_at 刻意設不同（模擬 ON UPDATE CURRENT_TIMESTAMP 的失真），驗證校正不受影響
        DB::table('withdrawal_orders')->where('id', $order->id)->update([
            'payment_time' => $paymentTime,
            'updated_at' => now(),
        ]);
    }
}
