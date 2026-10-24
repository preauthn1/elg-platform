<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\GgrSettlement;
use App\Services\Agent\Traits\ResolvesGgrGeneralAgent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * 推廣代理 GGR 派發紀錄查詢（4125，批次三純查詢版）。
 *
 * 資料由後續批次的結算引擎寫入，本 Service 只有 list / view——
 * 無 create / update / delete，發放與備註等寫入操作屬發放批次。
 *
 * 可見範圍＝「登入代理自己 + hierarchy 線內所有下線」，再疊 general_agent_id
 * 作第二道隔離。本頁選單不只授權給總代（實際有大量一般代理被授權），
 * 單靠 general_agent_id 會讓中層代理看到上層與旁系的分潤金額。
 */
class GgrSettlementService
{
    use ResolvesGgrGeneralAgent;

    public function paginate(Agent $agent, array $filters): LengthAwarePaginator
    {
        $query = GgrSettlement::query()
            ->with(['agent:id,username', 'updater:id,username'])
            ->where('general_agent_id', $this->resolveGeneralAgentId($agent));

        $this->scopeToOwnLine($query, $agent);

        return $query
            ->when(!empty($filters['agent_username']), function ($q) use ($filters) {
                $q->whereHas('agent', fn ($sub) => $sub->where('username', 'like', '%' . $filters['agent_username'] . '%'));
            })
            // 結轉狀態由 carry_out_amount 推導：本期有帶出金額即「有結轉」
            ->when(isset($filters['carry']) && $filters['carry'] !== '', function ($q) use ($filters) {
                (int) $filters['carry'] === 1
                    ? $q->where('carry_out_amount', '!=', 0)
                    : $q->where('carry_out_amount', 0);
            })
            ->when(isset($filters['payout_status']) && $filters['payout_status'] !== '', fn ($q) => $q->where('payout_status', $filters['payout_status']))
            ->when(!empty($filters['start_date']), fn ($q) => $q->where('period_start', '>=', $filters['start_date']))
            ->when(!empty($filters['end_date']), fn ($q) => $q->where('period_start', '<=', $filters['end_date']))
            ->orderBy('id', 'desc')
            ->paginate($filters['per_page']);
    }

    /** 檢視單筆（不在自己線內或查無皆拋 1010）：含計算明細快照。 */
    public function find(Agent $agent, int $id): GgrSettlement
    {
        $query = GgrSettlement::query()
            ->with(['agent:id,username', 'updater:id,username', 'plan:id,name'])
            ->where('general_agent_id', $this->resolveGeneralAgentId($agent))
            ->where('id', $id);

        $this->scopeToOwnLine($query, $agent);

        $settlement = $query->first();

        if (!$settlement) {
            throw new ApiException(__('error_message.1010'));
        }

        return $settlement;
    }

    /**
     * 收斂到「登入代理自己 + 線內所有下線」，看不到上層與旁系。
     *
     * hierarchy_path 自帶本人（如 /1/4249/102561），故「自己」是等值比對、
     * 「下線」是前綴 like；like 一定要帶結尾斜線，否則 /1/4249/1025610
     * 這種前綴相同的別人也會被撈進來。ggr_settlements 沒有 hierarchy 欄位，
     * 只能經 agent 關聯過濾。
     *
     * @param  Builder<GgrSettlement>  $query
     */
    private function scopeToOwnLine(Builder $query, Agent $agent): void
    {
        $path = $agent->hierarchy_path;

        // hierarchy_path 缺漏時安全降級成只看自己那一筆——
        // 寧可少看，也不可退回「整條總代線都看得到」的舊行為
        if ($path === null || $path === '') {
            $query->where('agent_id', $agent->id);

            return;
        }

        // orWhere 必須關在 whereHas 的子查詢裡自成群組，否則會短路外層的 where
        $query->whereHas('agent', function ($sub) use ($path): void {
            $sub->where('hierarchy_path', $path)
                ->orWhere('hierarchy_path', 'like', $path . '/%');
        });
    }
}
