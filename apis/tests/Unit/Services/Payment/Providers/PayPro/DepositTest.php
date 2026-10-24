<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayPro;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Providers\PayPro\Deposit;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PayPro 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'IDR',
            'name' => 'Indonesian Rupiah',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'API_URL' => 'https://sandbox-api.paypro.tech/indonesia-pay',
            'MERCHANT_NO' => '0000000001',
            'MERCHANT_KEY' => 'test_merchant_key',
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

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * 測試成功建立代收請求
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositTest.php --filter test_success_fiat
     */
    public function test_success_fiat()
    {
        // Arrange
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => 1],
            [
                'name' => 'PayPro QRIS',
                'status' => 1,
                'key' => 'QRIS',
                'info' => '{}',
                'admin' => 0,
            ]
        );

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $this->currency->id,
            'amount' => 100000,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'TRN' . time(),
            'status' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);

        Http::fake([
            'https://sandbox-api.paypro.tech/indonesia-pay/*' => Http::response([
                'code' => '0000',
                'msg' => 'Success',
                'data' => [
                    'status' => '0015',
                    'platFormTradeNo' => 'PAYPRO12345',
                    'paymentUrl' => 'https://example.invalid/checkout?order=12345',
                ],
            ], 200),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert
        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertNotEmpty($result->url);
        $this->assertEquals($order->order_no, $result->orderNo);
        $this->assertEquals('PAYPRO12345', $result->providerOrderNo);
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);

        // 驗證訂單已更新
        $order->refresh();
        $this->assertEquals('PAYPRO12345', $order->third_party_order_no);
    }

    /**
     * 測試 API 返回錯誤碼
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositTest.php --filter test_api_error_response
     */
    public function test_api_error_response()
    {
        // Arrange
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => 1],
            [
                'name' => 'PayPro QRIS',
                'status' => 1,
                'key' => 'QRIS',
                'info' => '{}',
                'admin' => 0,
            ]
        );

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $this->currency->id,
            'amount' => 100000,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'TRN' . time(),
            'status' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);

        Http::fake([
            'https://sandbox-api.paypro.tech/indonesia-pay/*' => Http::response([
                'code' => '0001',
                'msg' => 'Invalid merchant',
                'data' => [
                    'desc' => 'Merchant not found',
                ],
            ], 200),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        // Act & Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('PayPro deposit error');
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試缺少必要響應數據
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositTest.php --filter test_missing_response_data
     */
    public function test_missing_response_data()
    {
        // Arrange
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => 1],
            [
                'name' => 'PayPro QRIS',
                'status' => 1,
                'key' => 'QRIS',
                'info' => '{}',
                'admin' => 0,
            ]
        );

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $this->currency->id,
            'amount' => 100000,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'TRN' . time(),
            'status' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);

        Http::fake([
            'https://sandbox-api.paypro.tech/indonesia-pay/*' => Http::response([
                'code' => '0000',
                'msg' => 'Success',
                'data' => [], // 缺少必要欄位
            ], 200),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        // Act & Assert
        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('Undefined array key "platFormTradeNo"');
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試 HTTP 連線錯誤
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositTest.php --filter test_http_connection_error
     */
    public function test_http_connection_error()
    {
        // Arrange
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => 1],
            [
                'name' => 'PayPro QRIS',
                'status' => 1,
                'key' => 'QRIS',
                'info' => '{}',
                'admin' => 0,
            ]
        );

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $this->currency->id,
            'amount' => 100000,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'TRN' . time(),
            'status' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);

        Http::fake([
            'https://sandbox-api.paypro.tech/indonesia-pay/*' => Http::response([], 500),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        // Act & Assert
        $this->expectException(\Exception::class);
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試金額向下取整
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositTest.php --filter test_amount_floor
     */
    public function test_amount_floor()
    {
        // Arrange
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => 1],
            [
                'name' => 'PayPro QRIS',
                'status' => 1,
                'key' => 'QRIS',
                'info' => '{}',
                'admin' => 0,
            ]
        );

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $this->currency->id,
            'amount' => 100000.99, // 有小數點的金額
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'TRN' . time(),
            'status' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);

        Http::fake([
            'https://sandbox-api.paypro.tech/indonesia-pay/*' => function ($request) {
                $body = $request->data();
                // 驗證金額已向下取整
                $this->assertEquals(100000.0, $body['amount']);

                return Http::response([
                    'code' => '0000',
                    'msg' => 'Success',
                    'data' => [
                        'status' => '0015',
                        'platFormTradeNo' => 'PAYPRO12345',
                        'paymentUrl' => 'https://example.invalid/checkout?order=12345',
                    ],
                ], 200);
            },
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert
        $this->assertInstanceOf(DepositResponseData::class, $result);
    }
}
