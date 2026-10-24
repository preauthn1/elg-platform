<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayMate;

use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\QueryOrderRequestData;
use App\Services\Payment\Enums\ProviderOrderStatusEnum;
use App\Services\Payment\Providers\PayMate\QueryOrder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PayMate 主動查單測試（api.md 3.3 /query）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayMate/QueryOrderTest.php
 */
class QueryOrderTest extends TestCase
{
    use PayMateSignatureHelper;

    protected Agent $agent;

    protected Member $member;

    protected AgentCashFlow $agentCashFlow;

    private const MERCHANT_NO = 'PM134126';

    private const MD5_KEY = 'c3f839790505a40973613d51bb8e2b8e';

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

        $info = [
            'API_URL' => 'https://api.paymate.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'MD5_KEY' => self::MD5_KEY,
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => 1,
            'cash_flow_id' => 121,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;
    }

    private function createOrder(): Order
    {
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 121, 'currency_id' => 1, 'key' => 'P2PDEPOSIT'],
            ['name' => 'PayMate P2PDEPOSIT', 'status' => 1, 'info' => '{}', 'admin' => 0]
        );

        return Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'amount' => 100,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'PMDEP' . uniqid(),
            'status' => 0,
            'rate' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);
    }

    private function createWithdrawalOrder(): WithdrawalOrder
    {
        $bank = Bank::firstOrCreate(['code' => 'MAYB'], [
            'name' => '(MYR) - Maybank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_id' => 0,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYB',
            'bank_name' => '(MYR) - Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    private function createPayoutRecord(int $withdrawalOrderId, string $transactionNo): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create([
            'cash_flow_id' => 122,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $withdrawalOrderId,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => '',
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
        ]);
    }

    private function fakeQueryResponse(array $inner): void
    {
        Http::fake([
            '*' => Http::response(
                $this->buildResponseEnvelope($inner, self::MERCHANT_NO, self::MD5_KEY),
                200
            ),
        ]);
    }

    /**
     * 代收查單：status=SUCCESS → ProviderOrderStatusEnum::SUCCESS，orderNo 為 order->order_no
     */
    public function test_deposit_query_maps_success(): void
    {
        $order = $this->createOrder();
        $this->fakeQueryResponse([
            'code' => '0000',
            'msg' => '查詢成功',
            'service' => 'QRH5',
            'status' => 'SUCCESS',
            'amount' => '100.00',
            'orderNo' => $order->order_no,
            'gbpOrderNo' => '190101185511101',
        ]);

        $result = (new QueryOrder($this->agentCashFlow))->handle(
            QueryOrderRequestData::from(['type' => 'deposit', 'order' => $order])
        );

        $this->assertSame('deposit', $result->type);
        $this->assertSame($order->order_no, $result->orderNo);
        $this->assertSame(ProviderOrderStatusEnum::SUCCESS, $result->orderStatus);
    }

    /**
     * 代收查單：PAYING 視為處理中 → ProviderOrderStatusEnum::PROCESSING
     */
    public function test_deposit_query_maps_paying_to_processing(): void
    {
        $order = $this->createOrder();
        $this->fakeQueryResponse([
            'code' => '0000',
            'msg' => '處理中',
            'service' => 'QRH5',
            'status' => 'PAYING',
            'orderNo' => $order->order_no,
        ]);

        $result = (new QueryOrder($this->agentCashFlow))->handle(
            QueryOrderRequestData::from(['type' => 'deposit', 'order' => $order])
        );

        $this->assertSame(ProviderOrderStatusEnum::PROCESSING, $result->orderStatus);
    }

    /**
     * 代收查單：status=FAIL → ProviderOrderStatusEnum::FAIL
     */
    public function test_deposit_query_maps_fail(): void
    {
        $order = $this->createOrder();
        $this->fakeQueryResponse([
            'code' => '0000',
            'msg' => '失敗',
            'service' => 'QRH5',
            'status' => 'FAIL',
            'orderNo' => $order->order_no,
        ]);

        $result = (new QueryOrder($this->agentCashFlow))->handle(
            QueryOrderRequestData::from(['type' => 'deposit', 'order' => $order])
        );

        $this->assertSame(ProviderOrderStatusEnum::FAIL, $result->orderStatus);
    }

    /**
     * 代付查單：反查最新 payout record 的 transaction_no 作為 orderNo，service=PAYOUT
     */
    public function test_payout_query_uses_latest_transaction_no(): void
    {
        $order = $this->createWithdrawalOrder();
        $this->createPayoutRecord($order->id, 'PMOLD001');
        $this->createPayoutRecord($order->id, 'PMNEW002');

        $this->fakeQueryResponse([
            'code' => '0000',
            'msg' => '查詢成功',
            'service' => 'PAYOUT',
            'status' => 'SUCCESS',
            'orderNo' => 'PMNEW002',
        ]);

        $result = (new QueryOrder($this->agentCashFlow))->handle(
            QueryOrderRequestData::from(['type' => 'withdraw', 'order' => $order])
        );

        $this->assertSame('withdraw', $result->type);
        $this->assertSame('PMNEW002', $result->orderNo);
        $this->assertSame(ProviderOrderStatusEnum::SUCCESS, $result->orderStatus);
    }

    /**
     * 代付查單：查無 payout record → ERROR，且不對三方發出請求
     */
    public function test_payout_query_without_record_returns_error(): void
    {
        Http::fake();
        $order = $this->createWithdrawalOrder();

        $result = (new QueryOrder($this->agentCashFlow))->handle(
            QueryOrderRequestData::from(['type' => 'withdraw', 'order' => $order])
        );

        $this->assertSame(ProviderOrderStatusEnum::ERROR, $result->orderStatus);
        $this->assertSame('', $result->orderNo);
        Http::assertNothingSent();
    }
}
