<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Deposit;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\CashFlowEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\AgentCustomizedSetting;
use App\Models\CashFlow;
use App\Models\Member;
use App\Models\MemberCryptoWallet;
use App\Models\OrderType;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Deposit/DepositTypeTest.php
 */
class DepositTypeTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken = [REDACTED]';

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

    public function test_deposit_order_type_success()
    {
        // Arrange
        [
            $cashFlow,
            $agentCashFlow,
            $orderType,
            $agentCashFlowOrderType,
        ] = $this->createDefaultDatas();

        // Act
        $response = $this->act([]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $firstItem = $data[0]['items'][0];
        $this->assertEquals($orderType->id, $firstItem['order_type_id']);
        $this->assertEquals($orderType->currency_id, $firstItem['currency_id']);
        $this->assertEquals($orderType->name, $firstItem['name']);
        // $this->assertEquals($cashFlow->id, $firstItem['cf_id']);
        // $this->assertEquals($agentCashFlow->id, $firstItem['acf_id']);
        $this->assertEquals('PopupText0', $firstItem['popup_text']);
        $this->assertNotEmpty($firstItem['order_type_info']);
    }

    public function test_deposit_order_type_success_with_crypto_wallet()
    {
        // Arrange
        [
            $cashFlow,
            $agentCashFlow,
            $orderType,
            $agentCashFlowOrderType,
        ] = $this->createDefaultDatas(CashFlowEnum::BIND_CRYPTO_WALLET[0]);

        $cryptoWallet = MemberCryptoWallet::create([
            'member_id' => $this->member->id,
            'agent_cash_flow_order_type_id' => $agentCashFlowOrderType->id,
            'address' => '0x1234567890abcdef',
            'status' => 1,
            'admin' => 0,
        ]);

        // Act
        $response = $this->act([]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $firstItem = $data[0]['items'][0];
        $this->assertEquals($cryptoWallet->id, $firstItem['crypto_wallet_id']);
        $this->assertEquals($cryptoWallet->address, $firstItem['crypto_wallet_address']);
    }

    public function test_deposit_order_type_success_with_auto_deposit()
    {
        // Arrange

        $autoDepositCashFlow = CashFlow::create([
            'id' => CashFlowEnum::DEPOSIT_AUTO_DEPOSIT,
            'name' => 'Auto Deposit',
            'type' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);
        $autoDepositCashFlow->agentCashFlows()->create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'cash_flow_info_id' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);
        AgentCustomizedSetting::create([
            'agent_group_id' => $this->agent->customized_setting_group_id,
            'setting_key' => AgentCustomizedSettingKeyEnum::AUTODESPOSITE_BUTTON_VISIBLE,
            'value' => '1',
            'sub_category' => '',
            'memo' => '',
        ]);

        // Act
        $response = $this->act([]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $firstItem = $data[0]['items'][0];
        $this->assertEquals(0, $firstItem['order_type_id']);
        // $this->assertEquals(CashFlowEnum::DEPOSIT_AUTO_DEPOSIT, $firstItem['cf_id']);
    }

    public function test_deposit_order_type_success_with_auto_withdrawal()
    {
        // Arrange

        $autoWithdrawalCashFlow = CashFlow::create([
            'id' => CashFlowEnum::WITHDRAW_AUTO_WITHDRAWAL,
            'name' => 'Auto Withdrawal',
            'type' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);
        $autoWithdrawalCashFlow->agentCashFlows()->create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'cash_flow_info_id' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);
        AgentCustomizedSetting::create([
            'agent_group_id' => $this->agent->customized_setting_group_id,
            'setting_key' => AgentCustomizedSettingKeyEnum::WITHDRAWAL_BUTTON_VISIBLE,
            'value' => '1',
            'sub_category' => '',
            'memo' => '',
        ]);

        // Act
        $response = $this->act([]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $firstItem = $data[0]['items'][0];
        $this->assertEquals(0, $firstItem['order_type_id']);
        // $this->assertEquals(CashFlowEnum::WITHDRAW_AUTO_WITHDRAWAL, $firstItem['cf_id']);
    }

    public function test_choose_bank_children_grouped_under_parent_banks()
    {
        // Arrange：choose_bank 父渠道＋兩個子 order_type（parent 指向父），子項各有自己的 ACFOT
        [$cashFlow, $agentCashFlow, $parentOrderType] = $this->createDefaultDatas();
        $parentOrderType->update(['choose_bank' => 1]);

        $childNames = ['Maybank', 'CIMB'];
        $children = [];
        foreach ($childNames as $index => $name) {
            $child = OrderType::create([
                'cash_flow_id' => $cashFlow->id,
                'parent' => $parentOrderType->id,
                'currency_id' => 1,
                'name' => $name,
                'type' => 2,
                'status' => 1,
                'key' => '--',
                'info' => '',
                'admin' => 0,
            ]);
            AgentCashFlowOrderType::create([
                'agent_cash_flow_id' => $agentCashFlow->id,
                'order_type_id' => $child->id,
                'min_amount' => 100 * ($index + 1),
                'max_amount' => 1000 * ($index + 1),
                'status' => 1,
                'settings' => '',
            ]);
            $children[] = $child;
        }

        // Act
        $response = $this->act([]);

        // Assert：子項不出現在頂層 items，只掛在父項 banks 下，欄位契約完整
        $response->assertStatus(200);
        $items = collect($response->json('data'))->flatMap(fn ($group) => $group['items']);
        $this->assertEquals([$parentOrderType->id], $items->pluck('order_type_id')->all());

        $banks = collect($items->first()['banks'])->keyBy('id');
        $this->assertCount(2, $banks);
        foreach ($children as $index => $child) {
            $this->assertEquals($child->name, $banks[$child->id]['name']);
            $this->assertEquals(100 * ($index + 1), $banks[$child->id]['min_amount']);
            $this->assertEquals(1000 * ($index + 1), $banks[$child->id]['max_amount']);
        }
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/deposit/types',
            $this->encryptParams($params),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );

        return $response;
    }

    private function basicResponseDataStructure(): array
    {
        return [
            'code',
            'message',
            'data' => [
                [
                    'type',
                    'items' => [
                        [
                            'order_type_id',
                            'currency_id',
                            'name',
                            // 'cf_id',
                            // 'acf_id',
                            // 'cf_name',
                            'min_amount',
                            'max_amount',
                            'rate',
                            'image_path',
                            'order_type_info',
                            'banks',
                            'crypto_wallet_id',
                            'crypto_wallet_address',
                            'popup_text',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function createDefaultDatas(int $cashFlowId = 1): array
    {
        $cashFlow = CashFlow::create([
            'id' => $cashFlowId,
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

        $orderType = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => '--',
            'info' => '{"data":[{"name":"amount","type":"number","require":"true","display":"deposit_amount"}]}',
            'admin' => 0,
        ]);

        $agentCashFlowOrderType = AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'status' => 1,
            'settings' => '{"PopupText":{"0":"PopupText0"}}',
        ]);

        return [$cashFlow, $agentCashFlow, $orderType, $agentCashFlowOrderType];
    }
}
