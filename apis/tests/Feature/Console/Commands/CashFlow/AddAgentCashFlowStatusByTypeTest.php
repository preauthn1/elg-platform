<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\AddAgentCashFlow;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\Currency;
use App\Models\OrderType;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Console/Commands/CashFlow/AddAgentCashFlowStatusByTypeTest.php
 *
 * --status 寫在營運實際使用的上下架開關上：代收在支付通道關聯（agent_cash_flow_order_types），
 * 代收金流商戶固定啟用；代付在金流商戶（agent_cash_flows）。停用時同一層的 backend_display 一併設 0，
 * 後台列表才會隱藏（新舊代理後台都依金流類型讀這兩層）。沿用既有支付通道關聯的路徑保留營運值。
 */
class AddAgentCashFlowStatusByTypeTest extends TestCase
{
    private const int CURRENCY_USDT = 10;

    private const int AGENT_GROUP = 688;

    #[Group('rules/payment-channel-management#R-3')]
    #[Group('rules/payment-channel-management#R-4')]
    public function test_deposit_status_zero_disables_and_hides_link_but_keeps_merchant_enabled(): void
    {
        $cashFlow = $this->createDepositCashFlow();

        $this->assertTrue($this->runMakeAgentCashFlow($cashFlow, ['--status' => '0']), '代收商戶應建立成功');

        $agentCashFlow = $this->findAgentCashFlow($cashFlow);
        $this->assertSame(1, (int) $agentCashFlow->status, '代收金流商戶固定啟用，上下架改由支付通道關聯控制');
        $this->assertSame(1, (int) $agentCashFlow->backend_display, '代收金流商戶的 backend_display 不是後台列表讀的欄位，不動');

        $link = AgentCashFlowOrderType::query()->where('agent_cash_flow_id', $agentCashFlow->id)->sole();
        $this->assertSame(0, (int) $link->status, '--status=0 寫在支付通道關聯');
        $this->assertSame(0, (int) $link->backend_display, '停用的支付通道關聯同時隱藏後台顯示');
    }

    #[Group('rules/payment-channel-management#R-4')]
    public function test_deposit_default_status_enables_and_shows_link(): void
    {
        $cashFlow = $this->createDepositCashFlow();

        $this->assertTrue($this->runMakeAgentCashFlow($cashFlow, []), '代收商戶應建立成功');

        $agentCashFlow = $this->findAgentCashFlow($cashFlow);
        $link = AgentCashFlowOrderType::query()->where('agent_cash_flow_id', $agentCashFlow->id)->sole();

        $this->assertSame(1, (int) $agentCashFlow->status);
        $this->assertSame(1, (int) $link->status, '預設 --status=1 建成啟用');
        $this->assertSame(1, (int) $link->backend_display, '預設 --status=1 建成顯示');
    }

    #[Group('rules/payment-channel-management#R-5')]
    public function test_create_missing_only_keeps_existing_link_values(): void
    {
        $cashFlow = $this->createDepositCashFlow();
        $orderType = OrderType::query()->where('cash_flow_id', $cashFlow->id)->sole();
        $agentCashFlow = AgentCashFlow::create([
            'agent_group_id' => self::AGENT_GROUP,
            'cash_flow_info_id' => 0,
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => self::CURRENCY_USDT,
            'rate' => 1,
            'name' => '(USDT) CCPayment ' . uniqid(),
            'info' => '',
            'status' => 1,
            'admin' => 0,
        ]);
        $existing = AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'cash_flow_id' => $cashFlow->id,
            'min_amount' => 5,
            'max_amount' => 0,
            'available_amount' => '',
            'settings' => '{}',
            'sorts' => 0,
            'status' => 1,
            'backend_display' => 1,
        ]);

        $stats = ['order_types_created' => 0, 'banks_created' => 0];
        $method = new ReflectionMethod(AddAgentCashFlow::class, 'processDepositCashFlow');
        $result = $method->invokeArgs($this->makeCommand([]), [
            $agentCashFlow,
            $cashFlow,
            self::CURRENCY_USDT,
            0.0,
            0.0,
            [],
            &$stats,
            null,
            true,
            0,
        ]);

        $this->assertTrue($result);
        $existing->refresh();
        $this->assertSame(1, (int) $existing->status, '沿用既有列時 status 屬營運值，不被 --status 蓋掉');
        $this->assertSame(1, (int) $existing->backend_display, '沿用既有列時 backend_display 屬營運值，不被 --status 蓋掉');
    }

    #[Group('rules/payment-channel-management#R-3')]
    #[Group('rules/payment-channel-management#R-4')]
    public function test_payout_status_lands_on_merchant_with_backend_display(): void
    {
        $method = new ReflectionMethod(AddAgentCashFlow::class, 'agentCashFlowStatusAttributes');
        $command = $this->makeCommand([]);

        $payout = new CashFlow;
        $payout->forceFill(['type' => 3]);

        $this->assertSame(['status' => 0, 'backend_display' => 0], $method->invoke($command, $payout, 0), '代付停用即隱藏');
        $this->assertSame(['status' => 1, 'backend_display' => 1], $method->invoke($command, $payout, 1));

        $deposit = new CashFlow;
        $deposit->forceFill(['type' => 1]);

        $this->assertSame(['status' => 1], $method->invoke($command, $deposit, 0), '代收金流商戶不吃 --status');
    }

    private function createDepositCashFlow(): CashFlow
    {
        $cashFlow = CashFlow::create([
            'name' => 'CCPayment - Deposit ' . uniqid(),
            'type' => 1,
            'third_party_code' => 'D_CCPAYMENT',
            'status' => 1,
            'admin' => 0,
        ]);

        OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => self::CURRENCY_USDT,
            'name' => 'CCPayment USDT (TRC20)',
            'type' => 1,
            'status' => 1,
            'parent' => 0,
            'key' => 'CCPAYMENT.USDT_TRX',
            'choose_bank' => 0,
            'info' => '{}',
            'admin' => 0,
        ]);

        return $cashFlow;
    }

    private function findAgentCashFlow(CashFlow $cashFlow): AgentCashFlow
    {
        return AgentCashFlow::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('agent_group_id', self::AGENT_GROUP)
            ->where('currency_id', self::CURRENCY_USDT)
            ->sole();
    }

    private function makeCommand(array $options): AddAgentCashFlow
    {
        $command = $this->app->make(AddAgentCashFlow::class);

        $input = new ArrayInput($options, $command->getDefinition());
        $input->setInteractive(false);

        $property = new ReflectionProperty($command, 'input');
        $property->setValue($command, $input);

        $command->setOutput(new OutputStyle($input, new BufferedOutput));

        return $command;
    }

    private function runMakeAgentCashFlow(CashFlow $cashFlow, array $options): bool
    {
        $command = $this->makeCommand($options);

        $agent = new Agent;
        $agent->forceFill(['id' => 990002, 'cash_flow_group_id' => self::AGENT_GROUP]);

        $currency = new Currency;
        $currency->forceFill(['id' => self::CURRENCY_USDT, 'code' => 'USDT', 'name' => 'Tether']);

        $stats = [
            'agent_cash_flow_created' => 0,
            'agent_cash_flow_updated' => 0,
            'order_types_created' => 0,
            'banks_created' => 0,
        ];

        $method = new ReflectionMethod(AddAgentCashFlow::class, 'makeAgentCashFlow');

        return $method->invokeArgs($command, [$agent, $cashFlow, $currency, &$stats]);
    }
}
