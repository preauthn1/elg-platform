<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\GgrPayoutRecord;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\GgrPlan;
use App\Models\GgrSettlement;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 推廣代理GGR - 派發紀錄 後台查詢（4125，批次三純查詢版）
 *
 * 資料由結算引擎批次寫入，這裡直接以 Model 造列驗證查詢面：
 * 列表欄位/篩選/分頁、總代隔離、詳情含計算快照、無寫入端點。
 *
 * php artisan test tests/Feature/Agent/GgrPayoutRecord/CrudTest.php
 */
class CrudTest extends TestCase
{
    private Agent $agent;

    private GgrPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);

        $this->plan = GgrPlan::create([
            'general_agent_id' => $this->agent->id,
            'name' => 'plan for settlement',
            'settlement_cycle' => 1,
            'agent_group_id' => 1,
            'start_date' => '2026-09-01',
            'downline_vendor_setting_id' => 1,
            'downline_condition_id' => 1,
            'created_by' => $this->agent->id,
            'admin' => $this->agent->id,
        ]);
    }

    private function act(string $action, array $payload = []): TestResponse
    {
        return $this->postJson('agent/api/ggr/payout-record/' . $action, $payload, $this->headers);
    }

    /** 造一筆結算列（引擎落地格式）。 */
    private function createSettlement(array $overrides = []): GgrSettlement
    {
        return GgrSettlement::create(array_merge([
            'general_agent_id' => $this->agent->id,
            'plan_id' => $this->plan->id,
            'agent_id' => $this->agent->id,
            'settlement_cycle' => 1,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-01',
            'ggr_amount' => '1000.00',
            'bonus_rate' => '10.00',
            'ggr_rate' => '60.00',
            'condition_rate' => '5.00',
            'admin_fee_rate' => '2.00',
            'handling_fee' => '10.00',
            'final_amount' => '7.00',
            'carry_in_amount' => '0.00',
            'carry_out_amount' => '0.00',
            'calc_snapshot' => ['qualified_members' => 3, 'matched_tier' => 2],
            'payout_status' => 0,
        ], $overrides));
    }

    public function test_list_returns_fields_and_paginates(): void
    {
        $this->createSettlement();
        $this->createSettlement(['period_start' => '2026-09-02', 'period_end' => '2026-09-02']);

        $res = $this->act('list', ['page' => 1, 'per_page' => 20])
            ->assertStatus(200)
            ->assertJson(['code' => 200]);

        $items = $res->json('data.items');
        $this->assertCount(2, $items);
        $this->assertSame(2, $res->json('data.pagination.total'));

        $row = collect($items)->firstWhere('period_start', '2026-09-01');
        $this->assertSame($this->agent->username, $row['agent_username']);
        $this->assertSame('1000.00', $row['ggr_amount']);
        $this->assertSame('5.00', $row['condition_rate']);
        $this->assertSame('7.00', $row['final_amount']);
        $this->assertSame(0, (int) $row['carry']);
        $this->assertSame(0, (int) $row['payout_status']);
        // list 不回傳計算快照（減量，詳情才給）
        $this->assertNull($row['calc_snapshot'] ?? null);
    }

    public function test_list_filters(): void
    {
        $this->createSettlement();
        $this->createSettlement([
            'period_start' => '2026-09-02',
            'period_end' => '2026-09-02',
            'carry_out_amount' => '-500.00',
            'payout_status' => 1,
            'paid_at' => '2026-09-03 00:00:00',
        ]);

        // 結轉狀態
        $res = $this->act('list', ['page' => 1, 'per_page' => 20, 'carry' => 1]);
        $this->assertCount(1, $res->json('data.items'));
        $this->assertSame('2026-09-02', $res->json('data.items.0.period_start'));

        // 發放狀態
        $res = $this->act('list', ['page' => 1, 'per_page' => 20, 'payout_status' => 0]);
        $this->assertCount(1, $res->json('data.items'));
        $this->assertSame('2026-09-01', $res->json('data.items.0.period_start'));

        // 期間（對 period_start）
        $res = $this->act('list', ['page' => 1, 'per_page' => 20, 'start_date' => '2026-09-02', 'end_date' => '2026-09-02']);
        $this->assertCount(1, $res->json('data.items'));

        // 代理帳號關鍵字
        $res = $this->act('list', ['page' => 1, 'per_page' => 20, 'agent_username' => $this->agent->username]);
        $this->assertCount(2, $res->json('data.items'));
        $res = $this->act('list', ['page' => 1, 'per_page' => 20, 'agent_username' => 'no-such-agent']);
        $this->assertCount(0, $res->json('data.items'));
    }

    public function test_list_invalid_filters_rejected(): void
    {
        $this->act('list', ['page' => 1, 'per_page' => 20, 'carry' => 2])->assertJson(['code' => 400]);
        $this->act('list', ['page' => 1, 'per_page' => 20, 'payout_status' => 9])->assertJson(['code' => 400]);
        $this->act('list', ['page' => 1, 'per_page' => 20, 'start_date' => '2026-09-02', 'end_date' => '2026-09-01'])->assertJson(['code' => 400]);
    }

    public function test_view_returns_calc_snapshot(): void
    {
        $settlement = $this->createSettlement();

        $res = $this->act('view', ['id' => $settlement->id])
            ->assertStatus(200)
            ->assertJson(['code' => 200]);

        $this->assertSame('plan for settlement', $res->json('data.plan_name'));
        $this->assertSame(3, $res->json('data.calc_snapshot.qualified_members'));
        $this->assertSame(2, $res->json('data.calc_snapshot.matched_tier'));
    }

    public function test_other_general_agent_settlement_is_invisible(): void
    {
        // 隔離只看 general_agent_id 欄位值，不需要真的建另一個總代
        $settlement = $this->createSettlement(['general_agent_id' => $this->agent->id + 1]);

        $this->assertCount(0, $this->act('list', ['page' => 1, 'per_page' => 20])->json('data.items'));
        $this->act('view', ['id' => $settlement->id])->assertJson(['code' => 400]);
    }

    public function test_write_endpoints_not_exposed(): void
    {
        // 批次三純查詢：資料由結算引擎寫入，不開任何寫入端點
        $this->act('create', [])->assertStatus(404);
        $this->act('update', [])->assertStatus(404);
        $this->act('delete', ['id' => 1])->assertStatus(404);
    }
}
