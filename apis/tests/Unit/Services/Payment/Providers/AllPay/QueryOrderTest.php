<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\AllPay;

use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\QueryOrderRequestData;
use App\Services\Payment\Enums\ProviderOrderStatusEnum;
use App\Services\Payment\Providers\AllPay\QueryOrder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AllPay 訂單查詢的內層業務碼判讀。
 *
 * 回歸背景：QueryOrder 曾把成功判定寫反（`code !== '0000'` 才映射 status），
 * 導致查詢成功（code 0000 + status SUCCESS）的訂單被判成 ERROR。外層信封
 * （resCode）由 sendRequest 驗證，這裡只測內層 code/status 的映射方向。
 */
class QueryOrderTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected AgentCashFlow $agentCashFlow;

    private const MERCHANT_NO = 'AP888888';

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
            'APIUrl' => 'https://public.allpay.test/qrh5',
            'MerchantNo' => self::MERCHANT_NO,
            'MerchantKey' => 'unit-test-merchant-key',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => 1,
            'cash_flow_id' => 3,
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
            ['cash_flow_id' => 3, 'currency_id' => 1, 'key' => 'QRH5'],
            ['name' => 'AllPay QRH5', 'status' => 1, 'info' => '{}', 'admin' => 0]
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
            'order_no' => 'APDEP' . uniqid(),
            'status' => 0,
            'rate' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);
    }

    /**
     * AllPay 信封：外層 resCode/resMsg/merchantNo，內層業務 JSON base64 於 response。
     * sendRequest 驗外層 resCode 與 merchantNo（不驗回應簽名），成功即回傳解碼後內層。
     */
    private function fakeQueryResponse(array $inner): void
    {
        Http::fake([
            '*' => Http::response([
                'version' => '1.0',
                'resCode' => '000',
                'resMsg' => 'success',
                'merchantNo' => self::MERCHANT_NO,
                'response' => base64_encode((string) json_encode($inner)),
            ], 200),
        ]);
    }

    /**
     * 回歸案例：code 0000＋status SUCCESS 必須映射為 SUCCESS。
     * 修正前的反向條件會讓成功訂單被判成 ERROR。
     */
    public function test_query_maps_success_when_business_code_ok(): void
    {
        $order = $this->createOrder();
        $this->fakeQueryResponse([
            'code' => '0000',
            'msg' => 'success',
            'status' => 'SUCCESS',
            'orderNo' => $order->order_no,
            'gbpOrderNo' => 'GBP2026082500001',
            'amount' => '100.00',
        ]);

        $result = (new QueryOrder($this->agentCashFlow))->handle(
            QueryOrderRequestData::from(['type' => 'deposit', 'order' => $order])
        );

        $this->assertSame('deposit', $result->type);
        $this->assertSame($order->order_no, $result->orderNo);
        $this->assertSame(ProviderOrderStatusEnum::SUCCESS, $result->orderStatus);
    }

    public function test_query_maps_paying_and_processing_to_processing(): void
    {
        foreach (['PAYING', 'PROCESSING'] as $status) {
            $order = $this->createOrder();
            $this->fakeQueryResponse([
                'code' => '0000',
                'msg' => 'processing',
                'status' => $status,
                'orderNo' => $order->order_no,
            ]);

            $result = (new QueryOrder($this->agentCashFlow))->handle(
                QueryOrderRequestData::from(['type' => 'deposit', 'order' => $order])
            );

            $this->assertSame(ProviderOrderStatusEnum::PROCESSING, $result->orderStatus, "status={$status} 應映射為 PROCESSING");
        }
    }

    public function test_query_maps_fail(): void
    {
        $order = $this->createOrder();
        $this->fakeQueryResponse([
            'code' => '0000',
            'msg' => 'fail',
            'status' => 'FAIL',
            'orderNo' => $order->order_no,
        ]);

        $result = (new QueryOrder($this->agentCashFlow))->handle(
            QueryOrderRequestData::from(['type' => 'deposit', 'order' => $order])
        );

        $this->assertSame(ProviderOrderStatusEnum::FAIL, $result->orderStatus);
    }

    /**
     * 業務層失敗（code 非 0000）：status 不可信，維持 ERROR 並由 errorMessage 帶出 msg——
     * 就算三方在失敗回應裡帶了 status=SUCCESS 也不得採信。
     */
    public function test_query_returns_error_when_business_code_not_ok(): void
    {
        $order = $this->createOrder();
        $this->fakeQueryResponse([
            'code' => '9999',
            'msg' => 'order not found',
            'status' => 'SUCCESS',
            'orderNo' => $order->order_no,
        ]);

        $result = (new QueryOrder($this->agentCashFlow))->handle(
            QueryOrderRequestData::from(['type' => 'deposit', 'order' => $order])
        );

        $this->assertSame(ProviderOrderStatusEnum::ERROR, $result->orderStatus);
        $this->assertSame('order not found', $result->errorMessage);
    }

    /**
     * 能力介面宣告：兩個既有實作者必須 instanceof SupportsDepositQuery，
     * 否則 cash-flow:third-party-query 的能力偵測掃不到（capability matrix，
     * 斷言具體 provider 而非「數量>0」——防 SupportsPayoutQuery 式空殼）。
     */
    public function test_capability_matrix_deposit_query_implementers(): void
    {
        $this->assertInstanceOf(
            \App\Services\Payment\Contracts\SupportsDepositQuery::class,
            new \App\Services\Payment\Providers\AllPay\Driver($this->agentCashFlow),
        );
        $this->assertInstanceOf(
            \App\Services\Payment\Contracts\SupportsDepositQuery::class,
            new \App\Services\Payment\Providers\PayMate\Driver($this->agentCashFlow),
        );
    }
}
