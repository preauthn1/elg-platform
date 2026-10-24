<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\DgPay;

use App\Models\Agent;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\DgPay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * DgPay 代收回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private OrderType $orderType;

    private array $info = [
        'APIUrl' => 'https://dgpayapi.test',
        'Merchant' => 'dgpay_test',
        'MerchantKey' => 'test-merchant-key-abc',
        'Currency' => 'MYR',
        'PROVIDER_ID' => '5',
        'PROVIDER_TYPE' => '10',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);

        $this->orderType = OrderType::create([
            'cash_flow_id' => 99,
            'currency_id' => 1,
            'name' => 'DgPay Callback Test',
            'type' => 1,
            'status' => 1,
            'key' => 'CIMB',
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    // ─────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────

    private function makeAgentCashFlow(): \App\Models\AgentCashFlow
    {
        $cashFlow = new \App\Models\AgentCashFlow([
            'cash_flow_id' => 99,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($this->info),
            'status' => 1,
            'admin' => 0,
        ]);
        $cashFlow->info = $this->info;

        return $cashFlow;
    }

    private function makeOrder(int $status = 0): Order
    {
        return Order::create([
            // 回調檢查比對訂單 ACF 的 cash_flow_id，訂單須掛在同一家金流商的 ACF 下
            'agent_cash_flow_id' => $this->createAgentCashFlow($this->agent, ['cash_flow_id' => 99])->id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $this->orderType->id,
            'currency_id' => 1,
            'amount' => 100,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'DCTEST' . uniqid(),
            'status' => $status,
            'admin' => 0,
            'third_party_order_no' => '',
            'rate' => 1,
        ]);
    }

    private function callCallback(string $orderId, string $status, string $amount, ?string $token = [REDACTED] DepositCallbackResponseData
    {
        $token ??= strtolower(md5('dgpay_test' . $orderId . $amount . $status . 'test-merchant-key-abc'));

        $request = Request::create('/callback', 'POST', [
            'orderId' => $orderId,
            'status' => $status,
            'amount' => $amount,
            'securityToken' => $token,
        ]);

        return (new DepositCallback($this->makeAgentCashFlow()))->handle($request);
    }

    // ─────────────────────────────────────────
    // Tests
    // ─────────────────────────────────────────

    /**
     * 有效簽名 + 成功狀態：應正常處理並回傳成功響應
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositCallbackTest.php --filter test_valid_signature_processes_success_callback
     */
    public function test_valid_signature_processes_success_callback(): void
    {
        $order = $this->makeOrder();
        $callbackOrderId = $order->order_no . '@dgpay_test';

        $result = $this->callCallback($callbackOrderId, '20', '100');

        $this->assertSame('0', $result->response['code']);
    }

    /**
     * 無效簽名：應硬拒並回傳 -100，訂單狀態不變
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositCallbackTest.php --filter test_invalid_signature_is_rejected
     */
    public function test_invalid_signature_is_rejected(): void
    {
        $order = $this->makeOrder();
        $callbackOrderId = $order->order_no . '@dgpay_test';

        $result = $this->callCallback($callbackOrderId, '20', '100', token: [REDACTED]');

        $this->assertSame('-100', $result->response['code']);

        $order->refresh();
        $this->assertSame(0, $order->status);
    }

    /**
     * 新組（DGPAY）binding 用 SCREAMING_SNAKE key（MERCHANT / MERCHANT_KEY）時，
     * 驗簽（resolveSecretKey）與 parseOrderId 的 opCode 比對（resolveOpCode）雙鍵相容仍成功。
     * 防止回調鏈直讀 $this->info['Merchant'] 未相容新命名 → 新組回調全被硬拒的 Critical 回歸。
     */
    public function test_screaming_snake_merchant_keys_verify_and_parse(): void
    {
        $order = $this->makeOrder();
        $callbackOrderId = $order->order_no . '@dgpay_test';

        $snakeInfo = [
            'API_URL' => 'https://dgpayapi.test',
            'MERCHANT' => 'dgpay_test',
            'MERCHANT_KEY' => 'test-merchant-key-abc',
            'CURRENCY' => 'MYR',
            'PROVIDER_ID' => '5',
            'PROVIDER_TYPE' => '20',
        ];
        $snakeAcf = new \App\Models\AgentCashFlow([
            'cash_flow_id' => 99,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($snakeInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $snakeAcf->info = $snakeInfo;

        $token = [REDACTED]'dgpay_test' . $callbackOrderId . '100' . '20' . 'test-merchant-key-abc'));
        $request = Request::create('/callback', 'POST', [
            'orderId' => $callbackOrderId,
            'status' => '20',
            'amount' => '100',
            'securityToken' => $token,
        ]);

        $result = (new DepositCallback($snakeAcf))->handle($request);

        $this->assertSame('0', $result->response['code']);
    }

    /**
     * 空憑證守衛：MERCHANT／MERCHANT_KEY 皆為空時（建 acf 後尚未填憑證的窗口），
     * verifyCallbackSignature() 的 opCode 與 secretKey 段都是空字串，
     * 攻擊者只要知道訂單號就能離線算出「合法」securityToken。
     * 守衛須在驗簽前擋下，訂單不得被結算。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositCallbackTest.php --filter test_empty_credentials_reject_forged_callback
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->makeOrder();

        $this->info = [
            'API_URL' => 'https://dgpayapi.test',
            'MERCHANT' => '',
            'MERCHANT_KEY' => '',
            'CURRENCY' => 'MYR',
            'PROVIDER_ID' => '5',
            'PROVIDER_TYPE' => '10',
        ];

        // opCode 為空，回調 orderId 的 @ 後綴亦為空
        $callbackOrderId = $order->order_no . '@';

        // 攻擊者以空 opCode 與空 secretKey 離線算出的簽名
        $forgedToken = [REDACTED]'' . $callbackOrderId . '100' . '20' . ''));

        $result = $this->callCallback($callbackOrderId, '20', '100', token: [REDACTED];

        $this->assertSame('-100', $result->response['code']);
        $this->assertSame('credentials not configured', $result->errorMessage);

        $order->refresh();
        $this->assertSame(0, $order->status);
        $this->assertSame('', $order->third_party_order_no);
    }
}
