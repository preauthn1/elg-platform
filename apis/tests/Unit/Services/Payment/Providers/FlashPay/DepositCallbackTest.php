<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\FlashPay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\FlashPay\DepositCallback;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Services/Payment/Providers/FlashPay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private array $info;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'SGD',
            'name' => 'Singapore Dollar',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $this->info = [
            'API_URL' => 'https://example.invalid/v3',
            'CLIENT_ID' => '8e363c65-6dec-11f1-8a6d-0200009ee8a2',
            'SECRET_KEY' => '5c378d3e9e1fcacf83e4ad94df944a538102905a116af5ae6ed6f1bdff13ca97',
            'CURRENCY' => 'SGD',
            'CONT_CODE' => 'SGD_PAYNOW',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'info' => json_encode($this->info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $this->info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function createOrder(string $orderNo, int $amount = 100): Order
    {
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => 1],
            ['name' => 'FlashPay PayNow', 'status' => 1, 'key' => 'SGD_PAYNOW', 'info' => '{}', 'admin' => 0]
        );

        return Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'third_party_order_no' => '',
            'order_no' => $orderNo,
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => $amount,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);
    }

    private function buildCallbackData(string $orderNo, int $amount = 100, string $status = 'completed'): array
    {
        $params = [
            'status_code' => 0,
            'status_msg' => 'NO_ERROR',
            'tran_token' => '7c711a99-8b42-11ee-b53e-020000557396',
            'tran_reference' => 'REF123456',
            'tran_flag' => '20260629120000',
            'tran_customer' => 'testuser',
            'tran_amount' => $amount,
            'tran_unique' => $orderNo,
            'tran_remark' => '',
            'tran_fee' => 1,
            'tran_status' => $status,
            'tran_createtime' => '2026-06-29 12:00:00',
            'tran_lastupdated' => '2026-06-29 12:00:01',
        ];

        $filtered = array_filter($params, fn ($v) => $v !== null && $v !== '');
        ksort($filtered);
        $signStr = urldecode(http_build_query($filtered));
        $params['tran_signature'] = hash('sha256', $signStr . $this->info['SECRET_KEY']);

        return $params;
    }

    public function test_success(): void
    {
        $order = $this->createOrder('FP_CB_001');
        $callbackData = $this->buildCallbackData('FP_CB_001');

        $request = Request::create('/callback', 'POST', $callbackData);
        $callback = new DepositCallback($this->agentCashFlow);
        $result = $callback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('7c711a99-8b42-11ee-b53e-020000557396', $order->third_party_order_no);
    }

    public function test_non_completed_status_keeps_order_created(): void
    {
        $order = $this->createOrder('FP_CB_002');
        $callbackData = $this->buildCallbackData('FP_CB_002', 100, 'expired');

        $request = Request::create('/callback', 'POST', $callbackData);
        $callback = new DepositCallback($this->agentCashFlow);
        $result = $callback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        // 非 completed 狀態不更新訂單，維持 CREATED
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }

    public function test_invalid_signature(): void
    {
        $callbackData = $this->buildCallbackData('FP_CB_003');
        $callbackData['tran_signature'] = 'invalid_signature';

        $request = Request::create('/callback', 'POST', $callbackData);
        $callback = new DepositCallback($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $callback->handle($request);
    }

    public function test_missing_required_fields(): void
    {
        $request = Request::create('/callback', 'POST', [
            'tran_amount' => 100,
        ]);

        $callback = new DepositCallback($this->agentCashFlow);

        $this->expectException(ValidationException::class);
        $callback->handle($request);
    }

    public function test_amount_mismatch(): void
    {
        $order = $this->createOrder('FP_CB_005', 100);
        $callbackData = $this->buildCallbackData('FP_CB_005', 50);

        $request = Request::create('/callback', 'POST', $callbackData);
        $callback = new DepositCallback($this->agentCashFlow);
        $result = $callback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
    }

    /**
     * 空憑證守衛：SECRET_KEY 為空時 checkSign() 的 secret 段為空、簽名可離線計算，
     * 攻擊者能偽造成功回調直接上分。守衛須在驗簽前先擋下（拋 ApiException），且訂單維持 CREATED。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->createOrder('FP_EMPTY_KEY');

        $emptyInfo = $this->info;
        $emptyInfo['SECRET_KEY'] = '';
        $emptyAcf = new AgentCashFlow([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $emptyAcf->info = $emptyInfo;

        // 攻擊者以空 secret 自算簽名
        $params = [
            'status_code' => 0,
            'status_msg' => 'NO_ERROR',
            'tran_token' => '7c711a99-8b42-11ee-b53e-020000557396',
            'tran_reference' => 'REF123456',
            'tran_flag' => '20260629120000',
            'tran_customer' => 'testuser',
            'tran_amount' => 100,
            'tran_unique' => 'FP_EMPTY_KEY',
            'tran_fee' => 1,
            'tran_status' => 'completed',
            'tran_createtime' => '2026-06-29 12:00:00',
            'tran_lastupdated' => '2026-06-29 12:00:01',
        ];
        $filtered = array_filter($params, fn ($v) => $v !== null && $v !== '');
        ksort($filtered);
        $signStr = urldecode(http_build_query($filtered));
        $params['tran_signature'] = hash('sha256', $signStr . '');

        $request = Request::create('/callback', 'POST', $params);

        try {
            (new DepositCallback($emptyAcf))->handle($request);
            $this->fail('空憑證回調應被守衛拒絕');
        } catch (ApiException $e) {
        }

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }
}
