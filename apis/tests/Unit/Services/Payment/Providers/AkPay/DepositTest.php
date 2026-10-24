<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\AkPay;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Providers\AkPay\Deposit;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AkPay Deposit 單元測試
 *
 * 測試 AkPay 代收發送請求的邏輯，包含成功、失敗、bank_code 傳遞等情境
 *
 * php artisan test tests/Unit/Services/Payment/Providers/AkPay/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private OrderType $orderType;

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

        $info = [
            'API_URL' => 'https://api.akpay.test',
            'MERCHANT_TOKEN' => 'test_token',
            'MERCHANT_CODE' => 'AKTEST',
            'CURRENCY' => 'MYR',
        ];

        $this->agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 150,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);

        $this->orderType = OrderType::create([
            'cash_flow_id' => 150,
            'currency_id' => 1,
            'name' => 'AkPay Deposit MYR',
            'type' => 1,
            'status' => 1,
            'key' => 'FPX',
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    private function createOrder(string $orderNo, ?OrderType $orderType = null): Order
    {
        return Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => ($orderType ?? $this->orderType)->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => $orderNo,
            'status' => 0,
            'balance' => 0,
            'amount' => 100.00,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);
    }

    private function createRequestData(Order $order): DepositRequestData
    {
        return DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.test/callback',
            'returnUrl' => 'https://example.test/return',
            'ip' => '0.0.0.0',
        ]);
    }

    /**
     * 測試代收成功：API 返回 success=true，取得 URL 並記錄三方訂單號
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/DepositTest.php --filter test_deposit_success_returns_response_data_with_url
     */
    public function test_deposit_success_returns_response_data_with_url(): void
    {
        $order = $this->createOrder('AKPAY_DEP_001');

        Http::fake([
            '*/api/order/fundin' => Http::response([
                'success' => true,
                'message' => 'OK',
                'data' => [
                    'tx_id' => 'TX_AKPAY_001',
                    'url' => 'https://pay.akpay.test/checkout/TX_AKPAY_001',
                ],
            ], 200),
        ]);

        $result = (new Deposit($this->agentCashFlow))->handle($this->createRequestData($order));

        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertSame('https://pay.akpay.test/checkout/TX_AKPAY_001', $result->url);
        $this->assertSame('AKPAY_DEP_001', $result->orderNo);
        $this->assertSame('TX_AKPAY_001', $result->providerOrderNo);

        $order->refresh();
        $this->assertSame('TX_AKPAY_001', $order->third_party_order_no);
    }

    /**
     * 測試代收 API 返回 success=false，應拋出 ApiException（取 message 欄位）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/DepositTest.php --filter test_deposit_api_failure_throws_exception
     */
    public function test_deposit_api_failure_throws_exception(): void
    {
        $order = $this->createOrder('AKPAY_DEP_002');

        Http::fake([
            '*/api/order/fundin' => Http::response([
                'success' => false,
                'message' => 'Invalid merchant',
                'data' => [],
            ], 200),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('AkPay deposit failed: Invalid merchant');

        (new Deposit($this->agentCashFlow))->handle($this->createRequestData($order));
    }

    /**
     * 測試 API 返回 data 層含欄位錯誤時，應取第一筆欄位錯誤訊息
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/DepositTest.php --filter test_deposit_api_failure_uses_first_field_error_message
     */
    public function test_deposit_api_failure_uses_first_field_error_message(): void
    {
        $order = $this->createOrder('AKPAY_DEP_003');

        Http::fake([
            '*/api/order/fundin' => Http::response([
                'success' => false,
                'message' => 'Validation error',
                'data' => ['amount' => ['Amount must be greater than 0']],
            ], 200),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('AkPay deposit failed: Amount must be greater than 0');

        (new Deposit($this->agentCashFlow))->handle($this->createRequestData($order));
    }

    /**
     * 測試無 parent OrderType 時，HTTP 請求不包含 bank_code
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/DepositTest.php --filter test_deposit_without_parent_order_type_does_not_send_bank_code
     */
    public function test_deposit_without_parent_order_type_does_not_send_bank_code(): void
    {
        $order = $this->createOrder('AKPAY_DEP_004');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertArrayNotHasKey('bank_code', $data);
            $this->assertSame('FPX', $data['payment_method'] ?? null);

            return Http::response([
                'success' => true,
                'data' => [
                    'tx_id' => 'TX_AKPAY_004',
                    'url' => 'https://pay.akpay.test/checkout/TX_AKPAY_004',
                ],
            ], 200);
        });

        (new Deposit($this->agentCashFlow))->handle($this->createRequestData($order));
    }

    /**
     * 測試有 parent OrderType 時，HTTP 請求包含正確 bank_code（子層 key）及 payment_method（父層 key）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/DepositTest.php --filter test_deposit_with_parent_order_type_sends_bank_code
     */
    public function test_deposit_with_parent_order_type_sends_bank_code(): void
    {
        // 父層 OrderType（payment_method）
        $parentOrderType = OrderType::create([
            'cash_flow_id' => 150,
            'currency_id' => 1,
            'name' => 'AkPay FPX',
            'type' => 1,
            'status' => 1,
            'key' => 'FPX',
            'info' => '{}',
            'admin' => 0,
        ]);

        // 子層 OrderType（bank_code），設定 parent 指向父層
        $childOrderType = OrderType::create([
            'cash_flow_id' => 150,
            'currency_id' => 1,
            'name' => 'AkPay FPX CIMB',
            'type' => 1,
            'status' => 1,
            'key' => 'CIMB',
            'parent' => $parentOrderType->id,
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = $this->createOrder('AKPAY_DEP_005', $childOrderType);

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('CIMB', $data['bank_code'] ?? null);
            $this->assertSame('FPX', $data['payment_method'] ?? null);

            return Http::response([
                'success' => true,
                'data' => [
                    'tx_id' => 'TX_AKPAY_005',
                    'url' => 'https://pay.akpay.test/checkout/TX_AKPAY_005',
                ],
            ], 200);
        });

        (new Deposit($this->agentCashFlow))->handle($this->createRequestData($order));
    }
}
