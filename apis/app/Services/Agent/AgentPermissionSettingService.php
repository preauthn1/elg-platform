<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentMenu;
use App\Models\AgentOperateLog;
use App\Models\AgentPermission;
use App\Models\AgentPermissionGroup;
use Illuminate\Support\Facades\DB;

/**
 * 代理個人權限存檔與子帳號權限同步（target/tickets#4422 自 API_Agent AgentPermissionController 搬入）。
 *
 * 必須與 API_Agent 版等價（舊後台仍走它），兩邊寫同一張 agent_permissions：
 * - setting：差集更新；不能授出自己沒有的權限（op2997：目標原本就有的、已停用的選單不算授出）；
 *   super admin 及其子帳號不受限；只在「操作者可授範圍 ∩ 目標既有」內刪除，範圍外的既有權限保留不重寫；
 *   不能使用會員銀行卡個資的操作者，可授範圍再扣掉銀行卡管理兩個選單（target/tickets#4486）
 * - childSync：主帳號把自己的個人權限「補給」所有子帳號（只增不刪），並把主帳號的角色補給沒有角色的子帳號
 *
 * 刻意的差異：childSync 補角色時套 4422 規則（admin 子帳號只能拿 admin／共用角色），API_Agent 版沒套；
 * childSync 包交易（API_Agent 版沒有，中途失敗會部分完成）。
 */
class AgentPermissionSettingService
{
    /** 同步入口本身的選單，不複製給子帳號（照 API_Agent） */
    private const string SYNC_MENU_CODE = 'agent_sync_permissiom';

    private const string SYNC_LOG_TYPE = 'agent_permission_sync';

    public function __construct(
        private readonly AgentPermissionScopeService $scope,
    ) {}

    /**
     * 存某代理的個人權限。
     *
     * @param  array<int|string, mixed>  $menus  {"選單id": "action字串"}
     */
    public function setting(Agent $operator, int $targetId, array $menus): void
    {
        $submitted = array_map('strval', array_keys($menus));

        DB::transaction(function () use ($operator, $targetId, $menus, $submitted): void {
            // 第一句即鎖定讀：鎖目標代理主鍵列當互斥點（record lock），同一目標的兩次存檔序列化
            Agent::query()->whereKey($targetId)->lockForUpdate()->first(['id']);

            // 範疇檢查放交易內（讀主庫）：新後台建完代理緊接著存權限，交易外可能讀到尚未同步的從庫而誤判 9003
            if (!$this->scope->isInScope($this->scope->hostId($operator), $targetId)) {
                throw new ApiException(__('error_message.9003'));
            }

            $existing = AgentPermission::query()->where('agent_id', $targetId)->pluck('agent_menu_id')
                ->map(fn ($v) => (string) $v)->unique()->values()->all();
            $grantable = $this->scope->isSuperAdminOrSubAccount($operator)
                ? null
                : array_map('strval', $this->scope->effectiveMenuIds($operator->id));

            // target/tickets#4486：不能使用會員銀行卡個資的操作者，看不到銀行卡管理兩個選單（自己的選單樹已濾掉），
            // 存檔送來的 menus 必然不含它們；把它們扣出可授範圍，下面的差集就會把目標既有的這兩項當成範圍外
            // 保留不刪、不改寫 action，目標原本沒有的也不得授出（9003）。與 API_Agent 同步修正
            if ($grantable !== null && !$this->scope->canAccessMemberBankAccount($operator)) {
                $grantable = array_values(array_diff($grantable, array_map('strval', $this->scope->memberBankAccountMenuIds())));
            }

            // 防提權：送來的、自己沒有、目標也原本沒有的（扣掉已停用的選單）不得授出
            if ($grantable !== null) {
                $excess = array_diff($submitted, $grantable, $existing);
                $excess = array_diff($excess, $this->disabledMenuIds($excess));
                if ($excess !== []) {
                    throw new ApiException(__('error_message.9003'));
                }
            }

            foreach ($submitted as $menuId) {
                if (!ctype_digit($menuId)) {
                    throw new ApiException(__('error_message.1060'));
                }
            }

            $scopedExisting = $grantable === null ? $existing : array_intersect($existing, $grantable);
            $toDelete = array_values(array_diff($scopedExisting, $submitted));
            if ($toDelete !== []) {
                AgentPermission::query()->where('agent_id', $targetId)->whereIn('agent_menu_id', $toDelete)->delete();
            }

            // 操作者範圍外的既有項跳過、不重寫（op2997）
            $keepAsIs = $grantable === null ? [] : array_diff($existing, $grantable);
            foreach ($menus as $menuId => $action) {
                if (in_array((string) $menuId, $keepAsIs, true)) {
                    continue;
                }
                AgentPermission::query()->updateOrCreate(
                    ['agent_id' => $targetId, 'agent_menu_id' => (int) $menuId],
                    ['action' => (string) $action, 'admin' => $operator->id],
                );
            }
        });
    }

