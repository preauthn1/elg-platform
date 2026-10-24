<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Deposit;

use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\Currency;
use App\Models\OrderType;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Deposit/DepositCreateOrderAlertTest.php
 */
class DepositCreateOrderAlertTest extends TestCase
{
    private string $loginToken;

    private OrderType $orderType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $this->loginToken = [REDACTED];
        $agent->cash_flow_group_id = 1;
        $agent->save();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1, 'code' => 'MYR', 'name' => 'Malaysian Ringgit',
            'rate' => 1, 'status' => 1, 'admin' => 0,
        ]));

        // DgPay cash_flow（third_party_code D_DG → PaymentService 解析出 DgPay Driver）
        $cashFlow = CashFlow::create([
            'id' => 100, 'third_party_code' => 'D_DG', 'name' => 'DGPay',
            'type' => 1, 'status' => 1, 'info' => '', 'admin' => 0,
        ]);
        $agentCashFlow = AgentCashFlow::create([
            'agent_group_id' => $agent->cash_flow_group_id,
            'cash_flow_info_id' => 1,
            'cash_flow_id' => $cashFlow->id,
            'status' => 1,
            'info' => [
                'APIUrl' => 'https://dgpayapi.test',
                'Merchant' => 'dgpay_test',
                'MerchantKey' => 'test-key',
                'Currency' => 'MYR',
                'PROVIDER_ID' => '5',
                'PROVIDER_TYPE' => '10',
            ],
            'admin' => 0,
        ]);
        $this->orderType = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => 1,
            'name' => 'TOUCH N GO eWALLET',
            'type' => 1, 'status' => 1, 'key' => 'TNGP',
            'info' => '{"data":[{"name":"amount","type":"number","require":"true","display":"deposit_amount"}]}',
            'admin' => 0,
        ]);
        AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $this->orderType->id,
            'status' => 1,
            'settings' => '{}',
        ]);

        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send')->andReturnNull();
    }

    private function act(): TestResponse
    {
        return $this->json('POST', '/frontend/api/deposit/create-order', $this->encryptParams([
            'order_type_id' => $this->orderType->id,
            'amount' => 30,
            'domain' => 'example.invalid',
        ]), [
            'Accept-Language' => 'zh-TW',
            'Authorization' => 'Bearer ' . $this->loginToken,
        ]);
    }

    public function test_config_error_returns_400_and_does_not_trigger_alert(): void
    {
        Http::fake([
            'https://dgpayapi.test/*' => Http::response([
                'code' => '-100',
                'description' => 'Internal Server Error',
                'providerMsg' => 'bankCode not support',
            ], 200),
        ]);

        // DgPay 對 -100 直接拋 ApiException：屬業務邏輯例外，AlertGate 一律靜音，不觸發 BotNotify。
        $this->act()->assertStatus(400);

        BotNotify::shouldNotHaveReceived('send');
    }
}
