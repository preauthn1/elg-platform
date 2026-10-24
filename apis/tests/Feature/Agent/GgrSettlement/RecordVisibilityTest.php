<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\GgrSettlement;

use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\Agent\GgrSettlementCycleEnum;
use App\Enums\Agent\RoleEnum;
use App\Enums\GgrPayoutStatusEnum;
use App\Enums\StatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\GgrPlan;
use App\Models\GgrSettlement;
use App\Services\Agent\GgrSettlementService;
use Tests\TestCase;
use Throwable;

/**
 * 4125 派發紀錄可見性（list / view）：只看得到「自己 + 線內所有下線」。
 *
 * 本頁選單不只發給總代，中層代理也拿得到；單靠 general_agent_id 隔離會讓
 * 中層代理讀到上層與旁系的分潤金額，view 端點更可被直接帶 id 撈走完整
 * 計算快照。這裡不跑結算引擎，直接造 ggr_settlements 列驗可見範圍。
 *
 * 階層：general ─┬─ mid ── child ── grandChild
 *                └─ sibling（旁系）
 */
class RecordVisibilityTest extends TestCase
{
    private Agent $general;

    private Agent $mid;

    private Agent $child;

    private Agent $grandChild;

    private Agent $sibling;

    private GgrPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->general = $this->createAgentAndDomain();
        $this->plan = $this->makePlan();

