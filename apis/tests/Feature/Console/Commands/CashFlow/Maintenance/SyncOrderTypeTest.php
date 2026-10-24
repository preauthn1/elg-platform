<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Maintenance;

use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\OrderType;
use App\Models\OrderTypeName;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Console/Commands/CashFlow/Maintenance/SyncOrderTypeTest.php
 *
 * 驗證 ctool_sync_order_type 複製 order_type_names 時，新建列的 cash_flow_id
 * 取自 target order_type（$t_order_type->cash_flow_id），而非 source order_type_name
 * 原本帶的 cash_flow_id（$row->toArray() 裡的舊值）。
 *
 * Regression risk：SyncOrderType::handle() 對缺漏語言的處理是先 `$row->toArray()`
 * 再覆寫 `$insert['cash_flow_id']`；若覆寫順序被移除或改成沿用 source 值，
 * target order_type_name 會錯誤指向 source 的 cash_flow_id。
 */
class SyncOrderTypeTest extends TestCase
{
    private const CF_A = 99801;

    private const CF_B = 99802;

    private const LANGUAGE_ID = 1;

    public function test_copied_order_type_name_uses_target_cash_flow_id_not_source(): void
    {
        // source：cash_flow_id = CF_A，已有一筆該語言的 order_type_name
        $sourceOrderType = OrderType::create([
            'cash_flow_id' => self::CF_A, 'currency_id' => 1, 'name' => 'source', 'type' => 1,
            'key' => '--', 'info' => '', 'status' => 1, 'admin' => 0,
        ]);
        $sourceAgentCashFlow = AgentCashFlow::create([
            'agent_group_id' => 1, 'cash_flow_info_id' => 1, 'cash_flow_id' => self::CF_A,
            'status' => 1, 'info' => [], 'cost' => 1, 'rate' => 1, 'admin' => 0,
        ]);
        $sourceAcfot = AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $sourceAgentCashFlow->id, 'order_type_id' => $sourceOrderType->id,
            'status' => 1, 'settings' => '{}', 'sorts' => 5,
        ]);
        OrderTypeName::create([
            'cash_flow_id' => self::CF_A, 'type_id' => $sourceOrderType->id,
            'language_id' => self::LANGUAGE_ID, 'name' => '來源名', 'admin' => 0,
        ]);

        // target：cash_flow_id = CF_B（與 source 不同），尚無該語言的 order_type_name
        $targetOrderType = OrderType::create([
            'cash_flow_id' => self::CF_B, 'currency_id' => 1, 'name' => 'target', 'type' => 1,
            'key' => '--', 'info' => '', 'status' => 1, 'admin' => 0,
        ]);
        $targetAgentCashFlow = AgentCashFlow::create([
            'agent_group_id' => 1, 'cash_flow_info_id' => 1, 'cash_flow_id' => self::CF_B,
            'status' => 1, 'info' => [], 'cost' => 2, 'rate' => 2, 'admin' => 0,
        ]);
        $targetAcfot = AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $targetAgentCashFlow->id, 'order_type_id' => $targetOrderType->id,
            'status' => 1, 'settings' => '{}', 'sorts' => 1,
        ]);

        $source = sprintf('%d/%d/%d', self::CF_A, $sourceAgentCashFlow->id, $sourceOrderType->id);
        $target = sprintf('%d/%d/%d', self::CF_B, $targetAgentCashFlow->id, $targetOrderType->id);

        $this->artisan('ctool_sync_order_type', ['source' => $source, 'target' => $target])
            ->assertExitCode(0);

        $copied = OrderTypeName::query()
            ->where('type_id', $targetOrderType->id)
            ->where('language_id', self::LANGUAGE_ID)
            ->first();

        $this->assertNotNull($copied);
        $this->assertSame('來源名', $copied->name);
        $this->assertSame(
            self::CF_B,
            $copied->cash_flow_id,
            'target 新建的 order_type_name 必須用 target 的 cash_flow_id，不能沿用 source 的',
        );
        $this->assertNotSame(self::CF_A, $copied->cash_flow_id);

        // 同步旁支斷言：命令也應同步 cost/rate/sorts，確認 fixture 走的是真實 handle() 路徑
        $this->assertSame(1.0, (float) $targetAgentCashFlow->refresh()->cost);
        $this->assertSame(5, $targetAcfot->refresh()->sorts);
    }
}
