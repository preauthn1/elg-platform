<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Traits;

use App\Enums\Payment\WithdrawalCallbackOutcome;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\AbstractMethod;
use Tests\TestCase;

/**
 * WithdrawalCallbackTrait 回調歸屬與冪等主體測試
 *
 * 鎖定 TPower withdrawal_orders 2174206 雙重出款事故的兩個缺陷：
 *  - 缺陷 A：一訂單多 payout record 時，非最新（stale）record 的回調
 *    不得改寫訂單狀態（DgPay 失敗回調把 EpicPay 在途的 22 洗成 29，
 *    解鎖重送 → 重複出款）。
 *  - 缺陷 B：isPayoutRecordProcessed 誤以「訂單」狀態判冪等，
 *    一訂單多 record 時其餘 record 的成功回調被靜默吞掉
 *    （is_success_payout 永久停留 PENDING，資損無告警）。
 *
 * php artisan test tests/Unit/Services/Payment/Traits/WithdrawalCallbackTraitTest.php
 */
class WithdrawalCallbackTraitTest extends TestCase
{
    /** makeDouble() 建立的渠道 id，makeRecord() 用它當 payout record 的出款渠道 */
    private int $agentCashFlowId = 0;

    // ─── 缺陷 B：冪等判定主體必須是 record 自身 ────────────────────────

    public function test_pending_record_is_not_processed_even_if_order_already_succeeded(): void
    {
        [$double, $order] = $this->makeDouble(orderStatus: WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value);
        $record = $this->makeRecord($order, 'TXN-B1', WithdrawalPayoutStatusEnum::PENDING);

        // 訂單已被「另一筆 record」推到 24，但本 record 自己從未收過回調：
        // 不得視為已處理，否則本 record 的成功回調（= 第二筆真實出款）被靜默吞掉
        $this->assertFalse($double->exposeIsPayoutRecordProcessed($record));
    }

    public function test_success_record_is_processed_even_if_order_moved_on(): void
    {
        [$double, $order] = $this->makeDouble(orderStatus: WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value);
        $record = $this->makeRecord($order, 'TXN-B2', WithdrawalPayoutStatusEnum::SUCCESS);

        // record 自身已 SUCCESS：重放回調應冪等 ack，與訂單當前狀態無關
        $this->assertTrue($double->exposeIsPayoutRecordProcessed($record));
    }

    // ─── 缺陷 A：只有訂單最新的 record 可驅動訂單狀態 ──────────────────

    public function test_stale_record_failure_callback_cannot_drive_order_status(): void
    {
        [$double, $order] = $this->makeDouble(orderStatus: WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value);
        $staleRecord = $this->makeRecord($order, 'TXN-A1-STALE', WithdrawalPayoutStatusEnum::CALLBACK_FAILED);
        $this->makeRecord($order, 'TXN-A1-LATEST', WithdrawalPayoutStatusEnum::PENDING);

        BotNotify::spy();

        // 2174206 場景重現：stale record（DgPay 1759059）的失敗回調抵達時，
        // 最新 record（EpicPay 1759060）仍在途（22）→ 不得把訂單洗成 29 解鎖重送
        $result = $double->exposeUpdateWithdrawalOrderStatus($order, WithdrawalCallbackOutcome::FAILURE, $staleRecord);

        $this->assertNull($result);
        $this->assertSame(
            WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            (int) $order->refresh()->status,
            '非最新 record 的失敗回調不得改寫訂單狀態（維持 22 鎖定重送）',
        );
        // 拒絕不可無聲：人工需要知道有 stale 回調試圖改單
        BotNotify::shouldHaveReceived('send')->once();
    }

    public function test_latest_record_failure_drives_order_to_failed(): void
    {
        [$double, $order] = $this->makeDouble(orderStatus: WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value);
        $latestRecord = $this->makeRecord($order, 'TXN-A2', WithdrawalPayoutStatusEnum::PENDING);

        // 主線保留：單一（=最新）record 的失敗回調照常 22 → 29，允許換渠道重送
        $double->exposeUpdateWithdrawalOrderStatus($order, WithdrawalCallbackOutcome::FAILURE, $latestRecord);

        $this->assertSame(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, (int) $order->refresh()->status);
    }