    /**
     * 操作者自己的子帳號清單（同步權限頁、新增子帳號頁用）。輸出結構照 API_Agent agent/child/accounts。
     *
     * @return array{is_not_child_account: bool, childs: array<int, array{id: int, username: string}>, childCount: int}
     */
    public function childAccounts(Agent $operator): array
    {
        $childs = Agent::query()
            ->where('host_id', $operator->id)
            ->orderBy('username')
            ->get(['id', 'username'])
            ->map(static fn (Agent $agent): array => ['id' => $agent->id, 'username' => $agent->username])
            ->all();

        return [
            'is_not_child_account' => (int) $operator->host_id === 0,
            'childs' => $childs,
            'childCount' => count($childs),
        ];
    }

    /**
     * 主帳號把自己的個人權限補給所有子帳號（只增不刪），並把自己的角色補給沒有角色的子帳號。
     *
     * @return array<int, int> 同步到的子帳號 id
     */
    public function syncChildren(Agent $operator): array
    {
        if ((int) $operator->host_id !== 0) {
            throw new ApiException(__('error_message.agent_permission.sync_sub_account_forbidden'));
        }

        $childIds = Agent::query()->where('host_id', $operator->id)->pluck('id')->map(fn ($v) => (int) $v)->all();
        if ($childIds === []) {
            throw new ApiException(__('error_message.agent_permission.sync_no_sub_account'));
        }

        DB::transaction(function () use ($operator, $childIds): void {
            // 第一句即鎖定讀：鎖主帳號主鍵列當互斥點，同一主帳號的兩次同步序列化
            Agent::query()->whereKey($operator->id)->lockForUpdate()->first(['id']);

            $sourceMenuIds = AgentMenu::query()
                ->where('code', '!=', self::SYNC_MENU_CODE)
                ->whereIn('id', AgentPermission::query()->where('agent_id', $operator->id)->select('agent_menu_id'))
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            foreach ($childIds as $childId) {
                $owned = AgentPermission::query()->where('agent_id', $childId)->pluck('agent_menu_id')
                    ->map(fn ($v) => (int) $v)->all();
                $rows = array_map(
                    fn (int $menuId): array => ['agent_id' => $childId, 'agent_menu_id' => $menuId, 'action' => '', 'admin' => 0],
                    array_values(array_diff($sourceMenuIds, $owned)),
                );
                if ($rows !== []) {
                    // 批次寫入：一個子帳號一句 INSERT（照 API_Agent 的 INSERT ... SELECT 語意，admin 寫 0）
                    AgentPermission::query()->insert(array_map(fn (array $r): array => $r + ['created_at' => now(), 'updated_at' => now()], $rows));
                }
            }

            $this->fillChildrenRole($operator, $childIds);

            AgentOperateLog::query()->create([
                'agent_id' => $operator->id,
                'reference_id' => '0',
                'reference_type' => '',
                'type' => self::SYNC_LOG_TYPE,
                'debug_info' => implode(',', $childIds),
                'ip' => (string) Common::getIp(),
            ]);
        });

        return $childIds;
    }

    /**
     * 主帳號有角色時，補給沒有角色的子帳號（4080）。4422：子帳號 host_id＝主帳號 id，
     * 主帳號是 super admin 時子帳號就是 admin 子帳號，只能拿 admin／共用角色，不符就不補。
     *
     * @param  array<int, int>  $childIds
     */
    private function fillChildrenRole(Agent $operator, array $childIds): void
    {
        $groupId = Agent::query()->whereKey($operator->id)->value('agent_permission_group_id');
        if (empty($groupId)) {
            return;
        }

        $owner = AgentPermissionGroup::query()->whereKey($groupId)->value('owner_agent_id');
        $owner = $owner === null ? null : (int) $owner;
        if (!AgentPermissionGroup::isAllowedForTargetHost($owner, $operator->id)) {
            return;
        }

        // 4498：補的是 admin 角色時，只補給「已開 Google 2FA 且登入 IP 白名單非空」的子帳號，
        // 否則同步子帳號會繞過建立／編輯營運商那道 admin 角色前提
        if (AgentPermissionGroup::isAdminOwner($owner)) {
            $childIds = Agent::query()
                ->whereIn('id', $childIds)
                ->get(['id', 'has_2fa', 'login_ip_whitelist'])
                ->filter(fn (Agent $child): bool => AgentPermissionGroup::adminRoleMissingRequirements((int) $child->has_2fa, $child->login_ip_whitelist) === [])
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();
            if ($childIds === []) {
                return;
            }
        }

        Agent::query()
            ->whereIn('id', $childIds)
            ->where(fn ($q) => $q->whereNull('agent_permission_group_id')->orWhere('agent_permission_group_id', 0))
            ->update(['agent_permission_group_id' => $groupId, 'admin' => $operator->id]);
    }

    /**
     * 存在且已停用的選單 id（op2997 豁免用；不存在的 id 不豁免）。
     *
     * @param  array<int, string>  $menuIds
     * @return array<int, string>
     */
    private function disabledMenuIds(array $menuIds): array
    {
        if ($menuIds === []) {
            return [];
        }

        return AgentMenu::query()->whereIn('id', $menuIds)->where('status', '!=', 1)->pluck('id')
            ->map(fn ($v) => (string) $v)->all();
    }
}
