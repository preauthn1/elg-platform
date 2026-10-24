<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\AddAgentCashFlow;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Models\Currency;
use App\Models\OrderType;
use Illuminate\Console\OutputStyle;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Console/Commands/CashFlow/AddAgentCashFlowCcpaymentCredentialsTest.php
 *
 * CCPayment 的金流商戶（agent_cash_flows）由 agent:add-agent-cash-flow 建立時，info 必須一次帶齊
 * Driver 會讀的四個鍵：API_URL、APP_ID、APP_SECRET、MERCHANT_PAY_NETWORK_FEE；
 * 幣別與鏈別都不在 info：代收跟著支付通道的 order type key，代付跟著提款銀行的三方代碼。
 * 非機密值由 Data/providers/CCPayment.php 的 credentials 模板直接給定，機密值（APP_ID、APP_SECRET）
 * 留空由上線人員在後台填入。沒有模板時 info 只會有空的 API_URL，上線要在後台對四個商戶各手打
 * 四個鍵，漏一個鍵回調就整條失效（CCPaymentTrait 缺鍵即拋）。
 */
class AddAgentCashFlowCcpaymentCredentialsTest extends TestCase
{
    private const int CURRENCY_USDT = 10;

    private const int AGENT_GROUP = 688;

    public function test_deposit_merchant_info_carries_every_driver_key_with_secrets_left_blank(): void
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

        $this->assertTrue($this->runMakeAgentCashFlow($cashFlow), '代收商戶應建立成功');

        $agentCashFlow = AgentCashFlow::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('agent_group_id', self::AGENT_GROUP)
            ->where('currency_id', self::CURRENCY_USDT)
            ->first();

        $this->assertNotNull($agentCashFlow, '應建立一筆 USDT 商戶');

        $info = $agentCashFlow->info;

        $this->assertSame(
            ['API_URL', 'APP_ID', 'APP_SECRET', 'MERCHANT_PAY_NETWORK_FEE'],
            collect(array_keys($info))->sort()->values()->all(),
            'info 鍵集合必須恰為 Driver 會讀的四個鍵，幣別與鏈別都不在 info',
        );
        $this->assertSame('https://example.invalid/ccpayment/v2', $info['API_URL'], 'API_URL 直接給定正式環境值');
        $this->assertSame('', $info['APP_ID'], '機密留空，由上線人員在後台填入');
        $this->assertSame('', $info['APP_SECRET'], '機密留空，由上線人員在後台填入');
        $this->assertSame('false', $info['MERCHANT_PAY_NETWORK_FEE'], '預設由會員負擔網路費');
    }

    private function runMakeAgentCashFlow(CashFlow $cashFlow): bool
    {
        $command = $this->app->make(AddAgentCashFlow::class);

        $input = new ArrayInput([], $command->getDefinition());
        $input->setInteractive(false);

        $property = new ReflectionProperty($command, 'input');
        $property->setValue($command, $input);

        $command->setOutput(new OutputStyle($input, new BufferedOutput));

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
