<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\OrderType;
use App\Models\OrderTypeName;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Migrations/CashFlowChannelViewsTest.php
 *
 * v_cash_flow_channels / v_agent_cash_flow_channels 兩張 view 的行為驗證。
 * 取代僅存在 DB、未入版控的舊 v_cash_flow_order_types（migration 一併 DROP）。
 *
 * 設計要點：
 * - view ①（渠道目錄）：頂層 OT（parent=0）一列；銀行子列以 bank_option_count 彙總；
 *   多語言 pivot 成 name_en / name_zh_tw / name_zh_cn（en=1、zh_tw=2、zh_cn=3）。
 * - view ②（代理配置）：ACFOT 一列；軟刪列全部呈現（附 deleted_at 欄，不內建過濾）；
 *   order_type_parent 為唯一結構事實：0=渠道列、>0=銀行子列（值即父 OT id）、NULL=查無 OT。
 *   不設 is_bank_option 之類語意欄——頂層 OT 也可能本身就是銀行（舊式一行一銀行渠道）。
 */
class CashFlowChannelViewsTest extends TestCase
{
    private const int CURRENCY_MYR = 1;

    private const int AGENT_GROUP = 688;

    private const int LANG_EN = 1;

    private const int LANG_ZH_TW = 2;

    public function test_legacy_view_replaced_by_channel_views(): void
    {
        $views = collect(DB::select(
            'SELECT TABLE_NAME AS name FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()'
        ))->pluck('name');

        $this->assertTrue($views->contains('v_cash_flow_channels'), '渠道目錄 view 應存在');
        $this->assertTrue($views->contains('v_agent_cash_flow_channels'), '代理配置 view 應存在');
        $this->assertFalse($views->contains('v_cash_flow_order_types'), '舊 view 應已被 DROP');
    }

    public function test_cash_flow_channels_lists_channels_with_language_pivot_and_bank_count(): void
    {
        [$cashFlow, $channelOt, $bankOt] = $this->makeChannelWithBankOption();

        OrderTypeName::create([
            'type_id' => $channelOt->id,
            'cash_flow_id' => $cashFlow->id,
            'language_id' => self::LANG_EN,
            'name' => 'Instant TopUp EN',
            'admin' => 0,
        ]);
        OrderTypeName::create([
            'type_id' => $channelOt->id,
            'cash_flow_id' => $cashFlow->id,
            'language_id' => self::LANG_ZH_TW,
            'name' => '快速進分',
            'admin' => 0,
        ]);

        $rows = DB::table('v_cash_flow_channels')->where('cash_flow_id', $cashFlow->id)->get();

        $this->assertCount(1, $rows, '目錄 view 只列頂層渠道，銀行子列不得成列');
        $row = $rows->first();
        $this->assertEquals($channelOt->id, $row->order_type_id);
        $this->assertSame($channelOt->key, $row->channel_key);
        $this->assertEquals(1, $row->bank_option_count, '銀行子列應以數量彙總');
        $this->assertSame('Instant TopUp EN', $row->name_en);
        $this->assertSame('快速進分', $row->name_zh_tw);
        $this->assertNull($row->name_zh_cn, '未建立的語言應為 NULL 而非缺欄');
    }

    public function test_agent_cash_flow_channels_exposes_config_with_channel_rollup(): void
    {
        [$cashFlow, $channelOt, $bankOt] = $this->makeChannelWithBankOption();
        $acf = $this->makeAcf($cashFlow);
        $this->makeAcfot($acf, $channelOt);
        $this->makeAcfot($acf, $bankOt);

        $rows = DB::table('v_agent_cash_flow_channels')
            ->where('agent_cash_flow_id', $acf->id)
            ->get()
            ->keyBy('order_type_id');

        $this->assertCount(2, $rows);

        $channelRow = $rows[$channelOt->id];
        $this->assertEquals(0, $channelRow->order_type_parent, '渠道列 order_type_parent 應為 0');
        $this->assertSame($channelOt->key, $channelRow->channel_key);
        $this->assertEquals($cashFlow->type, $channelRow->cash_flow_type, '應帶 cash_flows.type 區分代收/代付');
        $this->assertEquals(self::AGENT_GROUP, $channelRow->agent_group_id);

        $bankRow = $rows[$bankOt->id];
        $this->assertEquals($channelOt->id, $bankRow->order_type_parent, '銀行子列 order_type_parent 應為父 OT id');
        $this->assertSame($channelOt->key, $bankRow->channel_key, '銀行子列應歸戶到所屬渠道 key');
        $this->assertSame($bankOt->key, $bankRow->order_type_key);
    }

