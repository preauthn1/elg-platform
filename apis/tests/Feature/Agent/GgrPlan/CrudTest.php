<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\GgrPlan;

use App\Enums\Agent\AgentGroupTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentGroup;
use App\Models\GgrCondition;
use App\Models\GgrConditionTier;
use App\Models\GgrPlan;
use App\Models\GgrPlanSpecialAgent;
use App\Models\GgrVendorSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 推廣代理GGR - 方案設定 後台 CRUD（4121）
 *
 * php artisan test tests/Feature/Agent/GgrPlan/CrudTest.php
 */
class CrudTest extends TestCase
{
    private Agent $agent;

    private Agent $downlineAgent;

    private AgentGroup $agentGroup;

    private GgrVendorSetting $vendorSetting;

    private GgrCondition $condition;

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

        $this->createBindings();
    }

    /** 建立方案綁定素材：個性化組別、啟用中的遊戲商設定/條件設定、總代線內的下線代理。 */
    private function createBindings(): void
    {
        $this->agentGroup = AgentGroup::create([
            'agent_group_type' => AgentGroupTypeEnum::CUSTOMIZED_SETTING->value,
            'organization_id' => 1,
            'name' => 'ggr plan group',
            'memo' => '',
            'is_default' => 0,
        ]);

        $this->vendorSetting = GgrVendorSetting::create([
            'general_agent_id' => $this->agent->id,
            'name' => 'vs for plan',
            'status' => 1,
            'created_by' => $this->agent->id,
            'admin' => $this->agent->id,
        ]);

        $this->condition = GgrCondition::create([
            'general_agent_id' => $this->agent->id,
            'name' => 'cond for plan',
            'logic_type' => 1,
            'active_user_only' => 0,
            'status' => 1,
            'created_by' => $this->agent->id,
            'admin' => $this->agent->id,
        ]);
        GgrConditionTier::create([
            'condition_id' => $this->condition->id,
            'tier' => 1,
            'user_count' => 1,
            'deposit_amount' => '0',
            'total_bet_amount' => '0',
            'game_revenue_amount' => '0',
            'valid_bet_amount' => '0',
            'percentage' => '1.00',
            'admin' => $this->agent->id,
        ]);

        $this->downlineAgent = $this->createLineAgent($this->agent->hierarchy_path);
    }

    /** 建立掛在指定 hierarchy 前綴下的一般代理（不建 domain，避免撞 agent_domains unique）。 */
    private function createLineAgent(string $parentPath): Agent
    {
        $unique = bin2hex(random_bytes(8));
        $agent = Agent::factory()->create([
            'code' => substr($unique, 0, 10),
            'username' => 'd' . substr($unique, 0, 15),
            'email' => substr($unique, 0, 12) . '@t.ag',
            'status' => 1,
            'role_id' => 21,
        ]);
        $agent->hierarchy_path = $parentPath . '/' . $agent->id;
        $agent->hierarchy = "[-1,{$agent->id},-2]";
        $agent->save();

        return $agent;
    }

    private function act(string $action, array $payload = []): TestResponse
    {
        return $this->postJson('agent/api/ggr/plan/' . $action, $payload, $this->headers);
    }

    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'monthly ggr plan',
            'status' => 1,
            'settlement_cycle' => 3,
            'agent_group_id' => $this->agentGroup->id,
            // 開始日期有「不得早於今日業務日」下限，寫死日期會隨時間滑出區間
            'start_date' => now()->toBackendTime()->toDateString(),
            'carry_negative' => 1,
            'admin_fee_rate' => '5.00',
            'handling_fee' => '10.00',
            'ggr_rate' => '60.00',
            'bonus_rate' => '10.00',
            'kyc_unique_bank_account' => 1,
            'kyc_unique_contact_no' => 0,
            'kyc_unique_email' => 1,
            'kyc_unique_ip' => 0,
            'kyc_unique_name' => 0,
            'kyc_violate_count' => 2,
            'downline_vendor_setting_id' => $this->vendorSetting->id,
            'downline_condition_id' => $this->condition->id,
            'special_agents' => [
                [
                    'agent_id' => $this->downlineAgent->id,
                    'vendor_setting_id' => $this->vendorSetting->id,
                    'condition_id' => $this->condition->id,
                ],
            ],
        ], $overrides);
    }

    public function test_create_plan_with_special_agents(): void
    {
        $this->act('create', $this->createPayload())->assertStatus(200)->assertJson(['code' => 200]);

        $plan = GgrPlan::where('name', 'monthly ggr plan')->first();
        $this->assertNotNull($plan);
        $this->assertSame($this->agent->id, (int) $plan->general_agent_id);
        $this->assertSame(3, (int) $plan->settlement_cycle);
        $this->assertSame(1, (int) $plan->carry_negative);
        $this->assertSame('5.00', (string) $plan->admin_fee_rate);
        $this->assertSame(1, (int) $plan->kyc_unique_bank_account);
        $this->assertSame(0, (int) $plan->kyc_unique_contact_no);
        $this->assertSame($this->vendorSetting->id, (int) $plan->downline_vendor_setting_id);
        $this->assertSame($this->condition->id, (int) $plan->downline_condition_id);
        $this->assertSame($this->agent->id, (int) $plan->created_by);

        $specials = GgrPlanSpecialAgent::where('plan_id', $plan->id)->get();
        $this->assertCount(1, $specials);
        $this->assertSame($this->downlineAgent->id, (int) $specials[0]->agent_id);
    }

    public function test_create_invalid_inputs_rejected(): void
    {
        // 週期非法
        $this->act('create', $this->createPayload(['settlement_cycle' => 4]))->assertJson(['code' => 400]);

        // 費率超過 100 / 負數 / 超過兩位小數
        $this->act('create', $this->createPayload(['admin_fee_rate' => '100.01']))->assertJson(['code' => 400]);
        $this->act('create', $this->createPayload(['ggr_rate' => '-1']))->assertJson(['code' => 400]);
        $this->act('create', $this->createPayload(['bonus_rate' => '1.234']))->assertJson(['code' => 400]);

        // 開關非法
        $this->act('create', $this->createPayload(['kyc_unique_ip' => 2]))->assertJson(['code' => 400]);

        // 日期格式非法
        $this->act('create', $this->createPayload(['start_date' => '2026/09/01']))->assertJson(['code' => 400]);

        $this->assertSame(0, GgrPlan::count());
    }

    public function test_create_count_cannot_exceed_enabled_switches(): void
    {
        // 只開 2 個唯一限制，違規次數要 3 → 擋
        $this->act('create', $this->createPayload(['kyc_violate_count' => 3]))->assertJson(['code' => 400]);

        // 開關全關卻要求次數 → 擋
        $this->act('create', $this->createPayload([
            'kyc_unique_bank_account' => 0,
            'kyc_unique_email' => 0,
            'kyc_violate_count' => 1,
        ]))->assertJson(['code' => 400]);

        $this->assertSame(0, GgrPlan::count());

        // 邊界：次數等於開啟數可過
        $this->act('create', $this->createPayload([
            'kyc_unique_ip' => 1,
            'kyc_violate_count' => 3,
        ]))->assertJson(['code' => 200]);
    }

    public function test_create_zero_count_allowed_even_when_all_switches_off(): void
    {
        // 0＝不設限，開關全關也合法
        $this->act('create', $this->createPayload([
            'kyc_unique_bank_account' => 0,
            'kyc_unique_email' => 0,
            'kyc_violate_count' => 0,
        ]))->assertJson(['code' => 200]);
    }

    public function test_update_count_cannot_exceed_enabled_switches(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $planId = GgrPlan::where('name', 'monthly ggr plan')->value('id');

        $this->act('update', $this->createPayload([
            'id' => $planId,
            'kyc_violate_count' => 4,
        ]))->assertJson(['code' => 400]);

        $this->assertSame(2, (int) GgrPlan::find($planId)->kyc_violate_count);
    }

    public function test_create_agent_group_must_be_customized_setting_type(): void
    {
        $paymentGroup = AgentGroup::create([
            'agent_group_type' => AgentGroupTypeEnum::CASH_FLOW->value,
            'organization_id' => 1,
            'name' => 'payment group',
            'memo' => '',
            'is_default' => 0,
        ]);

        $this->act('create', $this->createPayload(['agent_group_id' => $paymentGroup->id]))
            ->assertJson(['code' => 400]);
        $this->assertSame(0, GgrPlan::count());
    }

    /**
     * 開始日期不得回填過去：回填會讓首輪一次補出全部歷史期間並在下一輪發放入帳，
     * 而已發放的列結算重跑不覆蓋、帳變無沖銷型別，錯誤金額無法經系統回收。
     */
    public function test_back_dated_start_date_rejected(): void
    {
        $yesterday = now()->toBackendTime()->subDay()->toDateString();
        $this->act('create', $this->createPayload(['start_date' => $yesterday]))->assertJson(['code' => 400]);
        $this->assertSame(0, GgrPlan::count());

        // 今日與未來可過
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $this->act('update', array_merge($this->createPayload([
            'start_date' => now()->toBackendTime()->addMonth()->toDateString(),
        ]), ['id' => (int) GgrPlan::value('id')]))->assertJson(['code' => 200]);
    }

    /** 已開跑的方案沿用原日期可過（日期本來就在過去），只擋「改成更早的日期」 */
    public function test_update_keeps_existing_past_start_date(): void
    {
        $startDate = now()->toBackendTime()->toDateString();
        $this->act('create', $this->createPayload(['start_date' => $startDate]))->assertJson(['code' => 200]);
        $id = (int) GgrPlan::value('id');

        // 三天後：原本的開始日期已成過去
        $this->travelTo(now()->addDays(3));

        $this->act('update', array_merge($this->createPayload([
            'name' => 'renamed',
            'start_date' => $startDate,
        ]), ['id' => $id]))->assertJson(['code' => 200], '沿用原開始日期不該被下限擋下');
        $this->assertSame('renamed', GgrPlan::find($id)->name);

        // 但不可再往前挪
        $earlier = now()->toBackendTime()->subDays(5)->toDateString();
        $this->act('update', array_merge($this->createPayload([
            'start_date' => $earlier,
        ]), ['id' => $id]))->assertJson(['code' => 400]);
        $this->assertSame($startDate, Carbon::parse(GgrPlan::find($id)->start_date)->toDateString());
    }

    /** 同總代同群組同時只能有一個啟用方案（carry 鏈跨方案跟代理走，兩鏈會雙重沖抵同一筆負餘額） */
    public function test_second_active_plan_on_same_group_rejected(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $planId = (int) GgrPlan::where('name', 'monthly ggr plan')->value('id');

        $this->act('create', $this->createPayload(['name' => 'second active plan']))->assertJson(['code' => 400]);
        $this->assertSame(1, GgrPlan::count());

        // 停用中的第二個方案可建
        $this->act('create', $this->createPayload(['name' => 'disabled plan', 'status' => 0]))->assertJson(['code' => 200]);
        $disabledId = (int) GgrPlan::where('name', 'disabled plan')->value('id');

        // update 自己不算撞到自己
        $this->act('update', array_merge($this->createPayload(['name' => 'renamed first']), ['id' => $planId]))
            ->assertJson(['code' => 200]);

        // 把第二個啟用則被擋；先停用第一個再啟用第二個可過
        $this->act('update', array_merge($this->createPayload(['name' => 'disabled plan', 'status' => 1]), ['id' => $disabledId]))
            ->assertJson(['code' => 400]);
        $this->act('update', array_merge($this->createPayload(['name' => 'renamed first', 'status' => 0]), ['id' => $planId]))
            ->assertJson(['code' => 200]);
        $this->act('update', array_merge($this->createPayload(['name' => 'disabled plan', 'status' => 1]), ['id' => $disabledId]))
            ->assertJson(['code' => 200]);
    }

    public function test_create_binding_of_other_general_agent_rejected(): void
    {
        $foreignSetting = GgrVendorSetting::create([
            'general_agent_id' => $this->agent->id + 1000,
            'name' => 'foreign vs',
            'status' => 1,
            'created_by' => 0,
            'admin' => 0,
        ]);

        $this->act('create', $this->createPayload(['downline_vendor_setting_id' => $foreignSetting->id]))
            ->assertJson(['code' => 400]);
        $this->assertSame(0, GgrPlan::count());
    }

    public function test_create_disabled_binding_rejected_but_update_allows_it(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $planId = (int) GgrPlan::where('name', 'monthly ggr plan')->value('id');

        // 停用後 create 不可新選
        $this->vendorSetting->update(['status' => 0]);
        $this->act('create', $this->createPayload(['name' => 'another plan']))->assertJson(['code' => 400]);

        // 但既有方案 update 沿用停用中的綁定可過（停用不斷鏈）
        $this->act('update', array_merge($this->createPayload(['name' => 'renamed plan']), ['id' => $planId]))
            ->assertJson(['code' => 200]);
        $this->assertSame('renamed plan', GgrPlan::find($planId)->name);
    }

    public function test_create_special_agent_outside_line_rejected(): void
    {
        // 不在總代線內的代理（獨立 hierarchy）
        $outsider = $this->createLineAgent('/1/999999');

        $payload = $this->createPayload(['special_agents' => [[
            'agent_id' => $outsider->id,
            'vendor_setting_id' => $this->vendorSetting->id,
            'condition_id' => $this->condition->id,
        ]]]);

        $this->act('create', $payload)->assertJson(['code' => 400]);
        $this->assertSame(0, GgrPlan::count());
    }

    public function test_create_duplicate_special_agent_rejected(): void
    {
        $row = [
            'agent_id' => $this->downlineAgent->id,
            'vendor_setting_id' => $this->vendorSetting->id,
            'condition_id' => $this->condition->id,
        ];

        $this->act('create', $this->createPayload(['special_agents' => [$row, $row]]))
            ->assertJson(['code' => 400]);
        $this->assertSame(0, GgrPlan::count());
    }

    public function test_list_returns_fields_and_filters(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $this->act('create', $this->createPayload(['name' => 'daily plan', 'settlement_cycle' => 1, 'status' => 0]))
            ->assertJson(['code' => 200]);

        $response = $this->act('list', ['page' => 1, 'per_page' => 20]);
        $response->assertJson(['code' => 200]);
        $row = collect($response->json('data.items'))->firstWhere('name', 'monthly ggr plan');
        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row['settlement_cycle']);
        $this->assertSame(1, (int) $row['special_agents_count']);
        $this->assertSame($this->agent->username, $row['created_by']);

        $byCycle = $this->act('list', ['page' => 1, 'per_page' => 20, 'settlement_cycle' => 1]);
        $this->assertCount(1, $byCycle->json('data.items'));
        $this->assertSame('daily plan', $byCycle->json('data.items.0.name'));

        $byStatus = $this->act('list', ['page' => 1, 'per_page' => 20, 'status' => 0]);
        $this->assertCount(1, $byStatus->json('data.items'));
    }

    public function test_view_returns_bindings_and_special_agents(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) GgrPlan::where('name', 'monthly ggr plan')->value('id');

        $view = $this->act('view', ['id' => $id]);
        $view->assertJson(['code' => 200]);
        $this->assertSame('vs for plan', $view->json('data.downline_vendor_setting_name'));
        $this->assertSame('cond for plan', $view->json('data.downline_condition_name'));

        $specials = $view->json('data.special_agents');
        $this->assertCount(1, $specials);
        $this->assertSame($this->downlineAgent->username, $specials[0]['agent_username']);
        $this->assertSame('vs for plan', $specials[0]['vendor_setting_name']);
    }

    public function test_view_missing_rejected(): void
    {
        $this->act('view', ['id' => 999999])->assertJson(['code' => 400]);
    }

    public function test_update_replaces_special_agents(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) GgrPlan::where('name', 'monthly ggr plan')->value('id');
        $oldRowIds = GgrPlanSpecialAgent::where('plan_id', $id)->pluck('id')->all();

        $this->act('update', array_merge($this->createPayload([
            'name' => 'updated plan',
            'settlement_cycle' => 2,
            'special_agents' => [],
        ]), ['id' => $id]))->assertJson(['code' => 200]);

        $plan = GgrPlan::find($id);
        $this->assertSame('updated plan', $plan->name);
        // 週期切換只對下一期生效：現行值不變、寫入待生效欄，引擎結算完當期後 promote
        $this->assertSame(3, (int) $plan->settlement_cycle);
        $this->assertSame(2, (int) $plan->pending_settlement_cycle);

        // 全量替換：舊列消失
        $this->assertSame(0, GgrPlanSpecialAgent::where('plan_id', $id)->count());
        $this->assertNotEmpty($oldRowIds);

        // view 回設定端視角（待生效值）；改回原值則取消待生效變更
        $this->assertSame(2, $this->act('view', ['id' => $id])->json('data.settlement_cycle'));
        $this->act('update', array_merge($this->createPayload([
            'name' => 'updated plan',
            'settlement_cycle' => 3,
            'special_agents' => [],
        ]), ['id' => $id]))->assertJson(['code' => 200]);
        $plan->refresh();
        $this->assertSame(3, (int) $plan->settlement_cycle);
        $this->assertNull($plan->pending_settlement_cycle);
    }

    public function test_other_general_agent_plan_is_invisible(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $mine = (int) GgrPlan::where('name', 'monthly ggr plan')->value('id');

        $foreign = GgrPlan::create([
            'general_agent_id' => $this->agent->id + 1000,
            'name' => 'foreign plan',
            'settlement_cycle' => 1,
            'agent_group_id' => $this->agentGroup->id,
            'start_date' => '2026-09-01',
            'downline_vendor_setting_id' => $this->vendorSetting->id,
            'downline_condition_id' => $this->condition->id,
            'status' => 1,
            'created_by' => 0,
            'admin' => 0,
        ]);

        $items = $this->act('list', ['page' => 1, 'per_page' => 20])->json('data.items');
        $this->assertSame([$mine], array_map('intval', array_column($items, 'id')));

        $this->act('view', ['id' => $foreign->id])->assertJson(['code' => 400]);
        $this->act('update', array_merge($this->createPayload(), ['id' => $foreign->id]))->assertJson(['code' => 400]);
    }

    public function test_dropdowns_return_only_active_of_own_general_agent(): void
    {
        // 停用項與他總代項都不得出現
        GgrVendorSetting::create([
            'general_agent_id' => $this->agent->id,
            'name' => 'disabled vs',
            'status' => 0,
            'created_by' => 0,
            'admin' => 0,
        ]);
        GgrCondition::create([
            'general_agent_id' => $this->agent->id + 1000,
            'name' => 'foreign cond',
            'logic_type' => 1,
            'active_user_only' => 0,
            'status' => 1,
            'created_by' => 0,
            'admin' => 0,
        ]);

        $vendorRows = $this->act('vendor-setting-dropdown')->assertJson(['code' => 200])->json('data');
        $this->assertSame(['vs for plan'], array_column($vendorRows, 'name'));

        // max_percentage 取最高階（有效分潤率試算用）：補一個更高階驗證取的是 MAX
        GgrConditionTier::create([
            'condition_id' => $this->condition->id,
            'tier' => 2,
            'user_count' => 5,
            'deposit_amount' => '0',
            'total_bet_amount' => '0',
            'game_revenue_amount' => '0',
            'valid_bet_amount' => '0',
            'percentage' => '7.50',
            'admin' => $this->agent->id,
        ]);

        $conditionRows = $this->act('condition-dropdown')->assertJson(['code' => 200])->json('data');
        $this->assertSame(['cond for plan'], array_column($conditionRows, 'name'));
        $this->assertSame('7.50', $conditionRows[0]['max_percentage']);
    }

    public function test_delete_endpoint_not_exposed(): void
    {
        $this->act('create', $this->createPayload())->assertJson(['code' => 200]);
        $id = (int) GgrPlan::where('name', 'monthly ggr plan')->value('id');

        $this->act('delete', ['id' => $id])->assertStatus(404);
        $this->assertNotNull(GgrPlan::find($id));
    }
}
