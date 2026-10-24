<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\Agent\RoleEnum;
use App\Models\Agent;
use App\Models\AgentPermissionGroup;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/ReleaseTopAgentNamedRolesToAdminTest.php
 *
 * patch:release-top-agent-named-roles（target/tickets#4543 第 6 項第一列）：名稱含「總代」且持有者是代理的角色
 * 改成 admin 持有（owner NULL、default 0）；admin預設角色（owner 1）與已是 NULL 的不動；綁定不動；--ids 可限定。
 */
class ReleaseTopAgentNamedRolesToAdminTest extends TestCase
{
    private const string COMMAND = 'patch:release-top-agent-named-roles';

    /** @var list<int> */
    private array $agentIds = [];

    /** @var list<int> */
    private array $groupIds = [];

    protected function tearDown(): void
    {
        if ($this->groupIds !== []) {
            AgentPermissionGroup::query()->whereIn('id', $this->groupIds)->delete();
        }
        if ($this->agentIds !== []) {
            Agent::query()->whereIn('id', $this->agentIds)->delete();
        }
        parent::tearDown();
    }

    private function agent(int $roleId = RoleEnum::GENERAL_AGENT->value): Agent
    {
        $unique = bin2hex(random_bytes(6));
        $agent = Agent::factory()->create([
            'code' => substr($unique, 0, 10), 'username' => 'a' . $unique, 'email' => $unique . '@t.ag',
            'status' => 1, 'role_id' => $roleId, 'host_id' => 0, 'agent_permission_group_id' => null,
        ]);
        $this->agentIds[] = $agent->id;

        return $agent;
    }

    private function group(string $name, ?int $owner, int $default = 0): AgentPermissionGroup
    {
        $group = AgentPermissionGroup::query()->create([
            'name' => $name, 'owner_agent_id' => $owner, 'status' => 1, 'default_setting' => $default, 'admin' => 0,
        ]);
        $this->groupIds[] = $group->id;

        return $group;
    }

    public function test_releases_only_agent_owned_roles_named_with_keyword_and_keeps_bindings(): void
    {
        $top = $this->agent();
        $named = $this->group('T4543總代', $top->id, default: 1);
        $other = $this->group('T4543客服', $top->id);
        $admin = $this->group('T4543總代admin', AgentPermissionGroup::ADMIN_OWNER_ID);
        $bound = $this->agent(RoleEnum::AGENT->value);
        $bound->forceFill(['agent_permission_group_id' => $named->id])->save();

        $this->artisan(self::COMMAND, ['--ids' => "{$named->id}"])->assertSuccessful();

        $this->assertNull($named->refresh()->owner_agent_id, '持有者改成 admin 持有（NULL）');
        $this->assertSame(0, $named->default_setting, '取消預設，由 ensure 命令替總代補');
        $this->assertSame($top->id, $other->refresh()->owner_agent_id, '名稱不含「總代」的不動');
        $this->assertSame(1, $admin->refresh()->owner_agent_id, 'admin預設角色（1）不動');
        $this->assertSame($named->id, (int) $bound->refresh()->agent_permission_group_id, '已綁定不動');
    }

    public function test_ids_must_all_match_and_dry_run_writes_nothing(): void
    {
        $top = $this->agent();
        $named = $this->group('T4543總代', $top->id);
        $adminOwned = $this->group('T4543總代共用', null);

        $this->artisan(self::COMMAND, ['--ids' => "{$named->id},{$adminOwned->id}"])->assertFailed();
        $this->assertSame($top->id, $named->refresh()->owner_agent_id, '有一個 id 不符就整批不動');

        $this->artisan(self::COMMAND, ['--ids' => "{$named->id}", '--dry-run' => true])->assertSuccessful();
        $this->assertSame($top->id, $named->refresh()->owner_agent_id);
    }

    /** 寫入一律要帶 --ids；不帶只能 dry-run 列候選 */
    public function test_write_requires_ids(): void
    {
        $top = $this->agent();
        $named = $this->group('T4543總代', $top->id);

        $this->artisan(self::COMMAND)->assertFailed();
        $this->assertSame($top->id, $named->refresh()->owner_agent_id);
        $this->artisan(self::COMMAND, ['--dry-run' => true])->assertSuccessful();
        $this->artisan(self::COMMAND, ['--ids' => ','])->assertFailed();
    }
}
