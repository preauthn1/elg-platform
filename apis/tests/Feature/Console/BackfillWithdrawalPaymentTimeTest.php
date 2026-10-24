<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\WithdrawalOrder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 驗證回填 command：status=24 的訂單依來源優先序回填 payment_time，且不覆蓋既有值。
 *
 * php artisan test tests/Feature/Console/BackfillWithdrawalPaymentTimeTest.php
 */
class BackfillWithdrawalPaymentTimeTest extends TestCase
{
    public function test_backfills_from_first_payout_record_when_multiple_exist(): void
    {
        $order = WithdrawalOrder::factory()->create(['status' => 24, 'payment_time' => null]);

        $this->makePayoutRecord($order->id, '2026-01-01 03:00:00'); // 第一筆（id 較小）
        $this->makePayoutRecord($order->id, '2026-01-01 05:00:00'); // 第二筆

        $this->artisan('patch:backfill-withdrawal-payment-time')->assertSuccessful();

        $this->assertSame('2026-01-01 03:00:00', $order->fresh()->payment_time);
    }

    public function test_backfills_from_created_at_when_no_payout_record(): void
    {
        $order = WithdrawalOrder::factory()->create(['status' => 24, 'payment_time' => null]);
        DB::table('withdrawal_orders')->where('id', $order->id)->update(['created_at' => '2026-02-01 08:00:00']);

        $this->artisan('patch:backfill-withdrawal-payment-time')->assertSuccessful();

        $this->assertSame('2026-02-01 08:00:00', $order->fresh()->payment_time);
    }

    public function test_does_not_overwrite_existing_payment_time(): void
    {
        $order = WithdrawalOrder::factory()->create(['status' => 24, 'payment_time' => '2020-01-01 00:00:00']);
        $this->makePayoutRecord($order->id, '2026-01-01 03:00:00');

        $this->artisan('patch:backfill-withdrawal-payment-time')->assertSuccessful();

        $this->assertSame('2020-01-01 00:00:00', $order->fresh()->payment_time);
    }

    public function test_ignores_non_success_status(): void
    {
        $order = WithdrawalOrder::factory()->create(['status' => 22, 'payment_time' => null]);

        $this->artisan('patch:backfill-withdrawal-payment-time')->assertSuccessful();

        $this->assertNull($order->fresh()->payment_time);
    }

    public function test_dry_run_does_not_write(): void
    {
        $order = WithdrawalOrder::factory()->create(['status' => 24, 'payment_time' => null]);
        $this->makePayoutRecord($order->id, '2026-01-01 03:00:00');

        $this->artisan('patch:backfill-withdrawal-payment-time', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($order->fresh()->payment_time);
    }

    private function makePayoutRecord(int $withdrawalOrderId, string $callbackTime): void
    {
        DB::table('withdrawal_payout_records')->insert([
            'cash_flow_id' => 0,
            'agent_cash_flow_id' => 0,
            'withdrawal_order_id' => $withdrawalOrderId,
            'transaction_no' => 'T' . uniqid(),
            'third_party_transaction_no' => 'TP' . uniqid(),
            'result_amount' => '0.0000',
            'result_code' => '00000',
            'is_success_payout' => 1,
            'transaction_time' => $callbackTime,
            'sign' => '',
            'payout_order_request_body' => '',
            'payout_order_response' => '',
            'callback_request_body' => '',
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
            'callback_time' => $callbackTime,
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);
    }
}
