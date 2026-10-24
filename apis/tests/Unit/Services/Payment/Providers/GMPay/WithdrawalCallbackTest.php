<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\GMPay;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\GMPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * GMPay 代付回調測試（standalone WithdrawalCallback）。
 *
 * 關鍵：GMPay 代付回調驗簽拼接 transaction_reference + amount + currency
 * （與代收回調一致、與 MajesticPay 的 ref_no 不同）。makeCallback 以 transaction_reference
 * 產生 hash——若受測碼驗簽欄位錯誤，completed 測試會因 Invalid signature 失敗。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/GMPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    private string $secretKey = 'gmpay_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'SGD',
            'name' => 'Singapore Dollar',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'API_URL' => 'https://api.gmpaysg.test',
            'SECRET_KEY' => $this->secretKey,
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 73,
            'cash_flow_info_id' => 1,
            'rate' => 3.146,
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

    private function createPayoutRecord(int $withdrawalOrderId, string $transactionNo, string $thirdPartyNo, array $overrides = []): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create(array_merge([
            'cash_flow_id' => $this->agentCashFlow->cash_flow_id ?? 1,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $withdrawalOrderId,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => $thirdPartyNo,
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
        $bank = Bank::firstOrCreate(['code' => 'DBS'], [
            'name' => 'DBS Bank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'DBS',
            'bank_name' => 'DBS Bank',
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
     * GMPay 代付回調 hash：SHA256(transaction_reference + amount + currency)
     */
    private function generateHash(string $transactionReference, string $amount, string $currency): string
    {
        $stringToSign = $transactionReference . $amount . $currency;

        return hash_hmac('sha256', $stringToSign, $this->secretKey);
    }

    private function makeCallback(
        string $transactionNo,
        string $thirdPartyNo,
        string $status,
        string $amount = '100.00',
        string $currency = 'SGD',
    ): WithdrawalCallbackResponseData {
        // GMPay 以 transaction_reference（thirdPartyNo）拼 hash
        $hash = $this->generateHash($thirdPartyNo, $amount, $currency);

        $request = Request::create('/callback', 'POST', [
            'status' => $status,
            'currency' => $currency,
            'amount' => $amount,
            'hash' => $hash,
            'ref_no' => $transactionNo,
            'platform_charge' => '0',
            'transaction_reference' => $thirdPartyNo,
            'updated_at' => now()->toDateTimeString(),
            'is_sandbox' => 0,
        ]);

        return (new WithdrawalCallback($this->agentCashFlow))->handle($request);
    }

    /**
     * 成功代付回調（status = completed）→ THIRD_PARTY_SUCCESS(24)。
     * 同時隱含驗證：GMPay 以 transaction_reference 驗簽成功。
     */
    public function test_completed_maps_to_24(): void
    {
        $order = $this->createWithdrawalOrder();
        $this->createPayoutRecord($order->id, 'GMPAY_REF_001', 'GMPAY_TXN_001');

        $result = $this->makeCallback(
            transactionNo: 'GMPAY_REF_001',
            thirdPartyNo: 'GMPAY_TXN_001',
            status: 'completed',
        );

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $order->status);
    }

    /**
     * 失敗代付回調（status = failed）→ THIRD_PARTY_FAILED(29)。
     */
    public function test_failed_maps_to_29(): void
    {
        $order = $this->createWithdrawalOrder();
        $this->createPayoutRecord($order->id, 'GMPAY_REF_002', 'GMPAY_TXN_002');

        $result = $this->makeCallback(
            transactionNo: 'GMPAY_REF_002',
            thirdPartyNo: 'GMPAY_TXN_002',
            status: 'failed',
        );

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $order->status);
    }

    /**
     * 三態：status = pending → 保持 PAYMENT_PROCESSING(22)，回傳成功，payout 未結算。
     */
    public function test_pending_keeps_status_22(): void
    {
        $order = $this->createWithdrawalOrder();
        $payoutRecord = $this->createPayoutRecord($order->id, 'GMPAY_REF_003', 'GMPAY_TXN_003');

        $result = $this->makeCallback(
            transactionNo: 'GMPAY_REF_003',
            thirdPartyNo: 'GMPAY_TXN_003',
            status: 'pending',
        );

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        $order->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, $order->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->is_success_payout);
    }

    /**
     * 空憑證守衛：SECRET_KEY 為空時 checkHash() 的 HMAC key 段為空、簽名可離線計算，
     * 攻擊者能偽造回調洗訂單。守衛須在驗簽前先擋下（回 Failed），且訂單維持 22。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->createWithdrawalOrder();
        $this->createPayoutRecord($order->id, 'GMPAY_REF_EMPTY', 'GMPAY_TXN_EMPTY');

        $emptyInfo = ['API_URL' => 'https://api.gmpaysg.test', 'SECRET_KEY' => ''];
        $emptyAcf = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 73,
            'cash_flow_info_id' => 1,
            'rate' => 3.146,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $emptyAcf->info = $emptyInfo;

        // 攻擊者以空 secret 自算 hash
        $forgedHash = hash_hmac('sha256', 'GMPAY_TXN_EMPTY' . '100.00' . 'SGD', '');
        $request = Request::create('/callback', 'POST', [
            'status' => 'completed',
            'currency' => 'SGD',
            'amount' => '100.00',
            'hash' => $forgedHash,
            'ref_no' => 'GMPAY_REF_EMPTY',
            'platform_charge' => '0',
            'transaction_reference' => 'GMPAY_TXN_EMPTY',
            'updated_at' => now()->toDateTimeString(),
            'is_sandbox' => 0,
        ]);

        $result = (new WithdrawalCallback($emptyAcf))->handle($request);

        $this->assertSame('Failed', $result->response);

        $order->refresh();
        $this->assertSame(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, $order->status);
    }
}
