<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TrustPay;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\TrustPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * TrustPay 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TrustPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    private string $secretKey = 'test_secret_key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'API_URL' => 'https://api.trustpay.test',
            'SECRET_KEY' => $this->secretKey,
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;
        $this->agentCashFlow->save();

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function createPayoutRecord(int $withdrawalOrderId, string $transactionNo, array $overrides = []): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create(array_merge([
            'cash_flow_id' => $this->agentCashFlow->cash_flow_id ?? 1,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $withdrawalOrderId,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => 'TPO_' . $transactionNo,
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

    private function createWithdrawalOrder(string $amount = '100.00'): WithdrawalOrder
    {
        $bank = Bank::firstOrCreate(['code' => 'MAYBANK'], [
            'name' => 'Maybank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYBANK',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => $amount,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    /**
     * 產生有效的回調簽名：SHA256(transaction_reference + amount + currency + secretKey)
     */
    private function generateHash(string $transactionReference, string $amount, string $currency): string
    {
        $signString = $transactionReference . $amount . $currency;

        return hash_hmac('sha256', $signString, $this->secretKey);
    }

    private function makeCallback(
        string $refNo,
        string $status,
        string $amount = '100.00',
        string $currency = 'MYR',
        string $transactionReference = 'TRUST_TXN_001',
    ): WithdrawalCallbackResponseData {
        $normalizedAmount = Common::numAdd('0', $amount, 2);
        $hash = $this->generateHash($transactionReference, $normalizedAmount, $currency);

        $request = Request::create('/callback', 'POST', [
            'amount' => $amount,
            'currency' => $currency,
            'hash' => $hash,
            'ref_no' => $refNo,
            'status' => $status,
            'transaction_reference' => $transactionReference,
        ]);

        return (new WithdrawalCallback($this->agentCashFlow))->handle($request);
    }

    /**
     * 測試成功代付回調（status = completed）→ 24
     */
    public function test_success_maps_to_24(): void
    {
        $order = $this->createWithdrawalOrder();
        $this->createPayoutRecord($order->id, 'TRUST_REF_001');

        $result = $this->makeCallback(refNo: 'TRUST_REF_001', status: 'completed');

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $order->status);
    }

    /**
     * 測試失敗代付回調（status = failed）→ 29
     */
    public function test_failed_maps_to_29(): void
    {
        $order = $this->createWithdrawalOrder();
        $this->createPayoutRecord($order->id, 'TRUST_REF_002');

        $result = $this->makeCallback(refNo: 'TRUST_REF_002', status: 'failed', transactionReference: 'TRUST_TXN_002');

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $order->status);
    }

    /**
     * 測試三態：status = pending → 保持22，回傳成功響應
     */
    public function test_pending_keeps_status_22(): void
    {
        $order = $this->createWithdrawalOrder();
        $this->createPayoutRecord($order->id, 'TRUST_REF_003');

        $result = $this->makeCallback(refNo: 'TRUST_REF_003', status: 'pending', transactionReference: 'TRUST_TXN_003');

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        // 維持22，訂單未結算
        $order->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, $order->status);
    }
}
