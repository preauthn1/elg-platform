<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TopPay;

use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Providers\TopPay\Deposit;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TopPay 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TopPay/DepositTest.php
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
            'API_URL' => 'https://example.invalid',
            'MERCHANT_CODE' => 'test_merchant',
            'MERCHANT_PRIVATE_KEY' => 'MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDJq4sgb3F/WsmbuDKZw7xyJWwke9l/RFmPed+pgDLxnhdSHJ2b1zUgMCxqA/Pxs+owD2UvQheVCg1XSHXkBciaqv+w9daJZgf3BerqE3UDUIj4iFITbkREPM70zMKZMFEr4NI1p2VZk2HzXPoWSdU9EdIL5gC99Sifw3q9H9VflvDl0FXdht/QYJnKfW+9cRVLMLnZFDDRtgrhteMmpEv61W42fFagUpViLJfkVo4K7biqv34zmNtwV/njxy+HV36fFZH+ElpahYP+Q9fXn1PdO0QZLICij8xfYdv4VBnMjQIKlf7HwqUlU4/SFfpYp+imjts9r2oKGceXvX6t6+6nAgMBAAECggEALgSVXiTf7rIeQAjDjx/BgAB3EUorgqVrlFg4h7qHpWewJZ3+/uEYL8yeTeCyCQzHWwkgSyzXKbyd97VCpXIGaPitGMm4FykvlxgEFz6yXDvDzYZ5yBJIxJNlnCmfHAqQ8yS9gt28PDjryHcGYBCf9mv7FJj6wV3QU8hs2PwLGeD049qPJBBhJYOusm6U+aX6ZnDcjAYK4S1tZdB/nSGOsfc3zvsRs6Iwp3jP27lVCJLkyM6opzhOqDLP7G7tS0oz9cLY++HY4Xn0qKK30a34Nm5WK9qb0Tq9RyEbDqYMDfzsBlvdSxKzCRtsib28TAPu3zD6Iiu0ydqtGtxndE5jXQKBgQD4RDv67A4egE3aVPAZI5mBKSe/T6sZ4zuEckxNbV2yxnWza9yXkhLerSS4GtCIbzC+h5MHYsKu/Y0lBaElxMhbM/NfxwlF8YMNRU2juKl/OfoF+dZ7oOp4w6W1ZpySZiA27YpcjPxL8MJ5pzVzS+GsBlwkIlgAf3vanl36X0mGLQKBgQDP87uFzlxyKIGsOXg5XfQY7ovifuO8xdETUSyZ4Z8SAGUTE8YbH+IEynSLwFyf7x/FW3pLY1IzLznKyg3NiHec9guyuGdQJgcBpZpLbf3rxnMooqOvBcMJKW10Cym5O1IdJkY1/5QuOU8tcimqg+yk8cDwhSDoSVgkdqE0OzmAowKBgDEgD15PZ1vRt5YESJ4w1SchpaPNd+XfeN90XnTTtz6w1olyRmwXk3RMfn+PjsPwfIy29vj3Kd7GfS9eNczra1ngpb/F+qfEalIt7HcBC0kJh9e24CIGbV8IF7oVW9X8L1OvHTnDDAla0qCP3Zu64CTR/VmJEjDHCRLxU1c/k6ThAoGACD8rNaA8bE6M7e6NhQDj2EVFiOLtVyQlKXXzpdqbu1QGrh3/oj2+ceAxhQVI5xLNmL8K94hlzx/VU+R1baw2yiO7zQehGT2Y04jkuI8RHdON55OjmCJLXMamz45NxknC9OL6r9OF5dth93RqkJeQv+3Sb/dnma6m7zNBK1gZ7K8CgYBausfxv0LkKHs9wZJ3OoDHAM8Eibs55kVq4bDBeWIQXuRPJgglzUYSZ55JWH/bG22PFlrk4VWtPPS7ti3rrkyYqEGxdlAKydx/g2j0K4yal/zDJHE9ibVDmcvtItvTW4Ju47qvkqbIkZ6DeQo4GggoCET01Y+wO1VVPl/2uDfmbg==',
            'PLATFORM_PUBLIC_KEY' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAyauLIG9xf1rJm7gymcO8ciVsJHvZf0RZj3nfqYAy8Z4XUhydm9c1IDAsagPz8bPqMA9lL0IXlQoNV0h15AXImqr/sPXWiWYH9wXq6hN1A1CI+IhSE25ERDzO9MzCmTBRK+DSNadlWZNh81z6FknVPRHSC+YAvfUon8N6vR/VX5bw5dBV3Ybf0GCZyn1vvXEVSzC52RQw0bYK4bXjJqRL+tVuNnxWoFKVYiyX5FaOCu24qr9+M5jbcFf548cvh1d+nxWR/hJaWoWD/kPX159T3TtEGSyAoo/MX2Hb+FQZzI0CCpX+x8KlJVOP0hX6WKfopo7bPa9qChnHl71+revupwIDAQAB',
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
     * php artisan test tests/Unit/Services/Payment/Providers/TopPay/DepositTest.php --filter test_success_fiat
     */
    public function test_success_fiat()
    {
        // Arrange
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => 1],
            [
                'name' => 'TopPay BCA',
                'status' => 1,
                'key' => 'BCA',
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
            '*' => Http::response([
                'success' => true,
                'code' => '9999',
                'msg' => null,
                'timeStamp' => now()->getTimestampMs(),
                'data' => [
                    'orderNum' => 'TRN' . time(),
                    'platOrderNum' => 'PRE2009093829059153920',
                    'amount' => 100000,
                    'fee' => 2000.0,
                    'method' => 'DANA',
                    'cashierUrl' => 'https://example.invalid/checkout?order=12345',
                    'payData' => 'https://example.invalid/checkout?order=12345',
                    'payDataType' => 'CASHIER_URL',
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

        // 驗證送往 TopPay 的 amount 為整數印尼盾且與訂單金額相等
        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();

            return $body['amount'] === (int) $order->amount
                && is_int($body['amount']);
        });
    }

    /**
     * 測試 API 返回錯誤
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TopPay/DepositTest.php --filter test_api_error_response
     */
    public function test_api_error_response()
    {
        // Arrange
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => 1],
            [
                'name' => 'TopPay BCA',
                'status' => 1,
                'key' => 'BCA',
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
            '*' => Http::response([
                'success' => false,
                'code' => '0001',
                'msg' => 'API Error',
                'data' => null,
            ], 200),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        // Act & Assert
        $this->expectException(\Exception::class);
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試缺少必要欄位
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TopPay/DepositTest.php --filter test_missing_response_data
     */
    public function test_missing_response_data()
    {
        // Arrange
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => 1],
            [
                'name' => 'TopPay BCA',
                'status' => 1,
                'key' => 'BCA',
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
            '*' => Http::response([
                'success' => true,
                'code' => '9999',
                'msg' => null,
                'timeStamp' => now()->getTimestampMs(),
                'data' => [],  // cashierUrl 缺失
            ], 200),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        // Act & Assert
        $this->expectException(\Exception::class);
        $deposit->handle($depositRequestData);
    }
}
