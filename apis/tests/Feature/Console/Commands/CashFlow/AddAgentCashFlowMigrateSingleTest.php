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
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Console/Commands/CashFlow/AddAgentCashFlowMigrateSingleTest.php
 *
 * 單筆→拆分就地遷移（原 fix:split-agent-cash-flow-by-channel 併入 --migrate-single）：
 * - 遷移保資料：rename 重用單筆列（id/status/info 不變）、新列繼承 rate/info/status、
 *   搬移既有 ACFOT 保留手動限額、刪孤兒、補缺只 firstOrCreate 不覆寫既有。
 * - 非互動未帶 --migrate-single 一律中止；混合狀態（單筆與 per-channel 並存）擋下。
 *
 * 以 TtPay 真實 provider 配置為藍圖（MYR 兩 channel、皆帶 cost key），
 * 期望值由測試內載入同一份配置推導，不硬編 channel 名稱。
 */
class AddAgentCashFlowMigrateSingleTest extends TestCase
{
    private const int CURRENCY_MYR = 1;

    private const int AGENT_GROUP = 688;

    public function test_migrate_single_splits_in_place_and_preserves_data(): void
    {
        [$cashFlow, $channels] = $this->makeCashFlowWithChannels();

        $firstKey = (string) $channels[0]['key'];
        $secondKey = (string) $channels[1]['key'];

        $firstOt = $this->createParentOrderType($cashFlow, key: $firstKey, status: 1);
        $this->createParentOrderType($cashFlow, key: $secondKey, status: 0);
        $orphanOt = $this->createParentOrderType($cashFlow, key: '49_99', status: 0);

        $legacyInfo = ['API_URL' => 'https://merchant.example', 'MERCHANT' => 'M123'];
        $legacy = $this->createLegacyAcf($cashFlow, info: $legacyInfo, status: 0);

        $movedAcfot = $this->createAcfot($legacy, $firstOt, minAmount: 77);
        $orphanAcfot = $this->createAcfot($legacy, $orphanOt, minAmount: 5);

        $result = $this->runMakeAgentCashFlow($cashFlow, ['--mode' => 'split', '--migrate-single' => true]);

        $this->assertTrue($result, '遷移＋補缺應成功');

        // 單筆列 rename 重用：id 不變、name 換成 channel[0]、status/info 原樣保留
        $legacy->refresh();
        $this->assertSame(sprintf('(MYR) %s', $channels[0]['name']), $legacy->name);
        $this->assertSame(0, (int) $legacy->status, '遷移不得改動原列 status（停用不可被意外啟用）');
        $this->assertSame($legacyInfo, $legacy->info, '原列 info（憑證）不得被 config 藍圖覆寫');

        // 其餘 channel 新建：繼承 status 與 info
        $second = AgentCashFlow::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('name', sprintf('(MYR) %s', $channels[1]['name']))
            ->first();
        $this->assertNotNull($second, 'channel[1] 應建立新 AgentCashFlow');
        $this->assertSame(0, (int) $second->status, '新列應繼承單筆列 status');
        $this->assertSame($legacyInfo, $second->info, '新列應繼承單筆列 info（生產憑證在 DB 不在 config）');

        // 既有 ACFOT 搬移保值：仍掛在原列、手動限額不被藍圖覆寫
        $movedAcfot->refresh();
        $this->assertSame($legacy->id, (int) $movedAcfot->agent_cash_flow_id, 'channel[0] 的 ACFOT 應保留在重用列');
        $this->assertSame(77, (int) $movedAcfot->min_amount, '補缺跑 firstOrCreate，不得覆寫既有手動限額');

        // 補缺：channel[1] 空殼補建 ACFOT（即使父層 OT 停用，拆分模式不濾 status）
        $secondAcfot = AgentCashFlowOrderType::query()
            ->where('agent_cash_flow_id', $second->id)
            ->first();
        $this->assertNotNull($secondAcfot, 'channel[1] 缺漏 ACFOT 應被補建');

        // 孤兒 ACFOT 刪除
        $this->assertNull(AgentCashFlowOrderType::query()->find($orphanAcfot->id), '孤兒 ACFOT 應被刪除');
    }

