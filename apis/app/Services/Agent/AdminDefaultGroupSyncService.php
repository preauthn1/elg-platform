<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\AgentPermissionGroup;
use App\Models\AgentPermissionGroupMenu;
use Illuminate\Support\Facades\DB;

/**
 * 「admin 預設角色」的權限跟 admin 本人（agents.id = 1）保持一致（target/tickets#4422）。
 *
 * 由 SyncAgentMenuSeeder 在每次升級時呼叫：
 * - admin 預設角色＝所屬總代為 admin 且設為預設的角色；該站還沒有就建立一個（啟用、預設）。
 *   設為預設代表之後新開的 admin 子帳號沒指定角色時，會自動套用它（4422 的預設規則）
 * - 只補不刪：admin 有、角色沒有的權限補進去（action 照抄）；admin 少了的不從角色拿掉，要拿掉由後台手動處理
 * - 不綁定任何帳號
 *
 * 第一次升級等於把 admin 的權限整份複製進新角色；之後每次升級新增給 admin 的權限，同一輪就會補進角色。
 */
class AdminDefaultGroupSyncService
{
    public const string DEFAULT_GROUP_NAME = 'admin預設角色';

    /** 分批寫入的筆數：第一次約 350 筆，避免單句 INSERT 過大 */
    private const int INSERT_CHUNK = 200;

    /**
     * @return array{group_id: int|null, created: bool, added: int} group_id 為 null 代表 admin 沒有任何權限，未處理
     */
    public function sync(): array
    {
        // 整段在交易內：交易中的讀取一律走主庫，剛建立的角色才讀得回來
        return DB::transaction(function (): array {
            $adminId = AgentPermissionGroup::ADMIN_OWNER_ID;

            // 只取還存在的選單：admin 的個人權限可能殘留已刪除選單的 id
            $adminPermissions = AgentPermission::query()
                ->where('agent_id', $adminId)
                ->whereIn('agent_menu_id', AgentMenu::query()->select('id'))
                ->pluck('action', 'agent_menu_id');

            // 測試庫或剛建站還沒有 admin 權限：不建空角色
            if ($adminPermissions->isEmpty()) {
                return ['group_id' => null, 'created' => false, 'added' => 0];
            }

            $group = AgentPermissionGroup::query()
                ->where('owner_agent_id', $adminId)
                ->where('default_setting', 1)
                ->first();

            $created = $group === null;
            if ($created) {
                $group = AgentPermissionGroup::query()->create([
                    'name' => self::DEFAULT_GROUP_NAME,
                    'owner_agent_id' => $adminId,
                    'remark' => '系統建立：權限與 admin 同步，升級時自動補上新權限',
                    'status' => 1,
                    'default_setting' => 1,
                    'admin' => 0,
                ]);
            }

            $existing = AgentPermissionGroupMenu::query()
                ->where('agent_permission_group_id', $group->id)
                ->pluck('agent_menu_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $now = now();
            $rows = $adminPermissions
                ->except($existing)
                ->map(fn ($action, $menuId): array => [
                    'agent_permission_group_id' => $group->id,
                    'agent_menu_id' => (int) $menuId,
                    'action' => (string) $action,
                    'admin' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->values();

            foreach ($rows->chunk(self::INSERT_CHUNK) as $chunk) {
                AgentPermissionGroupMenu::query()->insert($chunk->values()->all());
            }

            return ['group_id' => (int) $group->id, 'created' => $created, 'added' => $rows->count()];
        });
    }
}
