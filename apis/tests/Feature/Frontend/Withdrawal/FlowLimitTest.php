<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Withdrawal;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use Carbon\Carbon;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Withdrawal/FlowLimitTest.php
 */
class FlowLimitTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->token = [REDACTED];

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT, 10);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::DEPOSIT_FLOW_LIMIT_VISIBLE, 1);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::EVENT_FLOW_LIMIT_VISIBLE, 1);
    }

    public function test_success()
    {
        $this->createFlowLimit(TransactionEnum::ACTIVITY_AWARD->value, 100, 10, 0);
        $this->createFlowLimit(TransactionEnum::DEPOSIT->value, 100, 10, 0);
        $this->createFlowLimit(TransactionEnum::ORDER_DEPOSIT->value, 100, 10, 0);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/flow-limit',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'flow_limit' => 300,
                    'reach' => 30,
                    'pass' => 0,
                ],
            ])
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'flow_limit',
                    'reach',
                    'pass',
                    'message',
                ],
            ]);
    }

    /**
     * 測試 DEPOSIT_FLOW_LIMIT_VISIBLE
     *
     *
     * @return void
     */
    public function test_deposit_visible()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::DEPOSIT_FLOW_LIMIT_VISIBLE, 0);

        $this->createFlowLimit(TransactionEnum::ACTIVITY_AWARD->value, 100, 10, 0);
        $this->createFlowLimit(TransactionEnum::DEPOSIT->value, 100, 10, 0);
        $this->createFlowLimit(TransactionEnum::ORDER_DEPOSIT->value, 100, 10, 0);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/flow-limit',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'flow_limit' => 100,
                    'reach' => 10,
                    'pass' => 0,
                ],
            ])
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'flow_limit',
                    'reach',
                    'pass',
                    'message',
                ],
            ]);
    }

    /**
     * 測試 EVENT_FLOW_LIMIT_VISIBLE
     * php artisan test tests/Feature/Frontend/FlowLimit/DetailTest.php --filter test_event_visible
     *
     * @return void
     */
    public function test_event_visible()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::EVENT_FLOW_LIMIT_VISIBLE, 0);

        $this->createFlowLimit(TransactionEnum::ACTIVITY_AWARD->value, 100, 10, 0);
        $this->createFlowLimit(TransactionEnum::DEPOSIT->value, 100, 10, 0);
        $this->createFlowLimit(TransactionEnum::ORDER_DEPOSIT->value, 100, 10, 0);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/flow-limit',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'flow_limit' => 200,
                    'reach' => 20,
                    'pass' => 0,
                ],
            ])
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'flow_limit',
                    'reach',
                    'pass',
                    'message',
                ],
            ]);
    }

    /**
     * 測試 pass
     *
     * @return void
     */
    public function test_pass()
    {
        $this->createFlowLimit(TransactionEnum::DEPOSIT->value, 100, 100, 0);
        $this->createFlowLimit(TransactionEnum::ORDER_DEPOSIT->value, 100, 100, 0);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/flow-limit',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'flow_limit' => 200,
                    'reach' => 200,
                    'pass' => 1,
                ],
            ])
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'flow_limit',
                    'reach',
                    'pass',
                    'message',
                ],
            ]);
    }

    /**
     * 測試提款次數
     *
     * @return void
     */
    public function test_withdrawal_times()
    {

        $yesterday = Carbon::now()->timezone('+8')->startOfDay()->setTimezone('utc')->subSecond();
        $today = Carbon::now()->timezone('+8')->startOfDay()->setTimezone('utc');
        $todayEnd = Carbon::now()->timezone('+8')->endOfDay()->setTimezone('utc');
        $tomorrow = Carbon::now()->timezone('+8')->endOfDay()->setTimezone('utc')->addSecond();

        $this->createWithdrawalOrder(10, $yesterday);
        $this->createWithdrawalOrder(10, $today);
        $this->createWithdrawalOrder(10, $todayEnd);
        $this->createWithdrawalOrder(10, $tomorrow);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/flow-limit',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert JSON 結構
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'flow_limit' => 0,
                    'reach' => 0,
                    'pass' => 1,
                ],
            ])
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'flow_limit',
                    'reach',
                    'pass',
                    'message',
                ],
            ]);
    }

    protected function createFlowLimit(int $type, float $flowLimit, float $reach, int $status)
    {
        FlowLimit::query()->insert(
            [
                'member_id' => $this->member->id,
                'type' => $type,
                'event_id' => 0,
                'reference_id' => 0,
                'flow_limit' => $flowLimit,
                'reach' => $reach,
                'status' => $status,
                'admin' => $this->member->id,
            ]
        );
    }

    protected function createWithdrawalOrder(float $amount, ?Carbon $created_at = null)
    {
        if (is_null($created_at)) {
            $created_at = Carbon::now();
        }
        // 暫時禁用批量賦值保護
        WithdrawalOrder::unguard();
        WithdrawalOrder::query()->insert([
            'bank_id' => 0,
            'bank_name' => '豐隆銀行',
            'bank_code' => 'hlb',
            'account_name' => '122',
            'account_no' => '122',
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => $amount,
            'rate' => 1.00000,
            'point' => 0.0000,
            'tax' => 0.00000,
            'third_party_order_no' => ' ',
            'third_party_response' => null,
            'status' => WithdrawalOrderStatusEnum::ORDER_CREATED->value,
            'check_time' => null,
            'audit_time' => null,
            'audit_operator' => null,
            'payment_time' => null,
            'payment_operator' => null,
            'memo' => '',
            'admin' => 0,
            'operator' => 0,
            'change_logs' => '',
            'created_at' => $created_at,
        ]);

        // 恢復批量賦值保護
        WithdrawalOrder::reguard();

    }
}
