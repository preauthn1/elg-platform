<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\OrderType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ACFOT 唯一 live 連結（同 (agent_cash_flow_id, order_type_id) 至多一筆未軟刪列）：
 * Observer guard（友善錯誤層）＋ DB uq_acfot_live_link unique index（終防線）。
 *
 * php artisan test tests/Feature/Observers/AgentCashFlowOrderTypeObserverTest.php
 */
class AgentCashFlowOrderTypeObserverTest extends TestCase
{
    private AgentCashFlow $agentCashFlow;

    private OrderType $orderType;

    protected function setUp(): void
    {
        parent::setUp();

        $cashFlow = CashFlow::create([
            'id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        $this->agentCashFlow = AgentCashFlow::create([
            'agent_group_id' => 1,
            'cash_flow_info_id' => 1,
            'cash_flow_id' => $cashFlow->id,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        $this->orderType = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => '--',
            'info' => '',
            'admin' => 0,
        ]);
    }

    public function test_creating_duplicate_live_link_is_rejected()
    {
        $this->makeLink();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('duplicate link rejected');

        $this->makeLink();
    }

    public function test_duplicate_rejected_even_when_existing_row_is_disabled()
    {
        // 停用列仍占用唯一性——啟停走 status 開關，不以第二列表達
        $this->makeLink(['status' => 0]);

        $this->expectException(ApiException::class);

        $this->makeLink();
    }

    public function test_soft_deleted_link_does_not_block_recreation()
    {
        $link = $this->makeLink();
        $link->delete();

        $recreated = $this->makeLink();

        $this->assertTrue($recreated->exists);
        $this->assertNull($recreated->deleted_at);
    }

    public function test_same_order_type_under_different_acf_is_allowed()
    {
        // 同群組同金流商第二商戶號掛同一 order_type＝合法多商戶配置
        $this->makeLink();

        $secondAcf = AgentCashFlow::create([
            'agent_group_id' => 1,
            'cash_flow_info_id' => 2,
            'cash_flow_id' => $this->agentCashFlow->cash_flow_id,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        $link = AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $secondAcf->id,
            'order_type_id' => $this->orderType->id,
            'status' => 1,
            'settings' => '',
        ]);

        $this->assertTrue($link->exists);
    }

    public function test_updating_into_colliding_pair_is_rejected()
    {
        $this->makeLink();

        $otherOrderType = OrderType::create([
            'cash_flow_id' => $this->orderType->cash_flow_id,
            'currency_id' => 1,
            'name' => 'E-Wallet',
            'type' => 1,
            'status' => 1,
            'key' => '--',
            'info' => '',
            'admin' => 0,
        ]);
        $otherLink = $this->makeLink(['order_type_id' => $otherOrderType->id]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('duplicate link rejected');

        $otherLink->update(['order_type_id' => $this->orderType->id]);
    }

    public function test_db_unique_index_blocks_duplicate_even_when_observer_is_bypassed()
    {
        // 終防線驗證：raw insert 繞過 Eloquent／Observer（模擬併發 race 或 bulk 寫入），
        // 由 uq_acfot_live_link 於 DB 層擋下（測試專用 bypass，業務代碼禁 DB::table 寫入）
        $link = $this->makeLink();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('uq_acfot_live_link');

        DB::table('agent_cash_flow_order_types')->insert([
            'agent_cash_flow_id' => $link->agent_cash_flow_id,
            'order_type_id' => $link->order_type_id,
            'status' => 1,
            'settings' => '',
            'available_amount' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_restoring_soft_deleted_link_fails_loudly_when_replacement_exists()
    {
        // restore() 只動 deleted_at、不觸發 guard 的 isDirty 檢查——由 DB 唯一鍵 fail loud，
        // 不留同 pair 雙 live 列
        $original = $this->makeLink();
        $original->delete();
        $this->makeLink();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('uq_acfot_live_link');

        $original->restore();
    }

    private function makeLink(array $overrides = []): AgentCashFlowOrderType
    {
        return AgentCashFlowOrderType::create($overrides + [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_type_id' => $this->orderType->id,
            'status' => 1,
            'settings' => '',
        ]);
    }
}
