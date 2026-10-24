<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Order;
use App\Services\AgentService;
use App\Services\DepositService;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * DepositService 測試
 *
 * php artisan test tests/Unit/Services/DepositServiceTest.php
 */
class DepositServiceTest extends TestCase
{
    private function serviceWithAgentMock(AgentService $agentService): DepositService
    {
        return new DepositService($agentService);
    }

    public function test_should_set_payment_password_false_when_wallet_timing_not_wallet_page(): void
    {
        $agent = Agent::factory()->make();
        $member = Member::factory()->make([
            'balance' => 100,
            'payment_password' => null,
        ]);

        $agentService = Mockery::mock(AgentService::class);
        $agentService->shouldReceive('getAgentSettings')
            ->once()
            ->with(Mockery::type(Agent::class))
            ->andReturn([
                AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_CHECK_TIMING_SETTING => 0,
            ]);

        $service = $this->serviceWithAgentMock($agentService);

        $this->assertFalse($service->shouldSetPaymentPassword($agent, $member));
    }

    public function test_should_set_payment_password_false_when_wallet_page_but_no_balance_and_no_first_deposit(): void
    {
        $agent = Agent::factory()->make();
        $member = Member::factory()->make([
            'id' => 50,
            'balance' => 0,
            'payment_password' => null,
        ]);

        $agentService = Mockery::mock(AgentService::class);
        $agentService->shouldReceive('getAgentSettings')
            ->once()
            ->andReturn([
                AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_CHECK_TIMING_SETTING => 1,
            ]);

        $service = Mockery::mock(DepositService::class, [$agentService])->makePartial();
        $service->shouldReceive('getFirstDeposit')->with(50)->once()->andReturn(null);

        $this->assertFalse($service->shouldSetPaymentPassword($agent, $member));
    }

    public function test_should_set_payment_password_true_when_wallet_page_balance_positive_without_password(): void
    {
        $agent = Agent::factory()->make();
        $member = Member::factory()->make([
            'id' => 123,
            'balance' => '0.0001',
            'payment_password' => null,
        ]);

        $agentService = Mockery::mock(AgentService::class);
        $agentService->shouldReceive('getAgentSettings')
            ->once()
            ->andReturn([
                AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_CHECK_TIMING_SETTING => 1,
            ]);

        $service = $this->serviceWithAgentMock($agentService);

        $this->assertTrue($service->shouldSetPaymentPassword($agent, $member));
    }

    public function test_should_set_payment_password_true_when_first_deposit_exists_without_password(): void
    {
        $agent = Agent::factory()->make();
        $member = Member::factory()->make([
            'id' => 77,
            'balance' => 0,
            'payment_password' => null,
        ]);

        $agentService = Mockery::mock(AgentService::class);
        $agentService->shouldReceive('getAgentSettings')
            ->once()
            ->andReturn([
                AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_CHECK_TIMING_SETTING => 1,
            ]);

        $order = Order::factory()->make(['id' => 9001]);

        $service = Mockery::mock(DepositService::class, [$agentService])->makePartial();
        $service->shouldReceive('getFirstDeposit')->with(77)->once()->andReturn($order);

        $this->assertTrue($service->shouldSetPaymentPassword($agent, $member));
    }

    public function test_should_set_payment_password_false_when_password_already_set(): void
    {
        $agent = Agent::factory()->make();
        $member = Member::factory()->make([
            'id' => 124,
            'balance' => 100,
            'payment_password' => 'hashed',
        ]);

        $agentService = Mockery::mock(AgentService::class);
        $agentService->shouldReceive('getAgentSettings')
            ->once()
            ->andReturn([
                AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_CHECK_TIMING_SETTING => 1,
            ]);

        $service = $this->serviceWithAgentMock($agentService);

        $this->assertFalse($service->shouldSetPaymentPassword($agent, $member));
    }

    public function test_require_real_name_returns_true_when_agent_does_not_require_first_name(): void
    {
        $agent = Agent::factory()->make();
        $member = Member::factory()->make(['first_name' => null]);

        $agentService = Mockery::mock(AgentService::class);
        $agentService->shouldReceive('getAgentSettings')
            ->once()
            ->andReturn([
                AgentCustomizedSettingKeyEnum::DIFFERENTIATE_FIRST_NAME_MEMBER => 0,
            ]);

        $service = $this->serviceWithAgentMock($agentService);

        $this->assertTrue($service->requireRealName($agent, $member));
    }

    public function test_require_real_name_returns_false_when_required_but_first_name_empty(): void
    {
        $agent = Agent::factory()->make();
        $member = Member::factory()->make(['first_name' => '   ']);

        $agentService = Mockery::mock(AgentService::class);
        $agentService->shouldReceive('getAgentSettings')
            ->once()
            ->andReturn([
                AgentCustomizedSettingKeyEnum::DIFFERENTIATE_FIRST_NAME_MEMBER => 1,
            ]);

        $service = $this->serviceWithAgentMock($agentService);

        $this->assertFalse($service->requireRealName($agent, $member));
    }

    public function test_require_real_name_returns_true_when_required_and_first_name_present(): void
    {
        $agent = Agent::factory()->make();
        $member = Member::factory()->make(['first_name' => 'Alice']);

        $agentService = Mockery::mock(AgentService::class);
        $agentService->shouldReceive('getAgentSettings')
            ->once()
            ->andReturn([
                AgentCustomizedSettingKeyEnum::DIFFERENTIATE_FIRST_NAME_MEMBER => 1,
            ]);

        $service = $this->serviceWithAgentMock($agentService);

        $this->assertTrue($service->requireRealName($agent, $member));
    }

    public function test_get_first_deposit_returns_earliest_finished_by_pay_time(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);
        [, , $orderType] = $this->createDefaultCashFlow($agent, cashFlowId: random_int(10000, 99999));

        $early = Order::factory()->create([
            'member_id' => $member->id,
            'agent_id' => $agent->id,
            'order_type_id' => $orderType->id,
            'third_party_order_no' => 'tp_' . Str::random(8),
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'pay_time' => '2026-01-01 10:00:00',
            'admin' => 0,
        ]);
        Order::factory()->create([
            'member_id' => $member->id,
            'agent_id' => $agent->id,
            'order_type_id' => $orderType->id,
            'third_party_order_no' => 'tp_' . Str::random(8),
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'pay_time' => '2026-06-01 10:00:00',
            'admin' => 0,
        ]);

        $service = new DepositService(new AgentService);
        $first = $service->getFirstDeposit($member->id);

        $this->assertNotNull($first);
        $this->assertSame($early->id, $first->id);
    }

    public function test_get_order_type_returns_null_when_id_not_found(): void
    {
        $agent = $this->createAgentAndDomain();
        $service = new DepositService(new AgentService);

        $this->assertNull($service->getOrderType($agent, 999_999_999));
    }

    public function test_get_order_type_loads_matching_order_type(): void
    {
        $agent = $this->createAgentAndDomain();
        [, , $orderType] = $this->createDefaultCashFlow($agent, cashFlowId: random_int(10000, 99999));

        $service = new DepositService(new AgentService);
        $found = $service->getOrderType($agent, $orderType->id);

        $this->assertNotNull($found);
        $this->assertSame($orderType->id, $found->id);
        $this->assertNotNull($found->relationLoaded('cashFlow'));
        $this->assertNotNull($found->relationLoaded('agentCashFlow'));
    }
}
