<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\B3Wallet;

use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderRequestLog;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use App\Services\Payment\Providers\B3Wallet\DepositCallback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * B3 Wallet 代收回調測試
 *
 * B3 Wallet 回調本身無簽名欄位，鑑權僅靠 IP 白名單；正確性一律以 GET /bills/{billId}
 * 查詢接口二次確認為準，故本測試以 Http::fake 模擬查詢接口回應而非驗簽情境。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/B3Wallet/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::factory()->create();

        $this->currency = Currency::firstOrCreate(['code' => 'SGD'], [
            'name' => 'Singapore Dollar',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $info = [
            'API_URL' => 'https://www.judy88.xin',
            'PAYMENT_KEY' => 'test_payment_key',
            'PAYMENT_PASS' => 'test_payment_pass',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'name' => 'B3 Wallet',
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

    private function createOrder(float $amount = 120.5): Order
    {
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => $this->currency->id],
            [
                'name' => 'B3Wallet PayNow',
                'status' => 1,
                'key' => 'PAYNOW',
                'info' => '{}',
                'admin' => 0,
            ]
        );

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $this->currency->id,
            'amount' => $amount,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'B3W' . uniqid(),
            'status' => OrderStatusEnum::CREATED->value,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);

        // 建單成功時 Deposit 會把 BillId 寫進 third_party_order_no，回調以它查單
        $order->update(['third_party_order_no' => 'bill-' . $order->id]);

        return $order;
    }

    private function callbackRequest(Order $order, bool $paid = true): array
    {
        return [
            'BillId' => 'bill-' . $order->id,
            'Amount' => (string) $order->amount,
            'BuyerId' => null,
            'Paid' => $paid,
            'Param' => $order->order_no,
            'ProductName' => 'Deposit',
            'ShopName' => 'B3 Wallet',
        ];
    }

    /**
     * 正常成功回調（查詢接口確認 PAID），訂單狀態更新為成功
     */
    public function test_success_callback_updates_order_to_success(): void
    {
        $order = $this->createOrder();

        Http::fake([
            '*' => Http::response([
                'BillId' => 'bill-' . $order->id,
                'Status' => 'PAID',
                'Amount' => $order->amount,
                'Paid' => true,
            ], 200),
        ]);

        $request = Request::create('/callback', 'POST', $this->callbackRequest($order));

        $callback = new DepositCallback($this->agentCashFlow);
        $result = $callback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
    }

    /**
     * 帳單過期（查詢接口 EXPIRED）時訂單標記失敗。
     *
     * 取自 Dev 2026-10-05 QA 實測：訂單 13977 走銀行轉帳通道，會員未在期限內付款，
     * 三方回調帶 Paid=false、查詢接口回 EXPIRED，訂單轉為失敗。
     * 帳單有效期實測僅 5 分鐘（建單 03:04:49、過期 03:09:49），逾時機率不低。
     */
    public function test_expired_bill_marks_order_failed(): void
    {
        $order = $this->createOrder();

        Http::fake([
            '*' => Http::response([
                'BillId' => 'bill-' . $order->id,
                'Status' => 'EXPIRED',
                'Amount' => $order->amount,
                'Paid' => false,
                'PaidAmount' => null,
                // 查詢接口帶手續費欄位，回調沒有（實測差異，見 api.md）
                'FeeAmount' => 0.31,
                'NetAmount' => 9.69,
            ], 200),
        ]);

        $request = Request::create('/callback', 'POST', $this->callbackRequest($order, paid: false));

        $result = (new DepositCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::FAILED->value, $order->status);
        // 回調原文留在 remark 供客服追查
        $this->assertStringContainsString('"Paid":false', (string) $order->remark);
    }

    /**
     * 查詢接口回傳仍為 PENDING（無簽名，不可只信回調 Paid）時不 finalize，維持 CREATED 等下次回調
     */
    public function test_query_not_final_does_not_finalize_order(): void
    {
        $order = $this->createOrder();

        Http::fake([
            '*' => Http::response([
                'BillId' => 'bill-' . $order->id,
                'Status' => 'PENDING',
                'Amount' => $order->amount,
                'Paid' => false,
            ], 200),
        ]);

        $request = Request::create('/callback', 'POST', $this->callbackRequest($order, paid: true));

        $callback = new DepositCallback($this->agentCashFlow);
        $result = $callback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 重複回調（冪等性），訂單已是終態時不重複處理
     */
    public function test_duplicate_callback_is_idempotent(): void
    {
        $order = $this->createOrder();
        $order->update(['status' => OrderStatusEnum::DEPOSIT_FINISHED->value]);

        Http::fake([
            '*' => Http::response(['BillId' => 'bill-' . $order->id, 'Status' => 'PAID'], 200),
        ]);

        $request = Request::create('/callback', 'POST', $this->callbackRequest($order));

        $callback = new DepositCallback($this->agentCashFlow);
        $result = $callback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::DEPOSIT_FINISHED->value, $order->status);

        // 冪等分支不應再呼叫查詢接口
        Http::assertNothingSent();
    }

    /**
     * 金額不符，訂單標記為待人工確認
     */
    public function test_amount_mismatch_marks_waiting_manual_deposit(): void
    {
        $order = $this->createOrder(amount: 120.5);

        Http::fake([
            '*' => Http::response([
                'BillId' => 'bill-' . $order->id,
                'Status' => 'PAID',
                'Amount' => 50.0,
                'Paid' => true,
            ], 200),
        ]);

        $request = Request::create('/callback', 'POST', $this->callbackRequest($order));

        $callback = new DepositCallback($this->agentCashFlow);
        $result = $callback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
    }

    /**
     * 查單在交易外：打查單那一刻的交易層數必須等於進 handle() 之前（測試本身那一層）。
     * 查單放在交易裡，三方一慢，訂單的行鎖就被拉成 HTTP 往返那麼久。
     */
    public function test_query_runs_outside_transaction(): void
    {
        $order = $this->createOrder();
        $baseline = DB::transactionLevel();
        $levelDuringQuery = null;

        Http::fake(function () use ($order, &$levelDuringQuery) {
            $levelDuringQuery = DB::transactionLevel();

            return Http::response(['BillId' => 'bill-' . $order->id, 'Status' => 'PAID', 'Amount' => $order->amount], 200);
        });

        (new DepositCallback($this->agentCashFlow))
            ->handle(Request::create('/callback', 'POST', $this->callbackRequest($order)));

        $this->assertSame($baseline, $levelDuringQuery);
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->refresh()->status);
    }

    /**
     * 回調帶的 BillId 不是建單時存下的那張：拒絕，不查單、不動訂單。
     *
     * 回調無簽名，不擋的話可以把同商戶另一張已付款的帳單配到這張訂單上結算。
     */
    public function test_callback_bill_id_not_matching_stored_is_rejected(): void
    {
        $order = $this->createOrder();

        Http::fake();

        $payload = ['BillId' => 'someone-elses-paid-bill'] + $this->callbackRequest($order);
        (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $payload));

        Http::assertNothingSent();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->refresh()->status);
        $this->assertSame(
            OrderRequestResultEnum::QUERY_ANOTHER_ORDER->value,
            OrderRequestLog::where('order_no', $order->order_no)->value('result')
        );
    }

    /**
     * 建單沒存到 BillId 時退回用回調的值，但查回來的 Param 必須是本單，否則拒絕。
     */
    public function test_without_stored_bill_id_queried_param_must_match(): void
    {
        $order = $this->createOrder();
        $order->update(['third_party_order_no' => '']);

        Http::fake([
            '*' => Http::response([
                'BillId' => 'bill-' . $order->id,
                'Param' => 'ANOTHER_ORDER_NO',
                'Status' => 'PAID',
                'Amount' => $order->amount,
            ], 200),
        ]);

        (new DepositCallback($this->agentCashFlow))
            ->handle(Request::create('/callback', 'POST', $this->callbackRequest($order)));

        $this->assertSame(OrderStatusEnum::CREATED->value, $order->refresh()->status);
    }

    /**
     * 查單回 PAID 卻沒帶 Amount：不定案，不退回用回調自帶的金額。
     */
    public function test_paid_without_queried_amount_does_not_finalize(): void
    {
        $order = $this->createOrder();

        Http::fake(['*' => Http::response(['BillId' => 'bill-' . $order->id, 'Status' => 'PAID'], 200)]);

        (new DepositCallback($this->agentCashFlow))
            ->handle(Request::create('/callback', 'POST', $this->callbackRequest($order)));

        $this->assertSame(OrderStatusEnum::CREATED->value, $order->refresh()->status);
        $this->assertSame(
            OrderRequestResultEnum::NO_AMOUNT->value,
            OrderRequestLog::where('order_no', $order->order_no)->value('result')
        );
    }

    /**
     * 每次回調都寫一筆往返紀錄，留回調原文與我方回給三方的內容。
     */
    public function test_callback_writes_order_request_log(): void
    {
        $order = $this->createOrder();

        Http::fake(['*' => Http::response(['BillId' => 'bill-' . $order->id, 'Status' => 'PAID', 'Amount' => $order->amount], 200)]);

        (new DepositCallback($this->agentCashFlow))
            ->handle(Request::create('/callback', 'POST', $this->callbackRequest($order)));

        $log = OrderRequestLog::where('order_no', $order->order_no)->sole();
        $this->assertSame(OrderRequestActionEnum::DEPOSIT_CALLBACK->value, $log->action);
        $this->assertSame(OrderRequestResultEnum::OK->value, $log->result);
        $this->assertSame($order->id, $log->orderable_id);
        $this->assertSame('bill-' . $order->id, $log->third_party_order_no);
        $this->assertStringContainsString('"BillId":"bill-' . $order->id . '"', (string) $log->request_body);
    }

    /**
     * 查單打不通：回 HTTP 500 讓三方重送，訂單不動。
     *
     * 本家重送只看 HTTP 狀態，回 2xx 的 fail 它就不再送，會員付了錢的單會停在 0 沒人知道。
     */
    public function test_query_failure_returns_500_for_retry(): void
    {
        $order = $this->createOrder();

        Http::fake(['*' => Http::response('Bad Gateway', 502)]);

        $result = (new DepositCallback($this->agentCashFlow))
            ->handle(Request::create('/callback', 'POST', $this->callbackRequest($order)));

        $this->assertSame(500, $result->httpStatusCode);
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->refresh()->status);
    }
}
