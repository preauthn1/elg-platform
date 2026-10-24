<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\AddAgentCashFlow;
use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Models\OrderType;
use Illuminate\Console\OutputStyle;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Console/Commands/CashFlow/AddAgentCashFlowSplitStatusTest.php
 *
 * 拆分模式（processDepositCashFlow 帶 channelKey）依 provider config channel 藍圖預建配置，
 * 不受 DB order_types.status 停用狀態影響——避免 config 有 channel、但該站對應 OrderType 停用時
 * 撞 return false 導致整批 rollback（連帶把已成功的其他 channel 也回滾）。
 * 會員可見性由前端 OrderTypeService::getOrderTypes 的 order_types.status=1 主控，此處放寬不影響會員面。
 */
class AddAgentCashFlowSplitStatusTest extends TestCase
{
    private const int CURRENCY_MYR = 1;

    public function test_split_mode_builds_acfot_for_disabled_order_type_channel(): void
    {
        [$command, $cashFlow, $agentCashFlow] = $this->makeContext();

        $disabled = $this->createParentOrderType($cashFlow, key: '5_20', status: 0);

        $channel = ['currency' => 'MYR', 'name' => 'DGPay QR', 'key' => '5_20', 'cost' => 0, 'min_amount' => 30, 'max_amount' => 10000];
        $stats = ['order_types_created' => 0, 'banks_created' => 0];

        $result = $this->invokeProcessDeposit($command, $agentCashFlow, $cashFlow, [$channel], $stats, channelKey: '5_20');

        $this->assertTrue($result, '拆分模式撞停用渠道不應 return false（否則整批 rollback）');
        $this->assertDatabaseHas('agent_cash_flow_order_types', [
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $disabled->id,
        ]);
    }

    public function test_single_mode_still_excludes_disabled_order_type(): void
    {
        [$command, $cashFlow, $agentCashFlow] = $this->makeContext();

        $this->createParentOrderType($cashFlow, key: '5_20', status: 0);

        $channel = ['currency' => 'MYR', 'name' => 'DGPay QR', 'key' => '5_20', 'min_amount' => 30, 'max_amount' => 10000];
        $stats = ['order_types_created' => 0, 'banks_created' => 0];

        // channelKey = null → 單筆模式：維持只撈 status=1，撞全停用渠道應回 false
        $result = $this->invokeProcessDeposit($command, $agentCashFlow, $cashFlow, [$channel], $stats, channelKey: null);

        $this->assertFalse($result, '單筆模式不得因本次放寬而收錄停用渠道');
    }

    private function makeContext(): array
    {
        $cashFlow = CashFlow::create([
            'name' => 'Test DGPay ' . uniqid(),
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $agentCashFlow = AgentCashFlow::create([
            'agent_group_id' => 1,
            'cash_flow_info_id' => 0,
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => self::CURRENCY_MYR,
            'rate' => 1,
            'name' => '(MYR) DGPay QR ' . uniqid(),
            'info' => '',
            'status' => 1,
            'admin' => 0,
        ]);

        $command = $this->app->make(AddAgentCashFlow::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        return [$command, $cashFlow, $agentCashFlow];
    }

    private function createParentOrderType(CashFlow $cashFlow, string $key, int $status): OrderType
    {
        return OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => self::CURRENCY_MYR,
            'name' => 'DGPay ' . $key,
            'type' => 1,
            'status' => $status,
            'parent' => 0,
            'key' => $key,
            'choose_bank' => 0,
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    private function invokeProcessDeposit(
        AddAgentCashFlow $command,
        AgentCashFlow $agentCashFlow,
        CashFlow $cashFlow,
        array $channels,
        array $stats,
        ?string $channelKey,
    ): bool {
        $method = new ReflectionMethod(AddAgentCashFlow::class, 'processDepositCashFlow');

        return $method->invokeArgs($command, [
            $agentCashFlow,
            $cashFlow,
            self::CURRENCY_MYR,
            30.0,
            10000.0,
            $channels,
            &$stats,
            $channelKey,
        ]);
    }
}
