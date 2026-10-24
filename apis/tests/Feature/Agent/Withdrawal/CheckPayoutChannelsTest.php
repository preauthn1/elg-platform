<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Withdrawal;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlow;
use App\Models\WithdrawalOrder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Agent/Withdrawal/CheckPayoutChannelsTest.php
 */
class CheckPayoutChannelsTest extends TestCase
{
    private string $apiPath = 'agent/api/withdrawal/channels';

    private Agent $agent;

    private Bank $bank;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 AES 解密與日誌中間件，保留 AuthAgent + MergeAgentData 讓 agent context 正確注入
        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();

        // 建立銀行（含 currency_id）
        $this->bank = Bank::create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'type' => 1,
            'name' => '測試銀行',
            'code' => 'TEST001',
            'currency_id' => 1,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        // 建立 Agent token 並設定 Bearer header
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function createWithdrawalOrder(array $overrides = []): WithdrawalOrder
    {
        return WithdrawalOrder::factory()->create(array_merge([
            'agent_id' => $this->agent->id,
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'member_id' => 0,
            'bank_id' => $this->bank->id,
            'bank_code' => $this->bank->code,
            'bank_name' => $this->bank->name,
            'amount' => '500.00',
            'status' => WithdrawalOrderStatusEnum::APPROVED->value,
            'admin' => 0,
        ], $overrides));
    }

    private function createPayoutCashFlow(array $channelOverrides = []): AgentCashFlow
    {
        $cashFlow = CashFlow::create([
            'name' => 'Payout Channel',
            'type' => 3, // 代付
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        return AgentCashFlow::create(array_merge([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'cash_flow_id' => $cashFlow->id,
            'cash_flow_info_id' => 0,
            'currency_id' => 1,
            'rate' => 1,
            'info' => [
                'available_banks' => [
                    ['BankCode_ELG' => 'TEST001'],
                ],
                'min_amount' => 100,
                'max_amount' => 10000,
            ],
            'sorts' => 0,
            'cost' => 0,
            'status' => 1,
            'admin' => 1,
        ], $channelOverrides));
    }

    // -------------------------------------------------------
    // Tests
    // -------------------------------------------------------

    public function test_returns_available_channel_when_bank_code_matches(): void
    {
        // Arrange
        $order = $this->createWithdrawalOrder();
        $acf = $this->createPayoutCashFlow();

        // Act
        $response = $this->requestApi($this->apiPath, ['order_id' => $order->id]);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['code' => 200])
            ->assertJsonStructure([
                'data' => ['available', 'unavailable'],
            ])
            ->assertJsonCount(1, 'data.available')
            ->assertJsonCount(0, 'data.unavailable')
            ->assertJsonPath('data.available.0.agent_cash_flow_id', $acf->id);
    }

    /**
     * 迴歸：不能查他人代理線訂單的渠道（含 bank_code / bank_name / amount）。
     *
     * 原本一律 WithdrawalOrder::find($orderId)，訂單歸不歸呼叫者完全不問。
     */
    public function test_rejects_order_outside_agent_scope(): void
    {
        $otherAgent = Agent::factory()->create();
        $otherAgent->update(['hierarchy_path' => "/{$otherAgent->id}"]);

        $order = $this->createWithdrawalOrder(['agent_id' => $otherAgent->id]);
        $this->createPayoutCashFlow();

        $response = $this->requestApi($this->apiPath, ['order_id' => $order->id]);

        $response->assertJsonPath('message', __('error_message.9003'))
            ->assertJsonMissingPath('data.available');
    }

    public function test_returns_unavailable_channel_when_bank_code_not_supported(): void
    {
        // Arrange
        $order = $this->createWithdrawalOrder(['bank_code' => 'UNKNOWN']);
        $this->createPayoutCashFlow();

        // Act
        $response = $this->requestApi($this->apiPath, ['order_id' => $order->id]);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['code' => 200])
            ->assertJsonCount(0, 'data.available')
            ->assertJsonCount(1, 'data.unavailable')
            ->assertJsonPath('data.unavailable.0.reason', '不支援銀行 "' . $this->bank->name . '" (UNKNOWN)');
    }

    public function test_returns_unavailable_when_amount_below_min(): void
    {
        // Arrange
        $order = $this->createWithdrawalOrder(['amount' => '50.00']); // min_amount = 100
        $this->createPayoutCashFlow();

        // Act
        $response = $this->requestApi($this->apiPath, ['order_id' => $order->id]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonCount(0, 'data.available')
            ->assertJsonCount(1, 'data.unavailable')
            ->assertJsonPath('data.unavailable.0.reason', '訂單金額 50.00 低於最小限額 100.00');
    }

    public function test_returns_unavailable_when_amount_exceeds_max(): void
    {
        // Arrange
        $order = $this->createWithdrawalOrder(['amount' => '99999.00']); // max_amount = 10000
        $this->createPayoutCashFlow();

        // Act
        $response = $this->requestApi($this->apiPath, ['order_id' => $order->id]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonCount(0, 'data.available')
            ->assertJsonCount(1, 'data.unavailable')
            ->assertJsonPath('data.unavailable.0.reason', '訂單金額 99999.00 超過最大限額 10000.00');
    }

    public function test_returns_empty_lists_when_no_channels(): void
    {
        // Arrange
        $order = $this->createWithdrawalOrder();
        // 不建立任何 AgentCashFlow

        // Act
        $response = $this->requestApi($this->apiPath, ['order_id' => $order->id]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonCount(0, 'data.available')
            ->assertJsonCount(0, 'data.unavailable');
    }

    public function test_returns_404_when_order_not_found(): void
    {
        // Act
        $response = $this->requestApi($this->apiPath, ['order_id' => 999999]);

        // Assert
        $response->assertJson(['code' => 404]);
    }

    public function test_returns_400_when_bank_has_no_currency_id(): void
    {
        // Arrange
        $bankWithoutCurrency = Bank::create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'type' => 1,
            'name' => '無幣別銀行',
            'code' => 'NOCUR',
            'currency_id' => null,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);
        $order = $this->createWithdrawalOrder([
            'bank_id' => $bankWithoutCurrency->id,
            'bank_code' => $bankWithoutCurrency->code,
        ]);

        // Act
        $response = $this->requestApi($this->apiPath, ['order_id' => $order->id]);

        // Assert
        $response->assertJson(['code' => 400]);
    }

    public function test_validation_requires_order_id(): void
    {
        // Act
        $response = $this->requestApi($this->apiPath, []);

        // Assert
        $response->assertStatus(400);
    }

    public function test_returns_unavailable_when_channel_has_no_info(): void
    {
        // Arrange
        $order = $this->createWithdrawalOrder();
        $this->createPayoutCashFlow(['info' => []]);

        // Act
        $response = $this->requestApi($this->apiPath, ['order_id' => $order->id]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonCount(0, 'data.available')
            ->assertJsonCount(1, 'data.unavailable')
            ->assertJsonPath('data.unavailable.0.reason', '缺少渠道設定資訊');
    }
}
