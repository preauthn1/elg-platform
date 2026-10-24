<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\Agent\GgrSettlementCycleEnum;
use App\Enums\StatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\GgrCondition;
use App\Models\GgrPlan;
use App\Models\GgrPlanSpecialAgent;
use App\Models\GgrVendorSetting;
use App\Services\Agent\Traits\ResolvesGgrGeneralAgent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 推廣代理 GGR 方案（Agent 面向 CRUD，4121）。
 *
 * 一份方案＝主表一筆（含成員下線設置的兩顆綁定 FK）＋特殊用戶 override 多列。
 * 批次三結算/4125 以主表 id 為引用契約：無 delete（停用靠 status）、
 * override 採 transaction 內全量替換（列 id 不對外引用）。
 *
 * 綁定完整性：引用的遊戲商設定/條件設定必須屬同總代；create 時要求啟用中，
 * update 放行已停用的既有引用（PM 定案：停用不斷鏈，僅不可新選）。
 * 特殊用戶的代理必須在總代線內（hierarchy 節點邊界檢查）。
 *
 * 本功能僅總代使用，general_agent_id 為可見性軸（同 4123/4124）。
 */
class GgrPlanService
{
    use ResolvesGgrGeneralAgent;

    public function paginate(Agent $agent, array $filters): LengthAwarePaginator
    {
        return GgrPlan::query()
            ->with(['creator:id,username', 'updater:id,username'])
            ->withCount('specialAgents')
            ->where('general_agent_id', $this->resolveGeneralAgentId($agent))
            ->when(!empty($filters['name']), fn ($q) => $q->where('name', 'like', '%' . $filters['name'] . '%'))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['settlement_cycle']), fn ($q) => $q->where('settlement_cycle', $filters['settlement_cycle']))
            ->orderBy('id', 'desc')
            ->paginate($filters['per_page']);
    }

    /** 檢視單筆（非本總代或查無皆拋 1010）：主表＋綁定名稱＋特殊用戶列。 */
    public function find(Agent $agent, int $id): GgrPlan
    {
        $plan = GgrPlan::query()
            ->with([
                'creator:id,username',
                'updater:id,username',
                'downlineVendorSetting:id,name',
                'downlineCondition:id,name',
                'specialAgents.agent:id,username',
                'specialAgents.vendorSetting:id,name',
                'specialAgents.condition:id,name',
            ])
            ->where('general_agent_id', $this->resolveGeneralAgentId($agent))
            ->where('id', $id)
            ->first();

        if (!$plan) {
            throw new ApiException(__('error_message.1010'));
        }

        return $plan;
    }

    public function create(Agent $agent, array $data): GgrPlan
    {
        $generalAgentId = $this->resolveGeneralAgentId($agent);
        $this->assertBindings($agent, $generalAgentId, $data, requireActive: true);

        return DB::transaction(function () use ($agent, $generalAgentId, $data) {
            $plan = GgrPlan::create([
                'general_agent_id' => $generalAgentId,
                ...$this->planColumns($data),
                'created_by' => $agent->id,
                'admin' => $agent->id,
            ]);

            $this->insertSpecialAgents($plan, $agent, $data['special_agents'] ?? []);

            return $plan;
        });
    }

    /** 更新主表（非本總代或查無皆拋 1010）並全量替換特殊用戶列。 */
    public function update(Agent $agent, int $id, array $data): GgrPlan
    {
        $generalAgentId = $this->resolveGeneralAgentId($agent);
        $this->assertBindings($agent, $generalAgentId, $data, requireActive: false, excludePlanId: $id);

        return DB::transaction(function () use ($agent, $generalAgentId, $id, $data) {
            $plan = GgrPlan::query()
                ->where('general_agent_id', $generalAgentId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$plan) {
                throw new ApiException(__('error_message.1010'));
            }

            $columns = $this->planColumns($data);

            // 結算週期切換只對下一期生效（2026-09-01 工單 4121 定案）：
            // 變更時寫 pending＋生效日（切換當下當期的下一期起點），不動現行值，
            // 引擎結算推進到生效日時 promote；改回原值則取消待生效變更。
            // 方案尚未開跑（start_date 在今天之後）時無「當期」，直接改現行值。
            $today = now()->toBackendTime()->startOfDay();
            if ((int) $columns['settlement_cycle'] !== (int) $plan->settlement_cycle
                && $plan->start_date <= $today->toDateString()) {
                $currentCycle = GgrSettlementCycleEnum::from((int) $plan->settlement_cycle);
                $columns['pending_settlement_cycle'] = $columns['settlement_cycle'];
                $columns['pending_cycle_from'] = $currentCycle
                    ->periodEndFor($currentCycle->periodStartContaining($today))
                    ->addDay()
                    ->toDateString();
                $columns['settlement_cycle'] = $plan->settlement_cycle;
            } else {
                $columns['pending_settlement_cycle'] = null;
                $columns['pending_cycle_from'] = null;
            }

            $plan->fill([
                ...$columns,
                'admin' => $agent->id,
            ]);
            // 只改特殊用戶列時主表可能原封不動，Eloquent 便不會下 UPDATE；此處明確推進。
            $plan->updated_at = now();
            $plan->save();

            GgrPlanSpecialAgent::query()->where('plan_id', $plan->id)->delete();
            $this->insertSpecialAgents($plan, $agent, $data['special_agents'] ?? []);

            return $plan;
        });
    }

    /** 4121 下拉資料源：本總代啟用中的遊戲商設定（id+name）。 */
    public function vendorSettingDropdown(Agent $agent): array
    {
        return GgrVendorSetting::query()
            ->where('general_agent_id', $this->resolveGeneralAgentId($agent))
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->get(['id', 'name'])
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])
            ->all();
    }

    /**
     * 4121 下拉資料源：本總代啟用中的條件設定（id+name+最高階應得百分比）。
     *
     * max_percentage 供前端「有效分潤率」唯讀試算（2026-09-01 工單新增）——
     * 取最高階與結算取最高已達成階的邏輯一致；percentage 依階遞增，MAX 即最高階。
     */
    public function conditionDropdown(Agent $agent): array
    {
        return GgrCondition::query()
            ->withMax('tiers as max_percentage', 'percentage')
            ->where('general_agent_id', $this->resolveGeneralAgentId($agent))
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->get(['id', 'name'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'max_percentage' => (string) ($row->max_percentage ?? '0'),
            ])
            ->all();
    }

    /** 主表欄位映射（create/update 共用；general_agent_id 與稽核欄另行處理）。 */
    private function planColumns(array $data): array
    {
        return [
            'name' => $data['name'],
            'settlement_cycle' => $data['settlement_cycle'],
            'agent_group_id' => $data['agent_group_id'],
            'start_date' => $data['start_date'],
            'carry_negative' => $data['carry_negative'],
            'admin_fee_rate' => $data['admin_fee_rate'],
            'handling_fee' => $data['handling_fee'],
            'ggr_rate' => $data['ggr_rate'],
            'bonus_rate' => $data['bonus_rate'],
            'kyc_unique_bank_account' => $data['kyc_unique_bank_account'],
            'kyc_unique_contact_no' => $data['kyc_unique_contact_no'],
            'kyc_unique_email' => $data['kyc_unique_email'],
            'kyc_unique_ip' => $data['kyc_unique_ip'],
            'kyc_unique_name' => $data['kyc_unique_name'],
            'kyc_violate_count' => $data['kyc_violate_count'],
            'downline_vendor_setting_id' => $data['downline_vendor_setting_id'],
            'downline_condition_id' => $data['downline_condition_id'],
            'status' => $data['status'],
        ];
    }

    /**
     * 寫入前複驗（防繞過前端）：代理群組為個性化組別、同總代同群組同時只能有一個
     * 啟用中方案、綁定的設定屬同總代（create 另要求啟用中）、特殊用戶代理在總代線內。
     *
     * 個性化組別是全站共用（agent_groups 無總代歸屬），無法限定「總代可見」；
     * 結算引擎另以總代 hierarchy 線內過濾結算對象。
     */
    private function assertBindings(
        Agent $agent,
        int $generalAgentId,
        array $data,
        bool $requireActive,
        ?int $excludePlanId = null,
    ): void {
        $groupExists = AgentGroup::query()
            ->where('id', $data['agent_group_id'])
            ->where('agent_group_type', AgentGroupTypeEnum::CUSTOMIZED_SETTING->value)
            ->exists();
        if (!$groupExists) {
            throw new ApiException(__('error_message.ggr_setting.agent_group_invalid'));
        }

        // 遞延餘額（carry 鏈）跨方案跟代理走：同一群組若同時有兩個啟用方案，
        // 同一代理會被兩條鏈各沖抵一次同一筆負餘額，故同總代同群組僅允許一個啟用方案
        if ((int) $data['status'] === StatusEnum::ENABLE->value) {
            $groupInUse = GgrPlan::query()
                ->where('general_agent_id', $generalAgentId)
                ->where('agent_group_id', $data['agent_group_id'])
                ->where('status', StatusEnum::ENABLE->value)
                ->when($excludePlanId !== null, fn ($q) => $q->where('id', '!=', $excludePlanId))
                ->exists();
            if ($groupInUse) {
                throw new ApiException(__('error_message.ggr_setting.agent_group_in_use'));
            }
        }

        $vendorSettingIds = [(int) $data['downline_vendor_setting_id']];
        $conditionIds = [(int) $data['downline_condition_id']];
        foreach ($data['special_agents'] ?? [] as $row) {
            $vendorSettingIds[] = (int) $row['vendor_setting_id'];
            $conditionIds[] = (int) $row['condition_id'];
        }

        $this->assertSettingIdsBelongTo(
            GgrVendorSetting::query(),
            array_unique($vendorSettingIds),
            $generalAgentId,
            $requireActive,
        );
        $this->assertSettingIdsBelongTo(
            GgrCondition::query(),
            array_unique($conditionIds),
            $generalAgentId,
            $requireActive,
        );

        $agentIds = array_map(fn ($row) => (int) $row['agent_id'], $data['special_agents'] ?? []);
        if ($agentIds !== []) {
            $lineAgentCount = Agent::query()
                ->whereIn('id', $agentIds)
                ->where(function ($query) use ($agent) {
                    // 節點邊界檢查：本總代自己或其 hierarchy 下線
                    $query->where('id', $agent->id)
                        ->orWhere('hierarchy_path', 'like', $agent->hierarchy_path . '/%');
                })
                ->count();
            if ($lineAgentCount !== count(array_unique($agentIds))) {
                throw new ApiException(__('error_message.ggr_setting.agent_not_in_line'));
            }
        }
    }

    /**
     * 綁定的設定 id 必須全部屬於本總代。create 時另要求啟用中；
     * update 放行已停用的既有引用（停用僅代表不可新選，不斷既有鏈）。
     */
    private function assertSettingIdsBelongTo($query, array $ids, int $generalAgentId, bool $requireActive): void
    {
        $found = $query
            ->whereIn('id', $ids)
            ->where('general_agent_id', $generalAgentId)
            ->when($requireActive, fn ($q) => $q->where('status', 1))
            ->count();

        if ($found !== count($ids)) {
            throw new ApiException(__('error_message.ggr_setting.binding_invalid'));
        }
    }

    /** 特殊用戶 override 列落庫（agent 不重複已由 Request 驗證）。 */
    private function insertSpecialAgents(GgrPlan $plan, Agent $agent, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();
        $inserts = [];

        foreach ($rows as $row) {
            $inserts[] = [
                'plan_id' => $plan->id,
                'agent_id' => $row['agent_id'],
                'vendor_setting_id' => $row['vendor_setting_id'],
                'condition_id' => $row['condition_id'],
                'admin' => $agent->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        GgrPlanSpecialAgent::query()->insert($inserts);
    }
}
