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
 * php artisan test --compact tests/Feature/Console/Commands/CashFlow/AddAgentCashFlowKeyIdentityTest.php
 *
 * 拆分模式既有列識別：以 ACFOT → 父層 OrderType key 反查（穩定識別），
 * 不再單靠 name 字串比對（name 是營運顯示欄位，後台改名即 drift）：
 * - 既有 per-channel 列被人工改名後重跑，應命中並沿用該列，不得重複建立；
 *   name / status / cost 屬營運值，update 不覆寫（config cost 僅為藍圖占位）。
 * - 同 scope 兩列掛同一 channel key＝重複配置，應中止不新建。
 *
 * 以 TtPay 真實 provider 配置為藍圖（MYR 兩 channel），期望值由測試內載入
 * 同一份配置推導，不硬編 channel 名稱。
 */
class AddAgentCashFlowKeyIdentityTest extends TestCase
{
    private const int CURRENCY_MYR = 1;

    private const int AGENT_GROUP = 688;

    public function test_renamed_per_channel_row_is_updated_not_duplicated(): void
    {
        [$cashFlow, $channels] = $this->makeCashFlowWithChannels();

        $firstOt = $this->createParentOrderType($cashFlow, key: (string) $channels[0]['key'], status: 1);
        $this->createParentOrderType($cashFlow, key: (string) $channels[1]['key'], status: 1);

        // 既有 per-channel 列：營運已改名（與 config channel 名不同）、停用中、cost 為各站自設值
        $renamed = $this->createAcf($cashFlow, name: '(MYR) 營運自訂渠道名', status: 0, cost: 0.05);
        $this->createAcfot($renamed, $firstOt);

        $result = $this->runMakeAgentCashFlow($cashFlow, ['--mode' => 'split']);

        $this->assertTrue($result, '拆分模式應成功');

        // 不得重複建立：channel[0] 沿用改名列、channel[1] 新建 → scope 內恰 2 列
        $scoped = AgentCashFlow::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('agent_group_id', self::AGENT_GROUP)
            ->where('currency_id', self::CURRENCY_MYR)
            ->get();
        $this->assertCount(2, $scoped, '既有列應被 key 反查命中沿用，不得依 config 名新建重複列');

        // 營運值保留：name / status / cost 不被 config 藍圖覆寫
        $renamed->refresh();
        $this->assertSame('(MYR) 營運自訂渠道名', $renamed->name, '人工改名不得被 config 名覆寫');
        $this->assertSame(0, (int) $renamed->status, '停用列不得被重跑意外啟用');
        $this->assertSame('0.0500', (string) $renamed->cost, '各站自設 cost 不得被 config 占位值覆寫');

        // channel[1] 無既有列 → 以 config 名新建
        $this->assertNotNull(
            $scoped->firstWhere('name', sprintf('(MYR) %s', $channels[1]['name'])),
            'channel[1] 應以 config 名新建',
        );
    }

    public function test_duplicate_rows_on_same_channel_key_abort(): void
    {
        [$cashFlow, $channels] = $this->makeCashFlowWithChannels();

        $firstOt = $this->createParentOrderType($cashFlow, key: (string) $channels[0]['key'], status: 1);
        $this->createParentOrderType($cashFlow, key: (string) $channels[1]['key'], status: 1);

        // 同 channel key 已存在兩列（生產重複配置實況）
        $older = $this->createAcf($cashFlow, name: '(MYR) 舊列', status: 0, cost: 0.01);
        $this->createAcfot($older, $firstOt);
        $newer = $this->createAcf($cashFlow, name: '(MYR) 新列', status: 1, cost: 0.01);
        $this->createAcfot($newer, $firstOt);

        $result = $this->runMakeAgentCashFlow($cashFlow, ['--mode' => 'split']);

        $this->assertFalse($result, '同 key 多列＝重複配置，應中止要求人工清理');

        $scopedCount = AgentCashFlow::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('agent_group_id', self::AGENT_GROUP)
            ->where('currency_id', self::CURRENCY_MYR)
            ->count();
        $this->assertSame(2, $scopedCount, '中止時不得新建任何列');
    }

    public function test_soft_deleted_duplicate_unblocks_split_mode(): void
    {
        [$cashFlow, $channels] = $this->makeCashFlowWithChannels();

        $firstOt = $this->createParentOrderType($cashFlow, key: (string) $channels[0]['key'], status: 1);
        $this->createParentOrderType($cashFlow, key: (string) $channels[1]['key'], status: 1);

        $older = $this->createAcf($cashFlow, name: '(MYR) 舊列', status: 1, cost: 0.01);
        $this->createAcfot($older, $firstOt);
        $newer = $this->createAcf($cashFlow, name: '(MYR) 新列', status: 1, cost: 0.01);
        $this->createAcfot($newer, $firstOt);

        // 以 fix:acf-delete 的效果軟刪重複列（連動軟刪其 ACFOT 由 Observer 承擔）
        $newer->delete();

        $result = $this->runMakeAgentCashFlow($cashFlow, ['--mode' => 'split']);

        $this->assertTrue($result, '重複列軟刪後，split mode 應解封可續跑');

        // 倖存列被 key 反查命中沿用；channel[1] 新建 → 存活列共 2、軟刪列不復活
        $liveCount = AgentCashFlow::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('agent_group_id', self::AGENT_GROUP)
            ->where('currency_id', self::CURRENCY_MYR)
            ->count();
        $this->assertSame(2, $liveCount, '不得為 channel[0] 新建重複列');
        $this->assertNull(
            AgentCashFlow::query()->find($newer->id),
            '軟刪的重複列不得因重跑復活',
        );
    }

