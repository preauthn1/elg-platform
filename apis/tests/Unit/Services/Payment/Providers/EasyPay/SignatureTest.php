<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\EasyPay;

use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Providers\EasyPay\Deposit;
use App\Services\Payment\Providers\EasyPay\DepositCallback;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionMethod;
use Tests\TestCase;

/**
 * EasyPay 的 mdfive_val 簽名（工單 target/tickets#4470）。
 *
 * 守兩件事：建單簽出來的值與廠商文件一致，以及回調「沒帶簽名就放行、帶了就一定要驗得過」。
 * 後者是硬需求——簽名按商戶開關、預設關閉，改成一律要求會把未開簽名的商戶回調全擋掉。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/EasyPay/SignatureTest.php
 */
class SignatureTest extends TestCase
{
    private Agent $agent;

    private CashFlow $cashFlow;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->cashFlow = CashFlow::find(1) ?? CashFlow::forceCreate([
            'id' => 1,
            'third_party_code' => 'TEST',
            'name' => 'Test Cash Flow',
            'type' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        // 回調來源 IP 白名單（fail-closed）：沒放行的話會被防線 1 擋下，測不到驗簽那一步
        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    #[TestDox('文件範例值：代收建單只簽 amount / tx_id / callback_url / redirect_url')]
    public function test_deposit_signature_matches_vendor_example(): void
    {
        // 廠商文件（https://yizypayonline.online/merchant-docs §簽名 — mdfive_val）的範例：
        // 私鑰 abcdefgh23456789 + 下列四欄 ⇒ 40585e7c...0b86
        $agentCashFlow = $this->createEasyPayCashFlow(['API_PRIVATE_KEY' => 'abcdefgh23456789']);
        $order = $this->createDepositOrder($agentCashFlow, 'TX12345');

        $sent = $this->captureDepositRequest($agentCashFlow, $order, 'https://example.invalid/ok', 'https://example.invalid/cb');

        $this->assertSame(
            '40585e7cadc5482714d897c6f5f9f378558cf52f8a2bcca7d1a9a1b38ebc0b86',
            $sent['mdfive_val'],
            '簽出來的值與廠商文件範例不同：欄位清單、排序或 derived 那層的 key 型別有一個寫錯了'
        );

        // transferror_name 是必填欄位但不參與簽名，簽進去就會對不上
        $this->assertSame('player001', $sent['transferror_name']);
    }

    #[TestDox('沒設 API_PRIVATE_KEY 時建單不帶簽名（商戶未開啟簽名）')]
    public function test_deposit_request_unsigned_when_private_key_missing(): void
    {
        $agentCashFlow = $this->createEasyPayCashFlow();
        $order = $this->createDepositOrder($agentCashFlow, 'TX_NOKEY');

        $sent = $this->captureDepositRequest($agentCashFlow, $order);

        $this->assertArrayNotHasKey('mdfive_val', $sent);
    }

    #[TestDox('回調沒帶 mdfive_val 時照常處理（未開啟簽名的商戶不能被擋）')]
    public function test_callback_without_signature_is_accepted(): void
    {
        $agentCashFlow = $this->createEasyPayCashFlow(['API_PRIVATE_KEY' => 'abcdefgh23456789']);
        $order = $this->createCallbackOrder($agentCashFlow, 'EP_CB_UNSIGNED');

        $result = (new DepositCallback($agentCashFlow))->handle(
            $this->callbackRequest(['tx_id' => 'EP_CB_UNSIGNED'])
        );

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertSame('success', $result->response, '沒帶簽名代表該商戶沒開簽名，必須放行');

        // PENDING 是非終態，訂單維持 CREATED——驗的是「沒被簽名擋下」，不是入帳
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }

    #[TestDox('回調帶了錯的 mdfive_val 時拒絕，訂單狀態不動')]
    public function test_callback_with_invalid_signature_is_rejected(): void
    {
        $agentCashFlow = $this->createEasyPayCashFlow(['API_PRIVATE_KEY' => 'abcdefgh23456789']);
        $order = $this->createCallbackOrder($agentCashFlow, 'EP_CB_BADSIG');

        $result = (new DepositCallback($agentCashFlow))->handle($this->callbackRequest([
            'tx_id' => 'EP_CB_BADSIG',
            'status' => 'COMPLETE',
            'mdfive_val' => str_repeat('0', 64),
        ]));

        $this->assertSame('fail', $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status, '被擋下的回調不可以動到訂單');
    }

    #[TestDox('回調帶了對的 mdfive_val 時放行')]
    public function test_callback_with_valid_signature_is_accepted(): void
    {
        $privateKey = 'abcdefgh23456789';
        $agentCashFlow = $this->createEasyPayCashFlow(['API_PRIVATE_KEY' => $privateKey]);
        $this->createCallbackOrder($agentCashFlow, 'EP_CB_GOODSIG');

        $payload = [
            'tx_id' => 'EP_CB_GOODSIG',
            'transferror_name' => 'player001',
            'status' => 'PENDING',
            'order_id' => 'EP_TP_DEFAULT',
            'final_amount' => '100.00',
            'order_amount' => '100.00',
            'service_change' => '0.00',
        ];

        $signed = $payload;
        ksort($signed);
        $derived = hash_hmac('sha256', 'signing', $privateKey);
        $payload['mdfive_val'] = hash_hmac('sha256', http_build_query($signed), $derived);

        $result = (new DepositCallback($agentCashFlow))->handle($this->callbackRequest($payload));

        $this->assertSame('success', $result->response);
    }

    #[TestDox('廠商實際送來的回調（2026-10-13 dev 實測）簽得出同一個值')]
    public function test_real_callback_payload_signature_is_verified(): void
    {
        // 2026-10-13 dev 訂單 202610138494128850 收到的回調，欄位與送達順序原樣照抄
        // （三方送來的鍵不是排序過的，簽名前必須自己 ksort）。
        // 真實那筆用的是 dev 商戶私鑰、簽出 bae4d035…3105，與我方重算一致；此處換成測試用私鑰，
        // 期望值是對同一組欄位預先算好的——欄位被過濾、少簽一個、或 query 組法改掉都會紅。
        $payload = [
            'order_id' => 'DP17918607266ACD9FF6513C',
            'tx_id' => '202610138494128850',
            'transferror_name' => 'tim01',
            'status' => 'COMPLETE',
            'order_amount' => '100.00',
            'service_change' => '2.50',
            'final_amount' => '97.50',
            'mdfive_val' => '043355400e2b56b39909fedd75973210feb7865a5fab9f4269bd412c5111b195',
        ];

        $callback = new DepositCallback($this->createEasyPayCashFlow(['API_PRIVATE_KEY' => 'abcdefgh23456789']));

        $this->assertTrue(
            $this->verifyCallbackSignature($callback, $payload),
            '廠商實際回調的簽名驗不過：欄位集合、排序或 derived 那層的 key 型別有一個寫錯了'
        );

        // 同一組欄位改掉任一個值就必須驗不過
        $tampered = array_merge($payload, ['order_amount' => '999.00']);
        $this->assertFalse($this->verifyCallbackSignature($callback, $tampered));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function verifyCallbackSignature(DepositCallback $callback, array $payload): bool
    {
        $method = new ReflectionMethod($callback, 'isCallbackSignatureValid');

        return (bool) $method->invoke($callback, $payload);
    }

    /**
     * @param  array<string, string>  $extraInfo
     */
    private function createEasyPayCashFlow(array $extraInfo = []): AgentCashFlow
    {
        $info = array_merge([
            'API_URL' => 'https://api.easypay.test',
            'MERCHANT_NAME' => 'easy_merchant',
            'MERCHANT_PASSWORD' => 'easy_password',
        ], $extraInfo);

        $agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'currency_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);

        $agentCashFlow->info = $info;
        $agentCashFlow->setRelation('cashFlow', $this->cashFlow);

        return $agentCashFlow;
    }

    private function createDepositOrder(AgentCashFlow $agentCashFlow, string $orderNo): Order
    {
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

        $order = Order::factory()->create([
            'order_no' => $orderNo,
            'order_type_id' => $orderType->id,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 100,
            'status' => OrderStatusEnum::CREATED->value,
            'admin' => 0,
        ]);

        $order->setRelation('orderType', $orderType);
        $order->member->username = 'player001';

        return $order;
    }

    private function createCallbackOrder(AgentCashFlow $agentCashFlow, string $orderNo): Order
    {
        return Order::factory()->create([
            'order_no' => $orderNo,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => 0,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 100,
            'status' => OrderStatusEnum::CREATED->value,
            'admin' => 0,
        ]);
    }

    /**
     * 送一筆建單並把實際送出的 request body 撈回來。
     *
     * @return array<string, mixed>
     */
    private function captureDepositRequest(
        AgentCashFlow $agentCashFlow,
        Order $order,
        string $returnUrl = 'https://return.test',
        string $callbackUrl = 'https://callback.test'
    ): array {
        Http::fake([
            '*' => Http::response([
                'status' => 1,
                'data' => [
                    'order_id' => 'EP_TP_999',
                    'payment_details' => ['redirect_url' => 'https%3A%2F%2Fpay.test%2Fgo'],
                ],
            ]),
        ]);

        (new Deposit($agentCashFlow))->handle(DepositRequestData::from([
            'order' => $order,
            'member' => $order->member,
            'callbackUrl' => $callbackUrl,
            'returnUrl' => $returnUrl,
            'ip' => '0.0.0.0',
        ]));

        $sent = [];
        Http::assertSent(function (ClientRequest $request) use (&$sent): bool {
            $sent = $request->data();

            return true;
        });

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function callbackRequest(array $overrides = []): Request
    {
        return Request::create('/callback', 'POST', array_merge([
            'tx_id' => 'EP_CB_DEFAULT',
            'transferror_name' => 'player001',
            'status' => 'PENDING',
            'order_id' => 'EP_TP_DEFAULT',
            'final_amount' => '100.00',
            'order_amount' => '100.00',
            'service_change' => '0.00',
        ], $overrides));
    }
}