    public function test_latest_record_success_drives_order_to_success_with_payment_time(): void
    {
        [$double, $order] = $this->makeDouble(orderStatus: WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value);
        $latestRecord = $this->makeRecord($order, 'TXN-A3', WithdrawalPayoutStatusEnum::PENDING);

        // 主線保留：最新 record 的成功回調照常 22 → 24 並寫 payment_time
        $double->exposeUpdateWithdrawalOrderStatus($order, WithdrawalCallbackOutcome::SUCCESS, $latestRecord);

        $order->refresh();
        $this->assertSame(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, (int) $order->status);
        $this->assertNotNull($order->payment_time);

        // 支付商手續費（成本）= amount × rate × 出款渠道 cost，截到 4 位（target/tickets#4361）
        $expected = Common::numFloor(Common::numMul(Common::numMul((string) $order->amount, (string) $order->rate, 4), '0.025', 8), 4);
        $this->assertSame($expected, (string) $order->payment_cost);
        $this->assertGreaterThan(0, (float) $order->payment_cost);
    }

    public function test_submitted_order_success_callback_resolves_to_success(): void
    {
        [$double, $order] = $this->makeDouble(orderStatus: WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value);
        $latestRecord = $this->makeRecord($order, 'TXN-S1', WithdrawalPayoutStatusEnum::PENDING);

        // #4137：停留 21 訂單的成功回調自動定案 21 → 24 並寫 payment_time
        $double->exposeUpdateWithdrawalOrderStatus($order, WithdrawalCallbackOutcome::SUCCESS, $latestRecord);

        $order->refresh();
        $this->assertSame(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, (int) $order->status);
        $this->assertNotNull($order->payment_time);
    }

    public function test_submitted_order_failure_callback_resolves_to_failed(): void
    {
        [$double, $order] = $this->makeDouble(orderStatus: WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value);
        $latestRecord = $this->makeRecord($order, 'TXN-S2', WithdrawalPayoutStatusEnum::PENDING);

        // #4137：停留 21 訂單的失敗回調自動定案 21 → 29，可重新發起
        $double->exposeUpdateWithdrawalOrderStatus($order, WithdrawalCallbackOutcome::FAILURE, $latestRecord);

        $this->assertSame(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, (int) $order->refresh()->status);
    }

    // ─── fixtures ──────────────────────────────────────────────────────

    private function makeRecord(
        WithdrawalOrder $order,
        string $transactionNo,
        WithdrawalPayoutStatusEnum $status,
    ): WithdrawalPayoutRecord {
        return WithdrawalPayoutRecord::create([
            'cash_flow_id' => 1,
            'agent_cash_flow_id' => $this->agentCashFlowId,
            'withdrawal_order_id' => $order->id,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => '',
            'payout_order_request_body' => '{}',
            'payout_order_response' => '',
            'result_amount' => $order->amount,
            'result_code' => '',
            'transaction_time' => now()->toDateTimeString(),
            'sign' => '',
            'callback_request_body' => '',
            'is_success_payout' => $status->value,
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
        ]);
    }

    /**
     * 建立 test double（含已持久化的 AgentCashFlow）與指定狀態的提款訂單。
     *
     * @return array{0: object, 1: WithdrawalOrder}
     */
    private function makeDouble(int $orderStatus): array
    {
        $agent = Agent::factory()->create();

        $currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'IDR',
            'name' => 'Indonesian Rupiah',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $member = Member::factory()->create(['parent' => $agent->id]);

        $agentCashFlow = AgentCashFlow::create([
            'currency_id' => $currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'cost' => 0.025, // 2.5%：驗成功回調寫入 payment_cost（target/tickets#4361）
            'admin' => 0,
            'info' => json_encode([]),
        ]);
        $agentCashFlow->info = [];
        $this->agentCashFlowId = $agentCashFlow->id;

        $order = WithdrawalOrder::create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'bank_id' => 0,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100000,
            'status' => $orderStatus,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $double = new class($agentCashFlow) extends AbstractMethod
        {
            public function __construct(AgentCashFlow $agentCashFlow)
            {
                $this->agentCashFlow = $agentCashFlow;
                $this->info = [];
            }

            protected function getDriverName(): string
            {
                return 'TestDouble';
            }

            public function exposeIsPayoutRecordProcessed(?WithdrawalPayoutRecord $record): bool
            {
                return $this->isPayoutRecordProcessed($record);
            }

            public function exposeUpdateWithdrawalOrderStatus(
                WithdrawalOrder $order,
                WithdrawalCallbackOutcome $outcome,
                WithdrawalPayoutRecord $payoutRecord,
            ): mixed {
                return $this->updateWithdrawalOrderStatus($order, $outcome, $payoutRecord);
            }
        };

        return [$double, $order];
    }
}
