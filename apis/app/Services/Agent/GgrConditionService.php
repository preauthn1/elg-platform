<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\GgrCondition;
use App\Models\GgrConditionTier;
use App\Services\Agent\Traits\ResolvesGgrGeneralAgent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 推廣代理 GGR 條件設置（Agent 面向 CRUD，4124）。
 *
 * 一份條件＝主表一筆＋階梯列多筆。4121 方案以主表 id 為引用契約：
 * 無 delete（停用靠 status）、階梯採 transaction 內全量替換（tier id 不對外引用）。
 * 階梯遞增（user_count / percentage）由 Request withValidator 把關。
 *
 * 本功能僅總代使用，且各總代的設置彼此不互見：一律以操作者的
 * general_agent_id 為可見性軸過濾（list / view / update 三處都要帶），
 * 取不到總代身分即拒絕，避免寫出無歸屬的孤兒資料。
 */
class GgrConditionService
{
    use ResolvesGgrGeneralAgent;

    public function paginate(Agent $agent, array $filters): LengthAwarePaginator
    {
        return GgrCondition::query()
            ->with(['creator:id,username', 'updater:id,username'])
            ->where('general_agent_id', $this->resolveGeneralAgentId($agent))
            ->when(!empty($filters['name']), fn ($q) => $q->where('name', 'like', '%' . $filters['name'] . '%'))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['logic_type']), fn ($q) => $q->where('logic_type', $filters['logic_type']))
            ->orderBy('id', 'desc')
            ->paginate($filters['per_page']);
    }

    /** 檢視單筆（非本總代或查無皆拋 1010）：主表欄位＋依 tier 升冪的階梯列。 */
    public function find(Agent $agent, int $id): GgrCondition
    {
        $condition = GgrCondition::query()
            ->with([
                'creator:id,username',
                'updater:id,username',
                'tiers' => fn ($q) => $q->orderBy('tier'),
            ])
            ->where('general_agent_id', $this->resolveGeneralAgentId($agent))
            ->where('id', $id)
            ->first();

        if (!$condition) {
            throw new ApiException(__('error_message.1010'));
        }

        return $condition;
    }

    public function create(Agent $agent, array $data): GgrCondition
    {
        $generalAgentId = $this->resolveGeneralAgentId($agent);
        $this->assertNameUnique($generalAgentId, $data['name']);

        return DB::transaction(function () use ($agent, $generalAgentId, $data) {
            $condition = GgrCondition::create([
                'general_agent_id' => $generalAgentId,
                'name' => $data['name'],
                'logic_type' => $data['logic_type'],
                'active_user_only' => $data['active_user_only'],
                'status' => $data['status'],
                'created_by' => $agent->id,
                'admin' => $agent->id,
            ]);

            $this->insertTiers($condition, $agent, $data['tiers']);

            return $condition;
        });
    }

    /** 更新主表（非本總代或查無皆拋 1010）並全量替換階梯列。 */
    public function update(Agent $agent, int $id, array $data): GgrCondition
    {
        $generalAgentId = $this->resolveGeneralAgentId($agent);
        $this->assertNameUnique($generalAgentId, $data['name'], $id);

        return DB::transaction(function () use ($agent, $generalAgentId, $id, $data) {
            $condition = GgrCondition::query()
                ->where('general_agent_id', $generalAgentId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$condition) {
                throw new ApiException(__('error_message.1010'));
            }

            $condition->fill([
                'name' => $data['name'],
                'logic_type' => $data['logic_type'],
                'active_user_only' => $data['active_user_only'],
                'status' => $data['status'],
                'admin' => $agent->id,
            ]);
            // 只改階梯時主表欄位可能原封不動，Eloquent 便不會下 UPDATE、
            // updated_at 也就停在上一次改標題／改狀態的時間；此處明確推進。
            $condition->updated_at = now();
            $condition->save();

            GgrConditionTier::query()->where('condition_id', $condition->id)->delete();
            $this->insertTiers($condition, $agent, $data['tiers']);

            return $condition;
        });
    }

    /**
     * 標題同總代內唯一（2026-09-01 工單定案，邊界8-12）：被 4121 下拉引用，
     * 重複會選錯；複製後未改名也要擋。DB 另有 uk_ggr_condition_agent_name 當保險。
     */
    private function assertNameUnique(int $generalAgentId, string $name, ?int $excludeId = null): void
    {
        $exists = GgrCondition::query()
            ->where('general_agent_id', $generalAgentId)
            ->where('name', $name)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw new ApiException(__('error_message.ggr_setting.name_duplicated'));
        }
    }

    /** 階梯列落庫：tier 依列序 1-based（遞增順序已由 Request 驗證）。 */
    private function insertTiers(GgrCondition $condition, Agent $agent, array $tiers): void
    {
        $now = now();
        $rows = [];

        foreach (array_values($tiers) as $index => $tier) {
            $rows[] = [
                'condition_id' => $condition->id,
                'tier' => $index + 1,
                'user_count' => $tier['user_count'],
                'deposit_amount' => $tier['deposit_amount'],
                'total_bet_amount' => $tier['total_bet_amount'],
                'game_revenue_amount' => $tier['game_revenue_amount'],
                'valid_bet_amount' => $tier['valid_bet_amount'],
                'percentage' => $tier['percentage'],
                'admin' => $agent->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        GgrConditionTier::query()->insert($rows);
    }
}