    public function test_key_matched_row_preserves_existing_acfot_values(): void
    {
        [$cashFlow, $channels] = $this->makeCashFlowWithChannels();

        $firstOt = $this->createParentOrderType($cashFlow, key: (string) $channels[0]['key'], status: 1);
        $this->createParentOrderType($cashFlow, key: (string) $channels[1]['key'], status: 1);

        $renamed = $this->createAcf($cashFlow, name: '(MYR) 營運自訂渠道名', status: 1, cost: 0.05);
        $acfot = $this->createAcfot($renamed, $firstOt);
        // 營運手動配置：停用該渠道關聯、自訂限額
        $acfot->update(['status' => 0, 'min_amount' => 77, 'max_amount' => 500]);

        $result = $this->runMakeAgentCashFlow($cashFlow, ['--mode' => 'split']);

        $this->assertTrue($result);

        // key 命中列的既有 ACFOT 屬營運值：status／手動限額不得被 config 藍圖打回
        $acfot->refresh();
        $this->assertSame(0, (int) $acfot->status, '營運停用的 ACFOT 不得被重跑打回啟用');
        $this->assertSame(77, (int) $acfot->min_amount, '手動限額不得被 config 藍圖覆寫');
        $this->assertSame(500, (int) $acfot->max_amount, '手動限額不得被 config 藍圖覆寫');
    }

    public function test_renamed_single_form_row_requires_migration_gate(): void
    {
        [$cashFlow, $channels] = $this->makeCashFlowWithChannels();

        $firstOt = $this->createParentOrderType($cashFlow, key: (string) $channels[0]['key'], status: 1);
        $secondOt = $this->createParentOrderType($cashFlow, key: (string) $channels[1]['key'], status: 1);

        // 單筆形態列（掛 ≥2 個父層 OT）且名稱已被人工改過（name 偵測必 miss）
        $renamedSingle = $this->createAcf($cashFlow, name: '(MYR) 老名字亂改', status: 1, cost: 0.02);
        $this->createAcfot($renamedSingle, $firstOt);
        $this->createAcfot($renamedSingle, $secondOt);

        $result = $this->runMakeAgentCashFlow($cashFlow, ['--mode' => 'split']);

        $this->assertFalse($result, '改名單筆列應被 shape 偵測攔下：非互動未帶 --migrate-single 必須中止');

        $scopedCount = AgentCashFlow::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('agent_group_id', self::AGENT_GROUP)
            ->where('currency_id', self::CURRENCY_MYR)
            ->count();
        $this->assertSame(1, $scopedCount, '中止時不得依 config 名新建 per-channel 列');
    }

    public function test_renamed_single_form_row_migrates_with_flag(): void
    {
        [$cashFlow, $channels] = $this->makeCashFlowWithChannels();

        $firstOt = $this->createParentOrderType($cashFlow, key: (string) $channels[0]['key'], status: 1);
        $secondOt = $this->createParentOrderType($cashFlow, key: (string) $channels[1]['key'], status: 1);

        $renamedSingle = $this->createAcf($cashFlow, name: '(MYR) 老名字亂改', status: 0, cost: 0.02);
        $firstAcfot = $this->createAcfot($renamedSingle, $firstOt);
        $secondAcfot = $this->createAcfot($renamedSingle, $secondOt);

        $result = $this->runMakeAgentCashFlow($cashFlow, ['--mode' => 'split', '--migrate-single' => true]);

        $this->assertTrue($result, 'shape 偵測到的單筆列帶 --migrate-single 應走既有遷移');

        // 遷移重用：改名列 rename 為 channel[0] config 名、status 保留；channel[1] 新建繼承
        $renamedSingle->refresh();
        $this->assertSame(sprintf('(MYR) %s', $channels[0]['name']), $renamedSingle->name, '遷移應 rename 重用單筆列');
        $this->assertSame(0, (int) $renamedSingle->status, '遷移不得改動原列 status');

        $scoped = AgentCashFlow::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('agent_group_id', self::AGENT_GROUP)
            ->where('currency_id', self::CURRENCY_MYR)
            ->get();
        $this->assertCount(2, $scoped, '遷移後 scope 內恰兩列（重用＋新建），不得重複');

        // ACFOT 重分配：channel[0] 保留原列、channel[1] 搬到新列
        $second = $scoped->firstWhere('name', sprintf('(MYR) %s', $channels[1]['name']));
        $this->assertNotNull($second);
        $this->assertSame($renamedSingle->id, (int) $firstAcfot->refresh()->agent_cash_flow_id);
        $this->assertSame($second->id, (int) $secondAcfot->refresh()->agent_cash_flow_id);
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

    private function createAcf(CashFlow $cashFlow, string $name, int $status, float $cost): AgentCashFlow
    {
        return AgentCashFlow::create([
            'cash_flow_id' => $cashFlow->id,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => self::CURRENCY_MYR,
            'cash_flow_info_id' => 0,
            'name' => $name,
            'rate' => 1,
            'cost' => $cost,
            'info' => ['API_URL' => 'https://merchant.example'],
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

    private function createAcfot(AgentCashFlow $acf, OrderType $orderType): AgentCashFlowOrderType
    {
        return AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $acf->id,
            'order_type_id' => $orderType->id,
            'min_amount' => 0,
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