    public function test_non_interactive_without_flag_aborts(): void
    {
        [$cashFlow] = $this->makeCashFlowWithChannels();

        $legacy = $this->createLegacyAcf($cashFlow, info: ['API_URL' => 'x'], status: 1);

        $result = $this->runMakeAgentCashFlow($cashFlow, ['--mode' => 'split']);

        $this->assertFalse($result, '非互動未帶 --migrate-single 應中止');
        $this->assertSame($legacy->name, $legacy->refresh()->name, '中止時不得改動單筆列');
    }

    public function test_mixed_state_aborts_even_with_flag(): void
    {
        [$cashFlow, $channels] = $this->makeCashFlowWithChannels();

        $this->createLegacyAcf($cashFlow, info: ['API_URL' => 'x'], status: 1);

        // 預先存在 channel[1] 的 per-channel 記錄 → 混合狀態
        AgentCashFlow::create([
            'cash_flow_id' => $cashFlow->id,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => self::CURRENCY_MYR,
            'cash_flow_info_id' => 0,
            'name' => sprintf('(MYR) %s', $channels[1]['name']),
            'rate' => 1,
            'info' => '',
            'status' => 1,
            'admin' => 0,
        ]);

        $result = $this->runMakeAgentCashFlow($cashFlow, ['--mode' => 'split', '--migrate-single' => true]);

        $this->assertFalse($result, '混合狀態應擋下、不自動處理');
    }

    /** @return array{0: CashFlow, 1: array<int, array<string, mixed>>} */
    private function makeCashFlowWithChannels(): array
    {
        $cashFlow = CashFlow::create([
            'name' => 'Test TTPay ' . uniqid(),
            'type' => 1,
            'third_party_code' => 'D_TTPAY',
            'status' => 1,
            'admin' => 0,
        ]);

        $config = require app_path('Console/Commands/CashFlow/Data/providers/TtPay.php');
        $channels = array_values(array_filter(
            $config['deposit']['channels'] ?? [],
            fn (array $ch): bool => ($ch['currency'] ?? '') === 'MYR'
        ));

        $this->assertGreaterThanOrEqual(2, count($channels), '測試前提：TtPay MYR 配置至少兩個 channel');

        return [$cashFlow, $channels];
    }

    private function createLegacyAcf(CashFlow $cashFlow, array $info, int $status): AgentCashFlow
    {
        return AgentCashFlow::create([
            'cash_flow_id' => $cashFlow->id,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => self::CURRENCY_MYR,
            'cash_flow_info_id' => 0,
            'name' => sprintf('(MYR) %s', $cashFlow->name),
            'rate' => 1,
            'info' => $info,
            'status' => $status,
            'admin' => 0,
        ]);
    }

    private function createParentOrderType(CashFlow $cashFlow, string $key, int $status): OrderType
    {
        return OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => self::CURRENCY_MYR,
            'name' => 'TTPay ' . $key,
            'type' => 1,
            'status' => $status,
            'parent' => 0,
            'key' => $key,
            'choose_bank' => 0,
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    private function createAcfot(AgentCashFlow $acf, OrderType $orderType, int $minAmount): AgentCashFlowOrderType
    {
        return AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $acf->id,
            'order_type_id' => $orderType->id,
            'min_amount' => $minAmount,
            'max_amount' => 0,
            'available_amount' => '',
            'settings' => '{}',
            'sorts' => 0,
            'status' => 1,
        ]);
    }

    private function runMakeAgentCashFlow(CashFlow $cashFlow, array $parameters): bool
    {
        $command = $this->app->make(AddAgentCashFlow::class);

        $input = new ArrayInput($parameters, $command->getDefinition());
        $input->setInteractive(false);

        $property = new ReflectionProperty($command, 'input');
        $property->setValue($command, $input);

        $command->setOutput(new OutputStyle($input, new BufferedOutput));

        $agent = new Agent;
        $agent->forceFill(['id' => 990001, 'cash_flow_group_id' => self::AGENT_GROUP]);

        $currency = new Currency;
        $currency->forceFill(['id' => self::CURRENCY_MYR, 'code' => 'MYR', 'name' => 'Malaysia Dollar']);

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
