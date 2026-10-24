<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\AgentPermission;

use App\Models\Agent;
use App\Models\AgentPermission;
use App\Models\AgentPermissionGroup;
use App\Models\AgentPermissionGroupMenu;
use App\Services\Agent\AdminDefaultGroupSyncService;
use Tests\Feature\Agent\AgentPermission\Concerns\BuildsAgentPermissionFixtures;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Agent/AgentPermission/AdminDefaultGroupSyncServiceTest.php
 *
 * admin 預設角色的權限跟 admin 本人保持一致（target/tickets#4422，由 SyncAgentMenuSeeder 每次升級呼叫）：
 * 沒有就建立並整份複製、之後只補缺的、不刪、不綁任何帳號。
 */
class AdminDefaultGroupSyncServiceTest extends TestCase
{
    use BuildsAgentPermissionFixtures;

    private AdminDefaultGroupSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // 測試庫只同步 schema（admin 沒有任何權限、也沒有 admin 角色），資料都由各測試自建，結束由交易回滾
        $this->service = app(AdminDefaultGroupSyncService::class);
    }

    public function test_creates_default_group_with_admin_permissions(): void
    {
        $this->grantAdmin('admin_a', 'view,update');
        $this->grantAdmin('admin_b', '');

        $result = $this->service->sync();

        $this->assertTrue($result['created']);
        $this->assertSame(2, $result['added']);
        $group = AgentPermissionGroup::query()->findOrFail($result['group_id']);
        $this->assertSame(AgentPermissionGroup::ADMIN_OWNER_ID, $group->owner_agent_id);
        $this->assertSame(1, $group->default_setting);
        $this->assertSame(1, $group->status);
        $this->assertSame(AdminDefaultGroupSyncService::DEFAULT_GROUP_NAME, $group->name);
        $this->assertSame('view,update', $this->groupAction($group->id, 'admin_a'), 'action 照抄');
        $this->assertFalse(Agent::query()->where('agent_permission_group_id', $group->id)->exists(), '不綁定任何帳號');
    }

    public function test_rerun_only_adds_missing_and_never_removes(): void
    {
        $this->grantAdmin('admin_a', '');
        $groupId = $this->service->sync()['group_id'];

        // 下次升級：admin 多了新權限、少了舊權限
        $this->grantAdmin('admin_new', '');
        AgentPermission::query()->where('agent_id', 1)->where('agent_menu_id', $this->menu('admin_a')->id)->delete();

        $result = $this->service->sync();

        $this->assertFalse($result['created']);
        $this->assertSame($groupId, $result['group_id']);
        $this->assertSame(1, $result['added']);
        $this->assertSame(2, AgentPermissionGroupMenu::query()->where('agent_permission_group_id', $groupId)->count(), '只補不刪');
        $this->assertSame(0, $this->service->sync()['added'], '重跑不重複寫入');
    }

    public function test_uses_existing_admin_default_group(): void
    {
        $existing = $this->role('既有 admin 預設', AgentPermissionGroup::ADMIN_OWNER_ID, [$this->menu('admin_a')->id], default: 1);
        $this->grantAdmin('admin_a', '');
        $this->grantAdmin('admin_b', '');

        $result = $this->service->sync();

        $this->assertFalse($result['created']);
        $this->assertSame($existing->id, $result['group_id']);
        $this->assertSame(1, $result['added']);
        $this->assertSame(1, AgentPermissionGroup::query()->where('owner_agent_id', 1)->where('default_setting', 1)->count());
    }

    public function test_skips_when_admin_has_no_permissions(): void
    {
        $result = $this->service->sync();

        $this->assertNull($result['group_id']);
        $this->assertFalse(AgentPermissionGroup::query()->where('owner_agent_id', 1)->where('default_setting', 1)->exists());
    }

    private function grantAdmin(string $code, string $action): void
    {
        AgentPermission::query()->create([
            'agent_id' => AgentPermissionGroup::ADMIN_OWNER_ID,
            'agent_menu_id' => $this->menu($code)->id,
            'action' => $action,
            'admin' => 0,
        ]);
    }

    private function groupAction(int $groupId, string $code): ?string
    {
        return AgentPermissionGroupMenu::query()
            ->where('agent_permission_group_id', $groupId)
            ->where('agent_menu_id', $this->menu($code)->id)
            ->value('action');
    }
}
