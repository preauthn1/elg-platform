<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\ZPay;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\ZPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ZPay 代付回調集成測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/ZPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $info = [
            'API_URL' => 'https://example.invalid',
            'TOKEN' => 'test_token',
            'SECRET_KEY' => 'test_secret',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 82,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
            'currency_id' => 19,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * 生成有效的 ZPay itemDesc 簽名
     */
    private function generateValidItemDesc(string $referenceId, string $dateTime, string $amount, string $secret): string
    {
        return md5($referenceId . $dateTime . (float) $amount . $secret);
    }

    /**
     * 創建測試用的 WithdrawalPayoutRecord
     */
    private function createPayoutRecord(int $withdrawalOrderId, string $transactionNo, array $overrides = []): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create(array_merge([
            'cash_flow_id' => $this->agentCashFlow->cash_flow_id ?? 82,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $withdrawalOrderId,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => 'DEFAULT_TPO_' . $transactionNo,
            'result_amount' => 0,
            'result_code' => '',
            'is_success_payout' => 0,
            'transaction_time' => '',
            'sign' => '',
            'payout_order_request_body' => '',
            'payout_order_response' => '',
            'callback_request_body' => '',
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
            'admin' => 0,
        ], $overrides));
    }

    /**
     * 測試成功處理代付回調
     */
    public function test_success(): void
    {
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => 'MBB',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'ZP20240123001');

        $callbackData = [
            'referenceId' => 'ZP20240123001',
            'itemDesc' => $this->generateValidItemDesc('ZP20240123001', '20240123100000', '100.00', 'test_secret'),
            'status' => 1,
            'transactionId' => 90000001,
            'amount' => '100.00',
            'currency' => 'MYR',
            'dateTime' => '20240123100000',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $result = $withdrawalCallback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);
        $this->assertNotNull($result->order);

        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
        $this->assertEquals('100.0000', $payoutRecord->result_amount);
        $this->assertEquals('20240123100000', $payoutRecord->transaction_time);
    }

    /**
     * 測試代付失敗回調
     */
    public function test_failure_callback(): void
    {
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => 'MBB',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'ZP20240123002');

        $callbackData = [
            'referenceId' => 'ZP20240123002',
            'itemDesc' => $this->generateValidItemDesc('ZP20240123002', '20240123100500', '100.00', 'test_secret'),
            'status' => 3,
            'transactionId' => 90000002,
            'amount' => '100.00',
            'currency' => 'MYR',
            'dateTime' => '20240123100500',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $result = $withdrawalCallback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
    }

    /**
     * 測試中間態（PROCESSING=5）保持 22 並回成功響應
     */
    public function test_indeterminate_keeps_22_and_acks(): void
    {
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => 'MBB',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'ZP20240123010');

        $callbackData = [
            'referenceId' => 'ZP20240123010',
            'itemDesc' => $this->generateValidItemDesc('ZP20240123010', '20240123110000', '100.00', 'test_secret'),
            'status' => 5, // PROCESSING → INDETERMINATE
            'transactionId' => 90000010,
            'amount' => '100.00',
            'currency' => 'MYR',
            'dateTime' => '20240123110000',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);
        $result = $withdrawalCallback->handle($request);

        // 應回成功響應 ack 三方
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        // 訂單 status 仍為 22
        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, $withdrawalOrder->status);

        // payout record 不應被結算
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->is_success_payout);
        $this->assertEquals('', $payoutRecord->result_code);
    }

    /**
     * 測試簽名驗證失敗
     */
    public function test_invalid_signature(): void
    {
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => 'MBB',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $this->createPayoutRecord($withdrawalOrder->id, 'ZP20240123003');

        $callbackData = [
            'referenceId' => 'ZP20240123003',
            'itemDesc' => 'INVALID',
            'status' => 1,
            'transactionId' => 90000003,
            'amount' => '100.00',
            'currency' => 'MYR',
            'dateTime' => '20240123101000',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('ZPay withdrawal callback signature verification failed');

        $withdrawalCallback->handle($request);
    }
}
