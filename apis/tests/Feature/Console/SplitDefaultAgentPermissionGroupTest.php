<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\Agent\RoleEnum;
use App\Models\Agent;
use App\Models\AgentPermissionGroup;
use App\Models\AgentPermissionGroupMenu;
use App\Models\AgentPermissionGroupMigrationLog;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/SplitDefaultAgentPermissionGroupTest.php
 *
 * patch:split-default-agent-permission-group（target/op_tickets#3168）整合測試。
 *
 * 守的是三件事：歸屬判定在巢狀總代下取最近的那個、生效權限零變動、可回滾。
 */
class SplitDefaultAgentPermissionGroupTest extends TestCase
{
    private const string SUFFIX = '預設角色';

    /** 本測試建立的代理 id，逐筆清理（禁止 truncate，見 .claude/rules/php.md） */
    private array $agentIds = [];

    /** 本測試建立的角色 id */
    private array $groupIds = [];

    protected function tearDown(): void
    {
        if ($this->agentIds !== []) {
            AgentPermissionGroupMigrationLog::query()->whereIn('agent_id', $this->agentIds)->delete();
            Agent::query()->whereIn('id', $this->agentIds)->delete();
        }

        if ($this->groupIds !== []) {
            AgentPermissionGroupMenu::query()->whereIn('agent_permission_group_id', $this->groupIds)->delete();
            AgentPermissionGroup::query()->whereIn('id', $this->groupIds)->delete();
        }

        parent::tearDown();
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /**
     * 建立全域預設角色（補丁的權限來源），並帶指定數量的選單。
     *
     * 補丁取來源時是 `whereNull(owner)->where(default_setting,1)->first()`——只取一筆。
     * 測試庫是共用的，若庫裡已有別的全域預設（他人測試殘留、或同一次執行的前一個測試），
     * 補丁會取到那一筆而不是這次建的，整個測試就驗錯對象。故先把既有的降級。
     */
    private function makeGlobalGroup(array $menuIds): AgentPermissionGroup
    {
        AgentPermissionGroup::query()
            ->whereNull('owner_agent_id')
            ->where('default_setting', 1)
            ->update(['default_setting' => 0]);

        $group = AgentPermissionGroup::query()->create([
            'name' => 'T全域預設' . random_int(100000, 999999),
            'owner_agent_id' => null,
            'status' => 1,
            'default_setting' => 1,
            'admin' => 0,
        ]);

        $this->groupIds[] = $group->id;

        foreach ($menuIds as $menuId) {
            AgentPermissionGroupMenu::query()->create([
                'agent_permission_group_id' => $group->id,
                'agent_menu_id' => $menuId,
                'action' => '',
            ]);
        }

        return $group;
    }

    /**
     * 建立代理。
     *
     * `hierarchy_path` 的最後一段必然是自己的 id（正式系統在 Agent::create() 之後
     * 才補寫這個欄位），所以這裡收的是**上層路徑**，建立後才用真實 id 補完自己那段。
     * 寫死假 id 會造出正式庫不可能出現的資料，讓歸屬驗證誤判。
     *
     * @param  string  $parentPath  上層代理的 hierarchy_path，例：'/1' 或 '/1/123'
     */
    private function makeAgent(string $username, string $parentPath, int $roleId, ?int $groupId): Agent
    {
        $agent = Agent::query()->create([
            'username' => $username,
            // agents 的 code／email／admin 皆為 NOT NULL 且無預設值，測試須自帶
            'code' => substr((string) random_int(100000, 999999), 0, 10),
            'email' => $username . '@test.local',
            'password' => 'x',
            'parent' => 1,
            'host_id' => 0,
            'admin' => 0,
            'role_id' => $roleId,
            'hierarchy_path' => '',
            'agent_permission_group_id' => $groupId,
            'status' => 1,
            'organization_id' => 1,
            'binding_scope_id' => 0,
        ]);

        $agent->update(['hierarchy_path' => rtrim($parentPath, '/') . '/' . $agent->id]);

        $this->agentIds[] = $agent->id;

        return $agent;
    }

    /** 該代理身上的生效權限（個人 ∪ 啟用中角色），與補丁的驗證同口徑 */
    private function effectiveMenus(int $agentId): array
    {
        $agent = Agent::query()->find($agentId);

        if (!$agent?->agent_permission_group_id) {
            return [];
        }

        $group = AgentPermissionGroup::query()
            ->where('id', $agent->agent_permission_group_id)
            ->where('status', 1)
            ->first();

        if (!$group) {
            return [];
        }

        return AgentPermissionGroupMenu::query()
            ->where('agent_permission_group_id', $group->id)
            ->pluck('agent_menu_id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    // -------------------------------------------------------
    // Tests
    // -------------------------------------------------------

    #[TestDox('每個總代各建一個預設角色，權限完整複製自全域角色')]
    public function test_creates_one_group_per_top_agent_with_copied_menus(): void
    {
        $menuIds = [1, 4, 5];
        $global = $this->makeGlobalGroup($menuIds);

        $topA = $this->makeAgent('TtopA' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);
        $topB = $this->makeAgent('TtopB' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);

        // 限定自己建的總代：測試庫是共用的，不限定會掃到同一次執行中其他測試建的資料
        $this->artisan('patch:split-default-agent-permission-group', ['--tops' => "{$topA->id},{$topB->id}"])
            ->assertSuccessful();

        foreach ([$topA, $topB] as $top) {
            $group = AgentPermissionGroup::query()
                ->where('owner_agent_id', $top->id)
                ->where('default_setting', 1)
                ->first();

            $this->assertNotNull($group, "總代 {$top->username} 未建立預設角色");
            $this->assertSame($top->username . self::SUFFIX, $group->name);
            $this->groupIds[] = $group->id;

            $copied = AgentPermissionGroupMenu::query()
                ->where('agent_permission_group_id', $group->id)
                ->pluck('agent_menu_id')
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            $this->assertSame($menuIds, $copied, '權限未完整複製');
        }

        // 全域角色不動
        $this->assertSame(1, (int) $global->fresh()->default_setting);
        $this->assertNull($global->fresh()->owner_agent_id);
    }

    #[TestDox('巢狀總代時歸屬取最近的那一個，不會掛到外層')]
    public function test_nested_top_agents_resolve_to_nearest(): void
    {
        $global = $this->makeGlobalGroup([1, 4]);

        // 內層總代掛在外層總代底下（正式庫 vworldmy → Acompany 的形狀）
        $outer = $this->makeAgent('Touter' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);
        $inner = $this->makeAgent('Tinner' . random_int(1000, 9999), "/1/{$outer->id}", RoleEnum::GENERAL_AGENT->value, null);

        // 子帳號掛在內層總代底下，路徑同時含兩個總代
        $child = $this->makeAgent(
            'Tchild' . random_int(1000, 9999),
            "/1/{$outer->id}/{$inner->id}",
            RoleEnum::AGENT->value,
            $global->id,
        );

        $this->artisan('patch:split-default-agent-permission-group', ['--tops' => "{$outer->id},{$inner->id}"])
            ->assertSuccessful();

        $innerGroup = AgentPermissionGroup::query()
            ->where('owner_agent_id', $inner->id)
            ->where('default_setting', 1)
            ->first();

        $outerGroup = AgentPermissionGroup::query()
            ->where('owner_agent_id', $outer->id)
            ->where('default_setting', 1)
            ->first();

        $this->groupIds[] = $innerGroup->id;
        $this->groupIds[] = $outerGroup->id;

        $this->assertSame(
            $innerGroup->id,
            (int) $child->fresh()->agent_permission_group_id,
            '子帳號應掛到最近的總代（內層），而非外層',
        );
    }

    #[TestDox('遷移後生效權限零變動')]
    public function test_effective_permissions_unchanged_after_migration(): void
    {
        $menuIds = [1, 4, 5, 7];
        $global = $this->makeGlobalGroup($menuIds);

        $top = $this->makeAgent('Ttop' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);
        $child = $this->makeAgent(
            'Tchild' . random_int(1000, 9999),
            "/1/{$top->id}",
            RoleEnum::AGENT->value,
            $global->id,
        );

        $before = $this->effectiveMenus($child->id);

        $this->artisan('patch:split-default-agent-permission-group', ['--tops' => (string) $top->id])
            ->assertSuccessful();

        $newGroup = AgentPermissionGroup::query()
            ->where('owner_agent_id', $top->id)
            ->where('default_setting', 1)
            ->first();

        $this->groupIds[] = $newGroup->id;

        $after = $this->effectiveMenus($child->id);

        $this->assertSame($before, $after, '遷移後生效權限不應改變');
        $this->assertNotSame($global->id, (int) $child->fresh()->agent_permission_group_id, '應已改掛新角色');
    }

    #[TestDox('孤兒帳號維持指向全域角色')]
    public function test_orphan_agents_stay_on_global_group(): void
    {
        $global = $this->makeGlobalGroup([1]);

        // 直掛 Admin 下、路徑無任何總代
        $orphan = $this->makeAgent('Torphan' . random_int(1000, 9999), '/1', RoleEnum::AGENT->value, $global->id);

        // 另建一個總代，確保命令有事可做
        $top = $this->makeAgent('Ttop' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);

        $this->artisan('patch:split-default-agent-permission-group', ['--tops' => (string) $top->id])
            ->assertSuccessful();

        $newGroup = AgentPermissionGroup::query()->where('owner_agent_id', $top->id)->first();

        if ($newGroup) {
            $this->groupIds[] = $newGroup->id;
        }

        $this->assertSame(
            $global->id,
            (int) $orphan->fresh()->agent_permission_group_id,
            '孤兒帳號不該被改掛',
        );
    }

    #[TestDox('dry-run 不寫入任何資料')]
    public function test_dry_run_writes_nothing(): void
    {
        $global = $this->makeGlobalGroup([1, 4]);
        $top = $this->makeAgent('Ttop' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);
        $child = $this->makeAgent(
            'Tchild' . random_int(1000, 9999),
            "/1/{$top->id}",
            RoleEnum::AGENT->value,
            $global->id,
        );

        $this->artisan('patch:split-default-agent-permission-group', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertNull(
            AgentPermissionGroup::query()->where('owner_agent_id', $top->id)->first(),
            'dry-run 不應建立角色',
        );

        $this->assertSame(
            $global->id,
            (int) $child->fresh()->agent_permission_group_id,
            'dry-run 不應改掛代理',
        );
    }

    #[TestDox('重跑冪等，不重複建立角色也不重複改掛')]
    public function test_rerun_is_idempotent(): void
    {
        $global = $this->makeGlobalGroup([1, 4]);
        $top = $this->makeAgent('Ttop' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);
        $this->makeAgent(
            'Tchild' . random_int(1000, 9999),
            "/1/{$top->id}",
            RoleEnum::AGENT->value,
            $global->id,
        );

        $this->artisan('patch:split-default-agent-permission-group', ['--tops' => (string) $top->id])->assertSuccessful();

        $groupCount = AgentPermissionGroup::query()->where('owner_agent_id', $top->id)->count();
        $group = AgentPermissionGroup::query()->where('owner_agent_id', $top->id)->first();
        $this->groupIds[] = $group->id;

        $logCount = AgentPermissionGroupMigrationLog::query()
            ->whereIn('agent_id', $this->agentIds)
            ->count();

        $this->artisan('patch:split-default-agent-permission-group', ['--tops' => (string) $top->id])->assertSuccessful();

        $this->assertSame(
            $groupCount,
            AgentPermissionGroup::query()->where('owner_agent_id', $top->id)->count(),
            '重跑不應重複建立角色',
        );

        $this->assertSame(
            $logCount,
            AgentPermissionGroupMigrationLog::query()->whereIn('agent_id', $this->agentIds)->count(),
            '重跑不應重複寫備份（代理已在正確角色上）',
        );
    }

    #[TestDox('--tops 只建名單內的總代，名單外的線維持共用角色')]
    public function test_tops_limits_to_listed_top_agents(): void
    {
        $global = $this->makeGlobalGroup([1, 4]);

        // 名單內的線
        $listed = $this->makeAgent('Tlisted' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);
        $listedChild = $this->makeAgent(
            'TlistedC' . random_int(1000, 9999),
            "/1/{$listed->id}",
            RoleEnum::AGENT->value,
            $global->id,
        );

        // 名單外的線：同樣是總代，但這次不處理
        $skipped = $this->makeAgent('Tskipped' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);
        $skippedChild = $this->makeAgent(
            'TskippedC' . random_int(1000, 9999),
            "/1/{$skipped->id}",
            RoleEnum::AGENT->value,
            $global->id,
        );

        $this->artisan('patch:split-default-agent-permission-group', ['--tops' => (string) $listed->id])
            ->assertSuccessful();

        $listedGroup = AgentPermissionGroup::query()->where('owner_agent_id', $listed->id)->first();
        $this->assertNotNull($listedGroup, '名單內的總代應建立預設角色');
        $this->groupIds[] = $listedGroup->id;

        $this->assertNull(
            AgentPermissionGroup::query()->where('owner_agent_id', $skipped->id)->first(),
            '名單外的總代不應建立角色',
        );

        $this->assertSame(
            $listedGroup->id,
            (int) $listedChild->fresh()->agent_permission_group_id,
            '名單內的線，代理應改掛新角色',
        );

        $this->assertSame(
            $global->id,
            (int) $skippedChild->fresh()->agent_permission_group_id,
            '名單外的線，代理應留在共用角色',
        );
    }

    #[TestDox('--tops 帶到非總代的 id 時中止，不做半套')]
    public function test_tops_rejects_non_top_agent_id(): void
    {
        $global = $this->makeGlobalGroup([1]);
        $top = $this->makeAgent('Ttop' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);
        // 一般代理，不是總代
        $notTop = $this->makeAgent('TnotTop' . random_int(1000, 9999), '/1', RoleEnum::AGENT->value, null);

        $this->artisan(
            'patch:split-default-agent-permission-group',
            ['--tops' => "{$top->id},{$notTop->id}"],
        )->assertFailed();

        $this->assertNull(
            AgentPermissionGroup::query()->where('owner_agent_id', $top->id)->first(),
            '有無效 id 時整批中止，合法的那個也不該被建立',
        );
    }

    #[TestDox('補丁後新增的代理掛上新角色，不會擋住回滾')]
    public function test_rollback_not_blocked_by_later_bindings(): void
    {
        $global = $this->makeGlobalGroup([1, 4]);
        $top = $this->makeAgent('Ttop' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);
        $child = $this->makeAgent(
            'Tchild' . random_int(1000, 9999),
            "/1/{$top->id}",
            RoleEnum::AGENT->value,
            $global->id,
        );

        $this->artisan('patch:split-default-agent-permission-group', ['--tops' => (string) $top->id])
            ->assertSuccessful();

        $newGroup = AgentPermissionGroup::query()->where('owner_agent_id', $top->id)->first();
        $this->groupIds[] = $newGroup->id;

        // 模擬補丁跑完後，API_Agent 讓新代理自動掛上該線的預設角色——
        // 這在正式環境必然發生，不該讓回滾失效
        $later = $this->makeAgent(
            'Tlater' . random_int(1000, 9999),
            "/1/{$top->id}",
            RoleEnum::AGENT->value,
            $newGroup->id,
        );

        $log = AgentPermissionGroupMigrationLog::query()->where('agent_id', $child->id)->first();

        $this->artisan('patch:split-default-agent-permission-group', ['--rollback' => $log->batch])
            ->expectsConfirmation('確定執行？', 'yes')
            ->assertSuccessful();

        $this->assertSame(
            $global->id,
            (int) $child->fresh()->agent_permission_group_id,
            '本批次的代理應還原',
        );

        $this->assertSame(
            $newGroup->id,
            (int) $later->fresh()->agent_permission_group_id,
            '非本批次的綁定應留在原地',
        );
    }

    #[TestDox('--batch 續跑讓異動集中在同一批次')]
    public function test_batch_option_reuses_existing_batch(): void
    {
        $global = $this->makeGlobalGroup([1]);
        $topA = $this->makeAgent('TtopA' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);
        $topB = $this->makeAgent('TtopB' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);
        $childA = $this->makeAgent(
            'TchildA' . random_int(1000, 9999),
            "/1/{$topA->id}",
            RoleEnum::AGENT->value,
            $global->id,
        );
        $childB = $this->makeAgent(
            'TchildB' . random_int(1000, 9999),
            "/1/{$topB->id}",
            RoleEnum::AGENT->value,
            $global->id,
        );

        // 先跑 A 線
        $this->artisan('patch:split-default-agent-permission-group', ['--tops' => (string) $topA->id])
            ->assertSuccessful();

        $batch = AgentPermissionGroupMigrationLog::query()->where('agent_id', $childA->id)->value('batch');

        // 模擬中斷後續跑 B 線，沿用同一批次
        $this->artisan('patch:split-default-agent-permission-group', [
            '--tops' => (string) $topB->id,
            '--batch' => $batch,
        ])->assertSuccessful();

        foreach ([$topA, $topB] as $top) {
            $g = AgentPermissionGroup::query()->where('owner_agent_id', $top->id)->first();
            $this->groupIds[] = $g->id;
        }

        $this->assertSame(
            $batch,
            AgentPermissionGroupMigrationLog::query()->where('agent_id', $childB->id)->value('batch'),
            '續跑應沿用指定的批次代碼',
        );

        // 一次回滾就能還原兩線
        $this->artisan('patch:split-default-agent-permission-group', ['--rollback' => $batch])
            ->expectsConfirmation('確定執行？', 'yes')
            ->assertSuccessful();

        $this->assertSame($global->id, (int) $childA->fresh()->agent_permission_group_id);
        $this->assertSame($global->id, (int) $childB->fresh()->agent_permission_group_id);
    }

    #[TestDox('角色被前置補丁降級後重跑仍認得，不會撞同名')]
    public function test_rerun_recovers_demoted_group(): void
    {
        $global = $this->makeGlobalGroup([1, 4]);
        $top = $this->makeAgent('Ttop' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);
        $this->makeAgent(
            'Tchild' . random_int(1000, 9999),
            "/1/{$top->id}",
            RoleEnum::AGENT->value,
            $global->id,
        );

        $this->artisan('patch:split-default-agent-permission-group', ['--tops' => (string) $top->id])
            ->assertSuccessful();

        $group = AgentPermissionGroup::query()->where('owner_agent_id', $top->id)->first();
        $this->groupIds[] = $group->id;

        // 模擬 patch:create-default-agent-permission-group 重跑，把其他角色一律降級
        AgentPermissionGroup::query()->where('id', $group->id)->update(['default_setting' => 0]);

        // 重跑應認得這筆並修回預設，而不是走建立分支撞同名
        $this->artisan('patch:split-default-agent-permission-group', ['--tops' => (string) $top->id])
            ->assertSuccessful();

        $this->assertSame(1, (int) $group->fresh()->default_setting, '重跑應把被降級的角色修回預設');

        $this->assertSame(
            1,
            AgentPermissionGroup::query()->where('owner_agent_id', $top->id)->count(),
            '不應重複建立角色',
        );
    }

    #[TestDox('回滾還原代理的原角色綁定')]
    public function test_rollback_restores_original_binding(): void
    {
        $global = $this->makeGlobalGroup([1, 4]);
        $top = $this->makeAgent('Ttop' . random_int(1000, 9999), '/1', RoleEnum::GENERAL_AGENT->value, null);
        $child = $this->makeAgent(
            'Tchild' . random_int(1000, 9999),
            "/1/{$top->id}",
            RoleEnum::AGENT->value,
            $global->id,
        );

        $this->artisan('patch:split-default-agent-permission-group', ['--tops' => (string) $top->id])->assertSuccessful();

        $group = AgentPermissionGroup::query()->where('owner_agent_id', $top->id)->first();
        $this->groupIds[] = $group->id;

        $log = AgentPermissionGroupMigrationLog::query()->where('agent_id', $child->id)->first();
        $this->assertNotNull($log, '應寫入備份紀錄');
        $this->assertSame($global->id, (int) $log->from_group_id);

        $this->artisan('patch:split-default-agent-permission-group', ['--rollback' => $log->batch])
            ->expectsConfirmation('確定執行？', 'yes')
            ->assertSuccessful();

        $this->assertSame(
            $global->id,
            (int) $child->fresh()->agent_permission_group_id,
            '回滾後應還原為全域角色',
        );

        $this->assertSame(1, (int) $log->fresh()->rolled_back);
    }
}
