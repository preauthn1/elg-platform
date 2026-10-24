<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Deposit;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\CashFlowEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\AgentCustomizedSetting;
use App\Models\CashFlow;
use App\Models\Member;
use App\Models\MemberCryptoWallet;
use App\Models\OrderType;
use App\Models\OrderTypeName;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Context;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Deposit/DepositTopUpTypeTest.php
 */
class DepositTopUpTypeTest extends TestCase
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
        $this->assertEquals($orderType->id, $data[0]['order_type_id']);
        $this->assertEquals($orderType->currency_id, $data[0]['currency_id']);
        $this->assertEquals($orderType->name, $data[0]['name']);
        $this->assertEquals($cashFlow->id, $data[0]['cf_id']);
        $this->assertEquals($agentCashFlow->id, $data[0]['acf_id']);
        $this->assertEquals('PopupText0', $data[0]['PopupText']);
        $this->assertNotEmpty($data[0]['order_type_info']);
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
        $this->assertEquals($cryptoWallet->id, $data[0]['crypto_wallet_id']);
        $this->assertEquals($cryptoWallet->address, $data[0]['crypto_wallet_address']);
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
        $this->assertEquals(0, $data[0]['order_type_id']);
        $this->assertEquals(CashFlowEnum::DEPOSIT_AUTO_DEPOSIT, $data[0]['cf_id']);
    }

    /**
     * php artisan test tests/Feature/Frontend/Deposit/DepositTopUpTypeTest.php --filter test_deposit_order_type_success_with_auto_withdrawal
     *
     * @return void
     */
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
        $this->assertEquals(0, $data[0]['order_type_id']);
        $this->assertEquals(CashFlowEnum::WITHDRAW_AUTO_WITHDRAWAL, $data[0]['cf_id']);
    }

    public function test_duplicate_order_type_across_acfs_dedupes_to_earliest_channel()
    {
        // Arrange：同群組同金流商第二個商戶號（合法多商戶配置）掛同一 order_type
        [$cashFlow, $firstAgentCashFlow, $orderType, $firstChannel] = $this->createDefaultDatas();

        $secondAgentCashFlow = AgentCashFlow::create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'cash_flow_info_id' => 2,
            'cash_flow_id' => $cashFlow->id,
            'rate' => 2,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);
        AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $secondAgentCashFlow->id,
            'order_type_id' => $orderType->id,
            'status' => 1,
            'settings' => '',
        ]);

        // Act
        $response = $this->act([]);

        // Assert：同 order_type 只出一列，且取最早建立（最小 id）的渠道設定
        $response->assertStatus(200);
        $rows = collect($response->json('data'))->where('order_type_id', $orderType->id);
        $this->assertCount(1, $rows);
        $this->assertEquals($firstAgentCashFlow->id, $rows->first()['acf_id']);
    }

    public function test_channels_sorted_by_sorts_desc()
    {
        // Arrange：兩個 order_type，sorts 低者先建立、高者後建立
        [$cashFlow, $agentCashFlow, $lowSortOrderType] = $this->createDefaultDatas();
        AgentCashFlowOrderType::query()->update(['sorts' => 1]);

        $highSortOrderType = OrderType::create([
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
            'order_type_id' => $highSortOrderType->id,
            'status' => 1,
            'sorts' => 9,
            'settings' => '',
        ]);

        // Act
        $response = $this->act([]);

        // Assert：sorts 降冪
        $response->assertStatus(200);
        $orderTypeIds = array_column($response->json('data'), 'order_type_id');
        $this->assertEquals([$highSortOrderType->id, $lowSortOrderType->id], $orderTypeIds);
    }

    public function test_deposit_order_type_name_prefers_order_type_names_over_raw_name()
    {
        // Arrange：order_type_names 有語系列時，名稱不再回退 order_types.name
        [, , $orderType] = $this->createDefaultDatas();

        OrderTypeName::create([
            'type_id' => $orderType->id,
            'language_id' => LanguageEnum::EN->value,
            'name' => 'Bank Transfer EN',
            'admin' => 0,
        ]);

        // Act
        $response = $this->act([]);

        // Assert
        $response->assertStatus(200);
        $this->assertEquals('Bank Transfer EN', $response->json('data')[0]['name']);
    }

    public function test_channel_name_accessor_falls_back_language_then_en_then_order_type_name()
    {
        // Arrange
        [, , $orderType, $agentCashFlowOrderType] = $this->createDefaultDatas();

        OrderTypeName::create([
            'type_id' => $orderType->id,
            'language_id' => LanguageEnum::EN->value,
            'name' => 'Bank Transfer EN',
            'admin' => 0,
        ]);
        OrderTypeName::create([
            'type_id' => $orderType->id,
            'language_id' => LanguageEnum::ZH_TW->value,
            'name' => '銀行轉帳',
            'admin' => 0,
        ]);

        $freshChannel = fn () => AgentCashFlowOrderType::query()
            ->with(['agentCashFlowOrderTypeNames', 'orderType'])
            ->find($agentCashFlowOrderType->id);

        // Assert：命中 Context 語系
        Context::addHidden(ContextKeyEnum::LANGUAGE_ID->value, LanguageEnum::ZH_TW->value);
        $this->assertEquals('銀行轉帳', $freshChannel()->name);

        // Assert：語系無對應列 → 回退 EN
        Context::addHidden(ContextKeyEnum::LANGUAGE_ID->value, LanguageEnum::TH_TH->value);
        $this->assertEquals('Bank Transfer EN', $freshChannel()->name);

        // Assert：連 EN 都沒有 → 回退 order_types.name
        OrderTypeName::query()->where('type_id', $orderType->id)->delete();
        Context::addHidden(ContextKeyEnum::LANGUAGE_ID->value, LanguageEnum::ZH_TW->value);
        $this->assertEquals($orderType->name, $freshChannel()->name);
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/deposit/top-up-types',
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
                    'order_type_id',
                    'currency_id',
                    'name',
                    'cf_id',
                    'acf_id',
                    'cf_name',
                    'min_amount',
                    'max_amount',
                    'rate',
                    'image_path',
                    'order_type_info',
                    'choose_bank',
                    'crypto_wallet_id',
                    'crypto_wallet_address',
                    'PopupText',
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
