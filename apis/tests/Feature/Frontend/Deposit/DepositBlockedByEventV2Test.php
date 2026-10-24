<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Deposit;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\Currency;
use App\Models\EventV2UserJoin;
use App\Models\OrderType;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 會員目前有進行中（status=JOIN）的活動 V2 參加紀錄時，禁止建立儲值訂單。
 *
 * php artisan test tests/Feature/Frontend/Deposit/DepositBlockedByEventV2Test.php
 */
class DepositBlockedByEventV2Test extends TestCase
{
    private string $loginToken;

    private OrderType $orderType;

    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $this->memberId = $member->id;
        $this->loginToken = [REDACTED];
        $agent->cash_flow_group_id = 1;
        $agent->save();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1, 'code' => 'MYR', 'name' => 'Malaysian Ringgit',
            'rate' => 1, 'status' => 1, 'admin' => 0,
        ]));

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

    public function test_deposit_blocked_when_member_has_active_event_v2_join(): void
    {
        EventV2UserJoin::insert([
            'event_v2_detail_id' => 1,
            'member_id' => $this->memberId,
            'reward' => 30,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->act();

        $response->assertStatus(400)
            ->assertJson([
                'code' => 400,
                'message' => '會員處於活動模式中，不允許儲值',
            ]);
    }

    public function test_deposit_not_blocked_when_event_v2_join_already_completed(): void
    {
        // COMPLETE 狀態代表活動已提款結束，不應再擋儲值
        EventV2UserJoin::insert([
            'event_v2_detail_id' => 1,
            'member_id' => $this->memberId,
            'reward' => 30,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->act();

        // 沒有 mock DgPay API，走到後面的三方請求必定失敗；只驗證不是被本次新增的檢查擋下
        $response->assertJsonMissing(['message' => '會員處於活動模式中，不允許儲值']);
    }
}
