<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\ZPay;

use App\Enums\OrderStatusEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\ZPay\DepositCallback;
use App\Services\Payment\Providers\ZPay\ZPayStatusCodeEnum;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ZPay 代收回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/ZPay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
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
            'TOKEN' => 'test_token',
            'SECRET_KEY' => 'test_secret_key',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 110,
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
            'cash_flow_id' => 110,
            'currency_id' => 1,
            'name' => 'ZPay Deposit MYR',
            'type' => 1,
            'status' => 1,
            'key' => 'ZPAY',
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    /**
     * 測試成功狀態回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/ZPay/DepositCallbackTest.php --filter test_success_status
     */
    public function test_success_status(): void
    {
        $order = $this->createOrder('ZPAY123456789', '100.00');

        $request = $this->createRequest([
            'referenceId' => $order->order_no,
            'status' => ZPayStatusCodeEnum::ON->value,
            'transactionId' => 10001,
            'amount' => '100.00',
        ]);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        $result = $depositCallback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);
        $this->assertNotNull($result->order);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('10001', $order->third_party_order_no);
    }

    /**
     * 測試失敗狀態回調
     */
    #[DataProvider('failedStatusProvider')]
    public function test_failed_status(int $status): void
    {
        $order = $this->createOrder('ZPAY123456790', '100.00');

        $request = $this->createRequest([
            'referenceId' => $order->order_no,
            'status' => $status,
            'transactionId' => 10002,
            'amount' => '100.00',
        ]);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        $result = $depositCallback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);
        $this->assertNotNull($result->order);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);
        $this->assertEquals('10002', $order->third_party_order_no);
    }

    /**
     * 測試處理中/待處理狀態回調
     */
    #[DataProvider('pendingStatusProvider')]
    public function test_pending_status(int $status): void
    {
        $order = $this->createOrder('ZPAY123456791', '100.00');

        $request = $this->createRequest([
            'referenceId' => $order->order_no,
            'status' => $status,
            'transactionId' => 10003,
            'amount' => '100.00',
        ]);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        $result = $depositCallback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(['status' => 'OK', 'message' => 'Success'], $result->response);
        $this->assertNotNull($result->order);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
        $this->assertEquals('', $order->third_party_order_no);
    }

    public static function failedStatusProvider(): array
    {
        return [
            'VOIDED' => [ZPayStatusCodeEnum::VOIDED->value],
            'FAILED' => [ZPayStatusCodeEnum::FAILED->value],
            'REJECTED' => [ZPayStatusCodeEnum::REJECTED->value],
            'FORFEIT' => [ZPayStatusCodeEnum::FORFEIT->value],
            'REFUNDED' => [ZPayStatusCodeEnum::REFUNDED->value],
        ];
    }

    public static function pendingStatusProvider(): array
    {
        return [
            'PROCESSING' => [ZPayStatusCodeEnum::PROCESSING->value],
            'PENDING' => [ZPayStatusCodeEnum::PENDING->value],
        ];
    }

    /**
     * 建立代收訂單
     */
    private function createOrder(string $orderNo, string $amount): Order
    {
        $storedItemDesc = $this->generateStoredItemDesc($orderNo, $amount, 'test_secret_key');

        return Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $this->orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => $orderNo,
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => $amount,
            'point' => 0,
            'extra_content' => Common::safeJsonEncode([
                'zpay_item_desc' => $storedItemDesc,
            ]),
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);
    }

    /**
     * 建立回調 Request
     */
    private function createRequest(array $overrides = []): Request
    {
        $data = array_merge([
            'referenceId' => 'ZPAY000000000',
            'status' => ZPayStatusCodeEnum::ON->value,
            'transactionId' => 99999,
            'amount' => '100.00',
            'currency' => 'MYR',
            'sourceBank' => 'PBE',
            'sourceAccountNo' => '1234567890',
            'sourceAccountName' => 'Test Account',
            'transactionDate' => '20250205010101',
        ], $overrides);

        $storedItemDesc = $this->generateStoredItemDesc(
            $data['referenceId'],
            (string) $data['amount'],
            'test_secret_key'
        );
        $data['itemDesc'] = $this->generateCallbackItemDesc($storedItemDesc, 'test_secret_key');

        return Request::create('/callback', 'POST', $data);
    }

    private function generateStoredItemDesc(string $orderNo, string $amount, string $secret): string
    {
        return md5($orderNo . $amount . $secret);
    }

    private function generateCallbackItemDesc(string $storedItemDesc, string $secret): string
    {
        return md5($storedItemDesc . $secret);
    }
}