    public function test_agent_cash_flow_channels_keeps_soft_deleted_and_empty_acf_visible(): void
    {
        [$cashFlow, $channelOt] = $this->makeChannelWithBankOption();

        $deletedAcf = $this->makeAcf($cashFlow);
        $acfot = $this->makeAcfot($deletedAcf, $channelOt);
        $deletedAcf->delete();

        $emptyAcf = $this->makeAcf($cashFlow);

        $deletedRows = DB::table('v_agent_cash_flow_channels')
            ->where('agent_cash_flow_id', $deletedAcf->id)
            ->get();
        $this->assertCount(1, $deletedRows, '軟刪 ACF 應仍可見（附 deleted_at，不內建過濾）');
        $this->assertNotNull($deletedRows->first()->acf_deleted_at);
        $this->assertNotNull($deletedRows->first()->acfot_deleted_at, '連動軟刪的 ACFOT 應帶 deleted_at');
        $this->assertEquals($acfot->id, $deletedRows->first()->acfot_id);

        $emptyRows = DB::table('v_agent_cash_flow_channels')
            ->where('agent_cash_flow_id', $emptyAcf->id)
            ->get();
        $this->assertCount(1, $emptyRows, '空殼 ACF（零 ACFOT）應以單列呈現以利偵測');
        $this->assertNull($emptyRows->first()->acfot_id);
        $this->assertNull($emptyRows->first()->order_type_parent, '查無 OT 的列結構欄應為 NULL');
    }

    /**
     * @return array{0: CashFlow, 1: OrderType, 2: OrderType}
     */
    private function makeChannelWithBankOption(): array
    {
        $cashFlow = CashFlow::create([
            'name' => 'Test ChannelView ' . uniqid(),
            'type' => 1,
            'third_party_code' => 'D_TTPAY',
            'status' => 1,
            'admin' => 0,
        ]);

        $channelOt = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => self::CURRENCY_MYR,
            'name' => 'ChannelView OT',
            'type' => 2,
            'status' => 1,
            'parent' => 0,
            'key' => 'CHV_' . uniqid(),
            'choose_bank' => 1,
            'info' => '{}',
            'admin' => 0,
        ]);

        $bankOt = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => self::CURRENCY_MYR,
            'name' => '(MYR) - Test Bank',
            'type' => 2,
            'status' => 1,
            'parent' => $channelOt->id,
            'key' => 'CHVB_' . uniqid(),
            'choose_bank' => 0,
            'info' => '{}',
            'admin' => 0,
        ]);

        return [$cashFlow, $channelOt, $bankOt];
    }

    private function makeAcf(CashFlow $cashFlow): AgentCashFlow
    {
        return AgentCashFlow::create([
            'cash_flow_id' => $cashFlow->id,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => self::CURRENCY_MYR,
            'cash_flow_info_id' => 0,
            'name' => '(MYR) ChannelView Test',
            'rate' => 1,
            'info' => ['API_URL' => 'https://merchant.example'],
            'status' => 1,
            'admin' => 0,
        ]);
    }

    private function makeAcfot(AgentCashFlow $acf, OrderType $orderType): AgentCashFlowOrderType
    {
        return AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $acf->id,
            'order_type_id' => $orderType->id,
            'cash_flow_id' => $acf->cash_flow_id,
            'min_amount' => 0,
            'max_amount' => 0,
            'available_amount' => '',
            'settings' => '{}',
            'sorts' => 0,
            'status' => 1,
        ]);
    }
}
