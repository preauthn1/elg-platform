<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\AgentPermission\Concerns;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\RoleEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\AgentPermissionGroup;
use App\Models\AgentPermissionGroupMenu;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * 代理權限接口測試共用的資料建構（target/tickets#4422）。
 *
 * 線路結構：總代（role 11，path /總代）→ 下線（path /總代/下線，hierarchy 含總代）；
 * admin 子帳號＝host_id 1 的帳號（不需要真的有 agents.id = 1 那一列）。
 */
trait BuildsAgentPermissionFixtures
{
    protected function bootAgentApi(): void
    {
        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
    }

    protected function actAs(Agent $agent): void
    {
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $agent->id,
            'token' => $token,
            'expire_date' => '2099-01-01 00:00:00',
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    protected function callApi(string $path, array $payload = []): TestResponse
    {
        return $this->postJson('agent/api/' . $path, $payload, $this->headers);
    }

    /** 不綁網域的代理（createAgentAndDomain 一個測試只能建一個，因為網域固定） */
    protected function bareAgent(array $data = []): Agent
    {
        $unique = bin2hex(random_bytes(8));
        $agent = Agent::factory()->create($data + [
            'code' => substr($unique, 0, 10),
            'username' => 'p' . substr($unique, 0, 15),
            'email' => substr($unique, 0, 12) . '@t.ag',
            'password' => Hash::make('123456'),
            'status' => 1,
            'role_id' => RoleEnum::AGENT->value,
            'host_id' => 0,
            'agent_permission_group_id' => null,
        ]);
        $agent->forceFill(['hierarchy_path' => "/{$agent->id}", 'hierarchy' => "[-1,{$agent->id},-2]"])->save();

        return $agent->refresh();
    }

    /** 總代（自己一條線） */
    protected function topAgent(): Agent
    {
        return $this->bareAgent(['role_id' => RoleEnum::GENERAL_AGENT->value]);
    }

    /** 總代底下的下線 */
    protected function downline(Agent $top, array $data = []): Agent
    {
        $agent = $this->bareAgent(['role_id' => RoleEnum::AGENT->value] + $data);
        $agent->forceFill([
            'parent' => $top->id,
            'hierarchy_path' => $top->hierarchy_path . '/' . $agent->id,
            'hierarchy' => "[-1,{$agent->id},{$top->id},-2]",
        ])->save();

        return $agent->refresh();
    }

    /** super admin 的子帳號（host_id = 1） */
    protected function adminSubAccount(): Agent
    {
        $agent = $this->bareAgent(['role_id' => RoleEnum::ADMIN->value]);
        $agent->forceFill([
            'host_id' => AgentPermissionGroup::ADMIN_OWNER_ID,
            'parent' => AgentPermissionGroup::ADMIN_OWNER_ID,
            'hierarchy_path' => '/1/' . $agent->id,
            'hierarchy' => "[-1,{$agent->id},1,-2]",
        ])->save();

        return $agent->refresh();
    }

    protected function menu(string $code, array $data = []): AgentMenu
    {
        $menu = AgentMenu::query()->firstOrCreate(
            ['code' => $code],
            $data + ['name' => $code, 'parent' => 0, 'url' => '', 'symbol' => '', 'action' => '', 'sort' => 0, 'status' => 1, 'admin' => 0],
        );
        Cache::forget(CacheKeys::agentMenuCodeMap());

        return $menu;
    }

    /** 給代理個人權限（選單代碼不存在就建） */
    protected function grant(Agent $agent, string ...$codes): void
    {
        foreach ($codes as $code) {
            AgentPermission::query()->create([
                'agent_id' => $agent->id,
                'agent_menu_id' => $this->menu($code)->id,
                'action' => '',
                'admin' => 0,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $menuIds
     */
    protected function role(string $name, ?int $ownerAgentId, array $menuIds = [], int $default = 0, int $status = 1): AgentPermissionGroup
    {
        $group = AgentPermissionGroup::query()->create([
            'name' => $name . '_' . bin2hex(random_bytes(3)),
            'owner_agent_id' => $ownerAgentId,
            'remark' => null,
            'status' => $status,
            'default_setting' => $default,
            'admin' => 0,
        ]);
        foreach ($menuIds as $menuId) {
            AgentPermissionGroupMenu::query()->create([
                'agent_permission_group_id' => $group->id,
                'agent_menu_id' => $menuId,
                'action' => '',
                'admin' => 0,
            ]);
        }

        return $group;
    }

    protected function bindRole(Agent $agent, AgentPermissionGroup $group): void
    {
        $agent->forceFill(['agent_permission_group_id' => $group->id])->save();
    }
}
