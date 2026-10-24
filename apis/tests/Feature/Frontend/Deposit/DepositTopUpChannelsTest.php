<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Deposit;

use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\Member;
use App\Models\OrderType;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Deposit/DepositTopUpChannelsTest.php
 */
class DepositTopUpChannelsTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->loginToken = [REDACTED];

        $this->agent->cash_flow_group_id = 1;
        $this->agent->save();
    }

    public function test_returns_only_children_of_requested_order_type_with_contract_fields()
    {
        // Arrange：父渠道＋兩個子 order_type，另一個無關父渠道驗過濾
        [$cashFlow, $agentCashFlow, $parentOrderType] = $this->createParentChannel();

        $child = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'parent' => $parentOrderType->id,
            'currency_id' => 1,
            'name' => 'Maybank',
            'type' => 2,
            'status' => 1,
            'key' => '--',
            'info' => '{"data":[{"name":"amount","type":"number"}]}',
            'image_path' => '/images/maybank.png',
            'admin' => 0,
        ]);
        AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $child->id,
            'min_amount' => 50,
            'max_amount' => 5000,
            'status' => 1,
            'settings' => '',
        ]);

        $unrelatedParent = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => 1,
            'name' => 'E-Wallet',
            'type' => 1,
            'status' => 1,
            'key' => '--',
            'info' => '',
            'admin' => 0,
        ]);
        AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $unrelatedParent->id,
            'status' => 1,
            'settings' => '',
        ]);

        // Act
        $response = $this->act(['order_type_id' => $parentOrderType->id]);

        // Assert：只回該父渠道的子項，契約欄位齊備
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($child->id, $data[0]['id']);
        $this->assertEquals('Maybank', $data[0]['name']);
        $this->assertEquals('/images/maybank.png', $data[0]['image']);
        $this->assertEquals(50, $data[0]['min_amount']);
        $this->assertEquals(5000, $data[0]['max_amount']);
        $this->assertNotEmpty($data[0]['order_type_info']);
        $this->assertIsArray($data[0]['options']);
    }

    private function act(array $params): TestResponse
    {
        return $this->json(
            'POST',
            '/frontend/api/deposit/top-up-channels',
            $this->encryptParams($params),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );
    }

    private function createParentChannel(): array
    {
        $cashFlow = CashFlow::create([
            'id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        $agentCashFlow = AgentCashFlow::create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'cash_flow_info_id' => 1,
            'cash_flow_id' => $cashFlow->id,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        $parentOrderType = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'choose_bank' => 1,
            'status' => 1,
            'key' => '--',
            'info' => '',
            'admin' => 0,
        ]);

        AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $parentOrderType->id,
            'status' => 1,
            'settings' => '',
        ]);

        return [$cashFlow, $agentCashFlow, $parentOrderType];
    }
}
