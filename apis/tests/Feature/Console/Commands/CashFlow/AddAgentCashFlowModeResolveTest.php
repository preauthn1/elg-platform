<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\AddAgentCashFlow;
use Illuminate\Console\OutputStyle;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Console/Commands/CashFlow/AddAgentCashFlowModeResolveTest.php
 *
 * 拆分形式（resolveDepositMode）決定順序：--mode 選項 > 互動詢問 > 非互動依 config cost 自動分流；
 * config 無目標幣別 channel 藍圖時無從拆分，一律單筆模式。
 * 互動詢問路徑（choice）不在此測，由人工 dry-run 驗證。
 */
class AddAgentCashFlowModeResolveTest extends TestCase
{
    public function test_empty_channels_forces_single_mode(): void
    {
        $command = $this->makeCommand([]);

        $this->assertSame('single', $this->resolveMode($command, []), 'config 無 channel 藍圖時無從拆分，應強制單筆模式');
    }

    public function test_mode_option_overrides_config_cost_suggestion(): void
    {
        $withCost = [['currency' => 'MYR', 'key' => '5_10', 'cost' => 0]];
        $withoutCost = [['currency' => 'MYR', 'key' => '5_10']];

        $this->assertSame('single', $this->resolveMode($this->makeCommand(['--mode' => 'single']), $withCost), '--mode=single 應優先於 config 帶 cost 的拆分建議');
        $this->assertSame('split', $this->resolveMode($this->makeCommand(['--mode' => 'split']), $withoutCost), '--mode=split 應優先於 config 無 cost 的單筆建議');
    }

    public function test_non_interactive_falls_back_to_cost_detection(): void
    {
        $withCost = [['currency' => 'MYR', 'key' => '5_10', 'cost' => 0]];
        $withoutCost = [['currency' => 'MYR', 'key' => '5_10']];

        $this->assertSame('split', $this->resolveMode($this->makeCommand([]), $withCost), '非互動未指定 --mode：任一 channel 存在 cost key（含 0）應走拆分');
        $this->assertSame('single', $this->resolveMode($this->makeCommand([]), $withoutCost), '非互動未指定 --mode：全部 channel 無 cost key 應走單筆');
    }

    private function makeCommand(array $parameters): AddAgentCashFlow
    {
        $command = $this->app->make(AddAgentCashFlow::class);

        $input = new ArrayInput($parameters, $command->getDefinition());
        $input->setInteractive(false);

        $property = new ReflectionProperty($command, 'input');
        $property->setValue($command, $input);

        $command->setOutput(new OutputStyle($input, new BufferedOutput));

        return $command;
    }

    private function resolveMode(AddAgentCashFlow $command, array $channels): string
    {
        $method = new ReflectionMethod(AddAgentCashFlow::class, 'resolveDepositMode');

        return $method->invoke($command, $channels);
    }
}