        $this->mid = $this->makeAgentUnder($this->general);
        $this->child = $this->makeAgentUnder($this->mid);
        $this->grandChild = $this->makeAgentUnder($this->child);
        $this->sibling = $this->makeAgentUnder($this->general);
    }

    public function test_list_contains_own_and_all_downline_records(): void
    {
        $own = $this->makeSettlement($this->mid->id);
        $child = $this->makeSettlement($this->child->id);
        $grandChild = $this->makeSettlement($this->grandChild->id);

        $ids = $this->listIds($this->mid);

        $this->assertContains($own->id, $ids, '看得到自己那一筆');
        $this->assertContains($child->id, $ids, '看得到直屬下線');
        $this->assertContains($grandChild->id, $ids, '看得到隔代下線');
    }

    public function test_list_excludes_upline_record(): void
    {
        $upline = $this->makeSettlement($this->general->id);
        $own = $this->makeSettlement($this->mid->id);

        $ids = $this->listIds($this->mid);

        $this->assertNotContains($upline->id, $ids, '上層總代的分潤不可外洩給下線');
        $this->assertSame([$own->id], $ids);
    }

    public function test_list_excludes_sibling_branch_record(): void
    {
        $own = $this->makeSettlement($this->mid->id);
        $sibling = $this->makeSettlement($this->sibling->id);

        // 前綴相同但不同層的帳號（如 /G/M 之於 /G/M0）不得被 like 誤中
        $lookalike = $this->makeAgentUnder($this->general);
        $lookalike->hierarchy_path = $this->mid->hierarchy_path . '0';
        $lookalike->save();
        $lookalikeSettlement = $this->makeSettlement($lookalike->id);

        $ids = $this->listIds($this->mid);

        $this->assertNotContains($sibling->id, $ids, '同總代但不同分支不可互看');
        $this->assertNotContains($lookalikeSettlement->id, $ids, 'like 必須帶結尾斜線');
        $this->assertSame([$own->id], $ids);
    }

    public function test_view_rejects_sibling_branch_record(): void
    {
        $sibling = $this->makeSettlement($this->sibling->id);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1010'));

        app(GgrSettlementService::class)->find($this->mid, $sibling->id);
    }

    public function test_view_allows_own_and_downline_record(): void
    {
        $own = $this->makeSettlement($this->mid->id);
        $grandChild = $this->makeSettlement($this->grandChild->id);

        $service = app(GgrSettlementService::class);

        $this->assertSame($own->id, $service->find($this->mid, $own->id)->id);
        $this->assertSame($grandChild->id, $service->find($this->mid, $grandChild->id)->id, '隔代下線可檢視');
    }

    /**
     * hierarchy_path 缺漏＝資料不完整：整頁拒絕，絕不退回「整條總代線都看得到」。
     *
     * general_agent_id 也是由 hierarchy_path 推導，所以缺漏時 resolveGeneralAgentId
     * 會比 scopeToOwnLine 更早擋下——空字串拋 ApiException，NULL 則是 accessor
     * 的 substr(null) 直接 TypeError（既有缺陷，非本次改動引入）。兩者都看不到
     * 任何紀錄，scopeToOwnLine 的降級分支僅作為第二道防線保留。
     */
    public function test_agent_without_hierarchy_path_cannot_see_any_record(): void
    {
        $orphan = $this->makeAgentUnder($this->general);
        $this->makeSettlement($orphan->id);
        $this->makeSettlement($this->general->id);
        $this->makeSettlement($this->sibling->id);

        $orphan->hierarchy_path = '';
        $orphan->save();
        $this->assertThrows(fn () => $this->listIds($orphan), ApiException::class);

        $orphan->hierarchy_path = null;
        $orphan->save();
        $this->assertThrows(fn () => $this->listIds($orphan), Throwable::class);
    }

    /** 總代自己不受影響：整條線（含各層下線）照舊看得到 */
    public function test_general_agent_still_sees_whole_line(): void
    {
        $expected = [
            $this->makeSettlement($this->general->id)->id,
            $this->makeSettlement($this->mid->id)->id,
            $this->makeSettlement($this->child->id)->id,
            $this->makeSettlement($this->grandChild->id)->id,
            $this->makeSettlement($this->sibling->id)->id,
        ];
        sort($expected);

        $ids = $this->listIds($this->general);
        sort($ids);

        $this->assertSame($expected, $ids);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<int, int>
     */
    private function listIds(Agent $agent): array
    {
        return app(GgrSettlementService::class)
            ->paginate($agent, ['per_page' => 100])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function makeAgentUnder(Agent $parent): Agent
    {
        $unique = bin2hex(random_bytes(8));
        $agent = Agent::factory()->create([
            'code' => substr($unique, 0, 10),
            'username' => 'v' . substr($unique, 0, 15),
            'email' => substr($unique, 0, 12) . '@t.ag',
            'parent' => $parent->id,
            'status' => StatusEnum::ENABLE->value,
            'role_id' => RoleEnum::AGENT->value,
        ]);
        $agent->hierarchy_path = $parent->hierarchy_path . '/' . $agent->id;
        $agent->hierarchy = "[-1,{$agent->id},-2]";
        $agent->save();

        return $agent;
    }

    private function makePlan(): GgrPlan
    {
        $group = AgentGroup::create([
            'agent_group_type' => AgentGroupTypeEnum::CUSTOMIZED_SETTING->value,
            'organization_id' => 1,
            'name' => 'visibility group ' . bin2hex(random_bytes(3)),
            'memo' => '',
            'is_default' => 0,
        ]);

        return GgrPlan::create([
            'general_agent_id' => $this->general->id,
            'name' => 'visibility plan ' . bin2hex(random_bytes(4)),
            'settlement_cycle' => GgrSettlementCycleEnum::DAILY->value,
            'agent_group_id' => $group->id,
            'start_date' => '2026-08-01',
            'carry_negative' => 0,
            'admin_fee_rate' => '0.00', 'handling_fee' => '0.00', 'ggr_rate' => '100.00', 'bonus_rate' => '0.00',
            'kyc_unique_bank_account' => 0, 'kyc_unique_contact_no' => 0, 'kyc_unique_email' => 0,
            'kyc_unique_ip' => 0, 'kyc_unique_name' => 0, 'kyc_violate_count' => 0,
            'downline_vendor_setting_id' => 0,
            'downline_condition_id' => 0,
            'status' => StatusEnum::ENABLE->value,
            'created_by' => $this->general->id,
            'admin' => $this->general->id,
        ]);
    }

    private function makeSettlement(int $agentId): GgrSettlement
    {
        return GgrSettlement::create([
            'general_agent_id' => $this->general->id,
            'plan_id' => $this->plan->id,
            'agent_id' => $agentId,
            'settlement_cycle' => GgrSettlementCycleEnum::DAILY->value,
            'period_start' => '2026-08-20',
            'period_end' => '2026-08-20',
            'ggr_amount' => '0.00',
            'bonus_rate' => '0.00', 'ggr_rate' => '100.00', 'condition_rate' => '100.00', 'admin_fee_rate' => '0.00',
            'handling_fee' => '0.00',
            'final_amount' => '100.00',
            'payable_amount' => '100.00',
            'carry_in_amount' => '0.00',
            'carry_out_amount' => '0.00',
            'calc_snapshot' => [],
            'payout_status' => GgrPayoutStatusEnum::UNPAID->value,
            'admin' => null,
        ]);
    }
}
