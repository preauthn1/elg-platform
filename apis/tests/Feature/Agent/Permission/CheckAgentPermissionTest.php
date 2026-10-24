<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Permission;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\AgentPermissionGroup;
use App\Models\AgentPermissionGroupMenu;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 代理後台功能級授權（稽核 #13）。
 *
 * 以 agent/api/checkin/list 為代表：該端點需要 checkin_index 選單權限。
 *
 * php artisan test tests/Feature/Agent/Permission/CheckAgentPermissionTest.php
 */
class CheckAgentPermissionTest extends TestCase
{
    private string $apiPath = 'agent/api/checkin/list';

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 AES 解密與日誌中間件，保留 AuthAgent + MergeAgentData 讓 agent context 正確注入
        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();
        $this->loginAs($this->agent);
    }

    private function loginAs(Agent $agent): void
    {
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    private function createCheckinMenu(): AgentMenu
    {
        return AgentMenu::create([
            'code' => 'checkin_index',
            'name' => '打卡設置',
            'parent' => 0,
            'url' => 'checkin/index',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);
    }

    /**
     * 沒有選單權限的代理不得呼叫——這正是 #13：原本任何有效 agent token 都打得到。
     */
    public function test_rejects_agent_without_menu_permission(): void
    {
        $this->createCheckinMenu();

        $response = $this->requestApi($this->apiPath);

        $response->assertJsonPath('message', __('error_message.9003'));
    }

    /**
     * 個人權限（agent_permissions）。
     */
    public function test_allows_agent_with_personal_permission(): void
    {
        $menu = $this->createCheckinMenu();

        AgentPermission::create([
            'agent_id' => $this->agent->id,
            'agent_menu_id' => $menu->id,
            'action' => '',
            'admin' => 0,
        ]);

        $response = $this->requestApi($this->apiPath);

        $response->assertJsonPath('code', 200);
    }

    /**
     * 角色權限（agent_permission_group_menus，工單4080）：最終權限為個人 ∪ 角色。
     */
    public function test_allows_agent_with_permission_group(): void
    {
        $menu = $this->createCheckinMenu();

        $group = AgentPermissionGroup::create([
            'name' => '活動維運',
            'status' => 1,
            'default_setting' => 0,
            'admin' => 0,
        ]);

        AgentPermissionGroupMenu::create([
            'agent_permission_group_id' => $group->id,
            'agent_menu_id' => $menu->id,
            'action' => '',
            'admin' => 0,
        ]);

        $this->agent->update(['agent_permission_group_id' => $group->id]);

        $response = $this->requestApi($this->apiPath);

        $response->assertJsonPath('code', 200);
    }

    /**
     * agent_menus 沒有該 code 時放行（fail-open）：各白標選單資料不一致，
     * 擋下來會讓沒建該選單的白標整支端點不可用。
     */
    public function test_allows_when_menu_code_not_configured(): void
    {
        // 刻意不建立 checkin_index 選單

        $response = $this->requestApi($this->apiPath);

        $response->assertJsonPath('code', 200);
    }

    /**
     * 平台端（admin 子帳號）不受選單權限限制。
     */
    public function test_allows_platform_admin_sub_account(): void
    {
        $this->createCheckinMenu();

        // 直接用 factory：createAgentAndDomain() 綁的測試網域有 unique 限制，同測試建第二個會撞
        $adminSubAccount = Agent::factory()->create(['host_id' => 1]);
        $this->loginAs($adminSubAccount);

        $response = $this->requestApi($this->apiPath);

        $response->assertJsonPath('code', 200);
    }
}
