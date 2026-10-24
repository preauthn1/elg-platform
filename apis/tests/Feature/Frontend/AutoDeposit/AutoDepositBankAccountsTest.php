<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\AutoDeposit;

use App\Enums\CashFlowEnum;
use App\Enums\CashFlowTypeEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\BankAccount;
use App\Models\CashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderImage;
use App\Models\OrderType;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/AutoDeposit/AutoDepositBankAccountsTest.php
 */
class AutoDepositBankAccountsTest extends TestCase
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

    public function test_deposit_banks_success()
    {
        // Arrange
        [
            $cashFlow,
            $agentCashFlow,
            $orderType,
            $agentCashFlowOrderType
        ] = $this->createDefaultDatas(CashFlowEnum::DEPOSIT_AUTO_DEPOSIT);

        $bankAccount = BankAccount::factory()->create([
            'order_type_id' => $orderType->id,
            'is_deposit' => 1,
        ]);

        // Act
        $response = $this->act([]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure($this->basicResponseDataStructure())
            ->assertJson([
                'data' => [
                    'type' => 1,
                ],
            ]);

        $data = $response->json('data');
        $bank = $data['bank'][0];
        $this->assertEquals($bank['order_type_id'], $orderType->id);
        $this->assertEquals($bank['bank_id'], $bankAccount->id);
        $this->assertEquals($bank['bank_name'], $orderType->name);
        $this->assertEquals($bank['bank_code'], $orderType->key);
    }

    public function test_deposit_banks_success_with_member_has_order()
    {
        // Arrange
        [
            $cashFlow,
            $agentCashFlow,
            $orderType,
            $agentCashFlowOrderType
        ] = $this->createDefaultDatas(CashFlowEnum::DEPOSIT_AUTO_DEPOSIT);

        $cashFlow->type = CashFlowTypeEnum::AUTO_DEPOSIT->value;
        $cashFlow->save();

        $bankAccounts = BankAccount::factory(2)->create([
            'order_type_id' => $orderType->id,
            'is_deposit' => 1,
        ]);

        $order = Order::factory()->create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'admin' => 0,
        ]);

        $orderImage = OrderImage::create([
            'order_id' => $order->id,
            'bank_id' => $bankAccounts[0]->id,
            'image' => 'test',
        ]);

        // Act
        $response = $this->act([]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure($this->basicResponseDataStructure())
            ->assertJson([
                'data' => [
                    'type' => 2,
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data['bank']);
        $this->assertEquals($data['bank'][0]['bank_id'], $bankAccounts[0]->id);
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/auto-deposit/bank-accounts',
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
                'type',
                'bank' => [
                    [
                        'order_type_id',
                        'bank_id',
                        'bank_name',
                        'bank_code',
                        'currency_id',
                        'account_name',
                        'account_no',
                        'bank_icon',
                        'agent_cash_flow_id',
                        'rate',
                        'min_amount',
                        'max_amount',
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
