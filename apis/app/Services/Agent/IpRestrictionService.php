<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\IpRestrictionBlockItemEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\IpRestriction;
use App\Services\IpRestrictionGuard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 風控IP限制後台管理（Agent 面向 CRUD，4096）。
 *
 * IP 限制為全站性、無目標歸屬，不做可見性 scope（與 MemberRestrictionService 的
 * agent_id 下線軸不同），權限由選單 code risk_ip_restriction 控管。
 * 本功能無 delete：同 IP 僅一筆（unique），打錯靠 update 修正、停用靠 status 關閉。
 *
 * 前台 enforcement 走 IpRestrictionGuard；IP 寫入一律 canonical form，
 * 與 Guard 查詢端字串等值比對一致。
 */
class IpRestrictionService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        return IpRestriction::query()
            ->with('updater:id,username')
            ->when(!empty($filters['ip']), fn ($q) => $q->where('ip', 'like', '%' . $filters['ip'] . '%'))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('id', 'desc')
            ->paginate($filters['per_page']);
    }

    /** 檢視單筆（查無拋 1010）。 */
    public function find(int $id): IpRestriction
    {
        $rule = IpRestriction::query()
            ->with('updater:id,username')
            ->where('id', $id)
            ->first();

        if (!$rule) {
            throw new ApiException(__('error_message.1010'));
        }

        return $rule;
    }

    /** 建立規則；同 IP（canonical 後比對）已存在拋 ip_restriction.duplicate。 */
    public function create(Agent $agent, array $data): IpRestriction
    {
        $ip = IpRestrictionGuard::canonicalize($data['ip']);

        return DB::transaction(function () use ($agent, $ip, $data) {
            $exists = IpRestriction::query()
                ->where('ip', $ip)
                ->lockForUpdate()
                ->exists();
            if ($exists) {
                throw new ApiException(__('error_message.ip_restriction.duplicate'));
            }

            return IpRestriction::create([
                'ip' => $ip,
                'remark' => $data['remark'] ?? null,
                'status' => $data['status'],
                'admin' => $agent->id,
                ...IpRestrictionBlockItemEnum::toColumns($data['block_items']),
            ]);
        });
    }

    /**
     * 更新規則（查無拋 1010）。IP 可改（無 delete，打錯要能修正），
     * 改成既存他筆 IP 拋 duplicate（self-exclusion）；repeat_count 不受 update 影響。
     */
    public function update(Agent $agent, int $id, array $data): IpRestriction
    {
        $ip = IpRestrictionGuard::canonicalize($data['ip']);

        return DB::transaction(function () use ($agent, $id, $ip, $data) {
            $rule = IpRestriction::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$rule) {
                throw new ApiException(__('error_message.1010'));
            }

            $exists = IpRestriction::query()
                ->where('ip', $ip)
                ->where('id', '!=', $rule->id)
                ->lockForUpdate()
                ->exists();
            if ($exists) {
                throw new ApiException(__('error_message.ip_restriction.duplicate'));
            }

            $rule->update([
                'ip' => $ip,
                'remark' => $data['remark'] ?? null,
                'status' => $data['status'],
                'admin' => $agent->id,
                ...IpRestrictionBlockItemEnum::toColumns($data['block_items']),
            ]);

            return $rule->refresh();
        });
    }
}
