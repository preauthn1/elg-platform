<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TrustPay;

use App\Enums\General\HttpCode;
use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Providers\TrustPay\Deposit;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TrustPay 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TrustPay/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    private string $secretKey = 'test_secret_key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'API_URL' => 'https://api.trustpay.test',
            'API_KEY' => 'test_api_key',
            'SECRET_KEY' => $this->secretKey,
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'currency_id' => $this->currency->id,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;
        $this->agentCashFlow->setRelation('currency', $this->currency);

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function createOrder(int $currencyId): Order
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => $currencyId,
            'name' => 'TrustPay Deposit',
            'type' => 2,
            'status' => 1,
            'key' => '7',
            'info' => '{}',
            'admin' => 0,
        ]);

        return Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $currencyId,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'TP' . uniqid(),
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);
    }

    private function makeRequest(Order $order): DepositRequestData
    {
        return DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://merchant.test/callback',
            'returnUrl' => 'https://merchant.test/return',
            'ip' => '0.0.0.0',
        ]);
    }

    /**
     * 幣別 id 對得到主檔：送出的 currency 是幣別代碼，且參與簽名
     */
    public function test_deposit_sends_currency_code_and_signature(): void
    {
        $order = $this->createOrder($this->currency->id);

        Http::fake([
            '*/api/create_deposit' => Http::response([
                'status' => true,
                'data' => [
                    'transaction_reference' => 'TP_TXN_001',
                    'payment_link' => 'https://pay.trustpay.test/x',
                ],
            ], 200),
        ]);

        $response = (new Deposit($this->agentCashFlow))->handle($this->makeRequest($order));

        $this->assertSame('https://pay.trustpay.test/x', $response->url);
        $this->assertSame('TP_TXN_001', $response->providerOrderNo);

        $expectedHash = hash_hmac('sha256', $order->order_no . '100.00' . 'MYR', $this->secretKey);
        Http::assertSent(fn ($request) => $request['currency'] === 'MYR' && $request['hash'] === $expectedHash);
    }

    /**
     * 訂單類型的幣別 id 是資料庫預設值 0：擋單、不打三方，錯誤語意是我方設定缺失（500）
     */
    public function test_deposit_rejects_zero_currency_id_before_calling_provider(): void
    {
        $order = $this->createOrder(0);
        Http::fake();

        try {
            (new Deposit($this->agentCashFlow))->handle($this->makeRequest($order));
            $this->fail('幣別 id 為 0 應擋單');
        } catch (ApiException $e) {
            $this->assertSame(HttpCode::HTTP_INTERNAL_SERVER_ERROR->value, $e->getCode());
            $this->assertStringContainsString('TrustPay invalid currency id: 0', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * 幣別 id 不在主檔內：同樣擋單、不打三方
     */
    public function test_deposit_rejects_unknown_currency_id_before_calling_provider(): void
    {
        $order = $this->createOrder(999);
        Http::fake();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('TrustPay invalid currency id: 999');

        try {
            (new Deposit($this->agentCashFlow))->handle($this->makeRequest($order));
        } finally {
            Http::assertNothingSent();
        }
    }
}
