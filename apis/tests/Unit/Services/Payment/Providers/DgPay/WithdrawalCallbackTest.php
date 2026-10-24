<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\DgPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\DgPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * DgPay 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/DgPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    protected Agent $agent;

    protected Currency $currency;

    protected Member $member;

    protected AgentCashFlow $agentCashFlow;

    private array $info = [
        'APIUrl' => 'https://dgpayapi.test',
        'Merchant' => 'dgpay_test',
        'MerchantKey' => 'test-merchant-key-abc',
        'Currency' => 'MYR',
        'PROVIDER_ID' => '5',
        'PROVIDER_TYPE' => '20',
        'available_banks' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::factory()->create();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
        ]);

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'admin' => 0,
            'info' => json_encode($this->info),
        ]);

        $this->agentCashFlow->info = $this->info;
    }

    private function makeWithdrawalOrder(int $status = 22): WithdrawalOrder
    {
        $bank = Bank::firstOrCreate(['code' => 'MAYB'], [
            'name' => 'Maybank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYB',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100,
            'status' => $status,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    private function createPayoutRecord(int $withdrawalOrderId, string $transactionNo, array $overrides = []): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create(array_merge([
            'cash_flow_id' => $this->agentCashFlow->cash_flow_id ?? 1,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $withdrawalOrderId,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => 'W' . $transactionNo,
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

    private function runCallback(string $orderId, string $status, string $amount = '100', ?string $securityToken = [REDACTED] WithdrawalCallbackResponseData
    {
        $securityToken ??= strtolower(md5('dgpay_test' . $orderId . $amount . $status . 'test-merchant-key-abc'));

        $request = Request::create('/callback', 'POST', [
            'orderId' => $orderId,
            'status' => $status,
            'amount' => $amount,
            'securityToken' => $securityToken,
        ]);

        return (new WithdrawalCallback($this->agentCashFlow))->handle($request);
    }

    /**
     * 驗簽失敗（securityToken 不符）→ 硬拒，回 code -100，訂單不變
     */
    public function test_invalid_signature_is_rejected(): void
    {
        $order = $this->makeWithdrawalOrder();
        $transactionNo = 'DG' . $order->id;
        $this->createPayoutRecord($order->id, $transactionNo);

        $result = $this->runCallback($transactionNo, '20', securityToken: [REDACTED]');

        $this->assertSame(-100, $result->response['code']);

        $order->refresh();
        $this->assertSame(22, $order->status);
    }

    /**
     * 新組（DGPAY）binding 用 SCREAMING_SNAKE key（MERCHANT / MERCHANT_KEY）時，
     * 驗簽經 DgPayTrait::resolveOpCode/resolveSecretKey 雙鍵相容仍成功 → 正常處理。
     * 防止「回調鏈直讀 $this->info['Merchant'] 未相容新命名 → 新組回調全被硬拒」的 Critical 回歸。
     */
    public function test_screaming_snake_merchant_keys_verify_and_process(): void
    {
        $order = $this->makeWithdrawalOrder();
        $transactionNo = 'DG' . $order->id;

        $snakeInfo = [
            'API_URL' => 'https://dgpayapi.test',
            'MERCHANT' => 'dgpay_test',
            'MERCHANT_KEY' => 'test-merchant-key-abc',
            'CURRENCY' => 'MYR',
            'PROVIDER_ID' => '5',
            'PROVIDER_TYPE' => '20',
            'available_banks' => [],
        ];
        $snakeAcf = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 120,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'admin' => 0,
            'info' => json_encode($snakeInfo),
        ]);
        $snakeAcf->info = $snakeInfo;

        // 代付回調只能處理自己渠道的代付請求（target/tickets#4449 需求 4）。本案例刻意用另一個渠道
        // 驗證雙鍵相容，所以 payout record 要掛在這個渠道底下，否則會先被渠道檢查擋掉。
        $this->createPayoutRecord($order->id, $transactionNo, ['agent_cash_flow_id' => $snakeAcf->id]);

        // securityToken 以相同 merchant/key 值計算；若雙鍵相容失效，opCode/secretKey 讀成空 → 驗簽不符被硬拒 -100
        $securityToken = [REDACTED]'dgpay_test' . $transactionNo . '100' . '20' . 'test-merchant-key-abc'));
        $request = Request::create('/callback', 'POST', [
            'orderId' => $transactionNo,
            'status' => '20',
            'amount' => '100',
            'securityToken' => $securityToken,
        ]);

        $result = (new WithdrawalCallback($snakeAcf))->handle($request);

        $this->assertSame(0, $result->response['code']);
        $order->refresh();
        $this->assertSame(24, $order->status);
    }

    /**
     * 正常成功回調（status=20）→ 訂單 24、紀錄成功
     */
    public function test_success_callback_updates_order_to_24(): void
    {
        $order = $this->makeWithdrawalOrder();
        $transactionNo = 'DG' . $order->id;
        $payoutRecord = $this->createPayoutRecord($order->id, $transactionNo);

        $result = $this->runCallback($transactionNo, '20');

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertIsArray($result->response);
        $this->assertSame(0, $result->response['code']);

        $order->refresh();
        $this->assertSame(24, $order->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
        $this->assertSame('20', (string) $payoutRecord->result_code);
    }

    /**
     * 失敗回調（status=-10）→ 訂單 29
     */
    public function test_failure_callback_updates_order_to_29(): void
    {
        $order = $this->makeWithdrawalOrder();
        $transactionNo = 'DG' . $order->id;
        $payoutRecord = $this->createPayoutRecord($order->id, $transactionNo);

        $result = $this->runCallback($transactionNo, '-10');

        $this->assertSame(0, $result->response['code']);

        $order->refresh();
        $this->assertSame(29, $order->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
    }

    /**
     * 中間態（status=10 等）→ 維持 PAYMENT_PROCESSING(22)，回成功 ack
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('intermediateStatusProvider')]
    public function test_intermediate_status_keeps_22(string $status): void
    {
        $order = $this->makeWithdrawalOrder();
        $transactionNo = 'DG_INDET_' . $status . '_' . $order->id;
        $this->createPayoutRecord($order->id, $transactionNo);

        $result = $this->runCallback($transactionNo, $status);

        $this->assertSame(0, $result->response['code']);

        $order->refresh();
        $this->assertSame(22, $order->status);
    }

    public static function intermediateStatusProvider(): array
    {
        return [
            'status=0 Pending' => ['0'],
            'status=1 Submit' => ['1'],
            'status=2 Queue' => ['2'],
            'status=5 Pending Verification' => ['5'],
            'status=10 Processing' => ['10'],
        ];
    }

    /**
     * 重複回調（訂單已 24）→ 冪等返回成功，不重複處理
     */
    public function test_idempotent_when_order_already_succeeded(): void
    {
        $order = $this->makeWithdrawalOrder(status: 24);
        $transactionNo = 'DG' . $order->id;
        $this->createPayoutRecord($order->id, $transactionNo, ['is_success_payout' => 1]);

        $result = $this->runCallback($transactionNo, '20');

        $this->assertSame(0, $result->response['code']);

        $order->refresh();
        $this->assertSame(24, $order->status);
    }

    /**
     * 代付紀錄不存在 → 失敗響應（code -100）
     */
    public function test_payout_record_not_found(): void
    {
        $result = $this->runCallback('NONEXISTENT', '20');

        $this->assertIsArray($result->response);
        $this->assertSame(-100, $result->response['code']);
    }

    /**
     * 成功但金額不符 → 仍標 24（金額不符僅告警，不阻斷流程）
     */
    public function test_amount_mismatch_still_succeeds(): void
    {
        $order = $this->makeWithdrawalOrder();
        $transactionNo = 'DG' . $order->id;
        $this->createPayoutRecord($order->id, $transactionNo);

        $result = $this->runCallback($transactionNo, '20', amount: '50');

        $this->assertSame(0, $result->response['code']);

        $order->refresh();
        $this->assertSame(24, $order->status);
    }

    /**
     * IDR 回調 orderId 為 {transaction_no}@{opcode}，切 @ 後仍命中 payout record
     */
    public function test_idr_orderid_with_opcode_suffix_matches_record(): void
    {
        $order = $this->makeWithdrawalOrder();
        $transactionNo = 'DG' . $order->id;
        $payoutRecord = $this->createPayoutRecord($order->id, $transactionNo);

        // 回調帶 @opcode；runCallback 會以此原始 orderId 自動簽名
        $callbackOrderId = $transactionNo . '@dgpay_test';
        $result = $this->runCallback($callbackOrderId, '20');

        $this->assertSame(0, $result->response['code']);

        $order->refresh();
        $this->assertSame(24, $order->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
    }

    /**
     * 缺少必要欄位 → ValidationException
     */
    public function test_missing_required_fields(): void
    {
        $request = Request::create('/callback', 'POST', ['orderId' => 'DG1']);

        $this->expectException(ValidationException::class);

        (new WithdrawalCallback($this->agentCashFlow))->handle($request);
    }

    /**
     * 空憑證守衛：MerchantKey 為空時 secretKey 段為空、簽名可離線計算，
     * 攻擊者能自算出「有效」securityToken 偽造成功回調把在途訂單洗成 24。
     * 守衛須在 verifyCallbackSignature() 之前先擋下：回 code -100，
     * 且訂單維持 22（偽造的成功回調未能結算）。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->makeWithdrawalOrder();
        $transactionNo = 'DG' . $order->id;
        $this->createPayoutRecord($order->id, $transactionNo);

        // 線上憑證未配置的常見形態：MerchantKey 為空字串
        $emptyKeyInfo = [
            'APIUrl' => 'https://dgpayapi.test',
            'Merchant' => 'dgpay_test',
            'MerchantKey' => '',
            'Currency' => 'MYR',
            'PROVIDER_ID' => '5',
            'PROVIDER_TYPE' => '20',
            'available_banks' => [],
        ];
        $emptyKeyAcf = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'admin' => 0,
            'info' => json_encode($emptyKeyInfo),
        ]);
        $emptyKeyAcf->info = $emptyKeyInfo;

        // 攻擊者以「空 secretKey」自算 securityToken：md5(opCode + orderId + amount + status + '')
        $forgedToken = [REDACTED]'dgpay_test' . $transactionNo . '100' . '20' . ''));
        $request = Request::create('/callback', 'POST', [
            'orderId' => $transactionNo,
            'status' => '20',
            'amount' => '100',
            'securityToken' => $forgedToken,
        ]);

        $result = (new WithdrawalCallback($emptyKeyAcf))->handle($request);

        // 守衛硬拒
        $this->assertSame(-100, $result->response['code']);

        // 訂單維持 22：偽造的成功回調沒能把它結算成 24
        $order->refresh();
        $this->assertSame(22, $order->status);
    }
}
