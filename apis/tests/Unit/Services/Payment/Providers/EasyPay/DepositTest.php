<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\EasyPay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderRequestLog;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Providers\EasyPay\Deposit;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * EasyPay 代收建單的三方往返紀錄（工單 target/tickets#4449）。
 *
 * 建單的往返沒有別的地方存：`orders.extra_content` 會原封不動回給會員 App、`remark` 由共用 trait
 * 統一寫，所以成功與失敗都只能靠 order_request_logs 留證。代付不同——`withdrawal_payout_records`
 * 本來就有 payout_order_request_body／payout_order_response，因此刻意不重複記。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/EasyPay/DepositTest.php
 */
class DepositTest extends TestCase
{
    private AgentCashFlow $agentCashFlow;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $agent = $this->createAgentAndDomain();

        $info = [
            'API_URL' => 'https://api.easypay.test',
            'MERCHANT_NAME' => 'easy_merchant',
            'MERCHANT_PASSWORD' => 'easy_password',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'currency_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'FPS',
            'type' => 1,
            'status' => 1,
            'key' => 'bank_order',
            'info' => '{}',
            'admin' => 0,
        ]);

        $member = Member::factory()->create(['parent' => $agent->id, 'admin' => 0]);

        $this->order = Order::factory()->create([
            'order_no' => 'EP_CREATE_001',
            'order_type_id' => $orderType->id,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'amount' => 100,
            'status' => OrderStatusEnum::CREATED->value,
            'admin' => 0,
        ]);
        $this->order->setRelation('orderType', $orderType);
    }

    private function createDeposit(): Deposit
    {
        return new Deposit($this->agentCashFlow);
    }

    private function requestData(): DepositRequestData
    {
        return DepositRequestData::from([
            'order' => $this->order,
            'member' => $this->order->member,
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);
    }

    #[TestDox('建單成功時寫入往返紀錄，含三方訂單號')]
    public function test_successful_creation_is_recorded(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 1,
                'data' => [
                    'order_id' => 'EP_TP_999',
                    'payment_details' => ['redirect_url' => 'https%3A%2F%2Fpay.test%2Fgo'],
                ],
            ]),
        ]);

        $this->createDeposit()->handle($this->requestData());

        $log = OrderRequestLog::where('order_no', 'EP_CREATE_001')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('deposit_create', $log->action);
        $this->assertSame('ok', $log->result);
        $this->assertSame('EP_TP_999', $log->third_party_order_no);
        $this->assertSame($this->order->id, $log->orderable_id);
        // 代收提單的成功路徑過去不帶 HTTP 狀態（只有失敗那幾條有）
        $this->assertSame(200, $log->http_status);
    }

    #[TestDox('三方回非成功狀態時寫入往返紀錄，result 帶三方的錯誤碼')]
    public function test_provider_rejection_is_recorded(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 0, 'code' => 'INSUFFICIENT_BALANCE']),
        ]);

        try {
            $this->createDeposit()->handle($this->requestData());
            $this->fail('三方拒絕時應拋出 ApiException');
        } catch (ApiException) {
            // 預期行為
        }

        $log = OrderRequestLog::where('order_no', 'EP_CREATE_001')->latest('id')->first();
        $this->assertNotNull($log, '三方拒絕也要留下紀錄，否則這次失敗在 DB 完全沒痕跡');
        $this->assertSame('failed:INSUFFICIENT_BALANCE', $log->result);
    }

    #[TestDox('三方以 HTTP 400 帶業務錯誤碼時，result 記三方的碼而不是 exception')]
    public function test_business_error_carried_by_http_400_is_recorded_with_provider_code(): void
    {
        // 本家用非 2xx 承載業務錯誤（Dev 實測：維護中、商戶餘額不足都是 400）。
        // 記成 exception 的話，「三方拒絕」與「連線斷掉」在這張表上分不出來。
        Http::fake([
            '*' => Http::response(['status' => 0, 'code' => 'SERVICE_UNDER_MAINTENANCE'], 400),
        ]);

        try {
            $this->createDeposit()->handle($this->requestData());
            $this->fail('三方回 400 時應往外拋');
        } catch (\Throwable) {
            // 預期行為
        }

        $log = OrderRequestLog::where('order_no', 'EP_CREATE_001')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('failed:SERVICE_UNDER_MAINTENANCE', $log->result);
        $this->assertSame(400, $log->http_status);
    }

    #[TestDox('連不上三方時寫入往返紀錄，result 為 exception')]
    public function test_connection_failure_is_recorded(): void
    {
        // 503 的 body 不是 JSON，取不到錯誤碼 → 退回 exception，這是 providerFailureOf() 的退路
        Http::fake([
            '*' => Http::response('Service Unavailable', 503),
        ]);

        try {
            $this->createDeposit()->handle($this->requestData());
            $this->fail('三方不可用時應往外拋');
        } catch (\Throwable) {
            // 預期行為
        }

        $log = OrderRequestLog::where('order_no', 'EP_CREATE_001')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('failed:exception', $log->result);
        $this->assertSame(503, $log->http_status);
    }
}
