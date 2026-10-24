<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Member;

use App\Enums\Agent\RoleEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentPermissionGroup;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 會員詳情抽屜的身份門檻（target/tickets#4438 第 7 項，PM 定案）。
 *
 * 必須成立：
 * 1. 系統管理者（含子帳號）、總代理（含子帳號，子帳號 role_id 常為 0 要看主帳號）、客服：能用。
 * 2. 一般代理（role_id 0 或 21）及其子帳號：不能用；detail/* 接口回「權限不足」，access 接口本身不擋。
 *
 * php artisan test tests/Feature/Agent/Member/MemberDrawerAccessTest.php
 */
class MemberDrawerAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
    }

    public function test_general_agent_and_customer_service_can_use_drawer(): void
    {
        $top = $this->createAgentAndDomain(['role_id' => RoleEnum::GENERAL_AGENT->value]);
        $this->assertTrue($this->accessAs($top), '總代理');

        $topSub = Agent::factory()->create(['host_id' => $top->id, 'parent' => $top->id, 'role_id' => 0]);
        $this->assertTrue($this->accessAs($topSub), '總代理子帳號（自己 role_id 0，看主帳號）');

        $cs = Agent::factory()->create(['host_id' => $top->id, 'parent' => $top->id, 'role_id' => RoleEnum::CUSTOMER_SERVICE->value]);
        $this->assertTrue($this->accessAs($cs), '客服');
    }

    public function test_platform_admin_and_sub_accounts_can_use_drawer(): void
    {
        $adminSub = Agent::factory()->create(['host_id' => AgentPermissionGroup::ADMIN_OWNER_ID, 'parent' => AgentPermissionGroup::ADMIN_OWNER_ID, 'role_id' => 0]);

        $this->assertTrue($this->accessAs($adminSub), '系統管理者子帳號');
    }

    public function test_general_downline_agent_cannot_use_drawer_and_detail_api_is_blocked(): void
    {
        $top = $this->createAgentAndDomain(['role_id' => RoleEnum::GENERAL_AGENT->value]);
        $agent = Agent::factory()->create(['parent' => $top->id, 'role_id' => 0]);
        $agentSub = Agent::factory()->create(['host_id' => $agent->id, 'parent' => $agent->id, 'role_id' => 0]);

        $this->assertFalse($this->accessAs($agent), '一般代理');
        $this->assertFalse($this->accessAs($agentSub), '一般代理子帳號');

        $member = $this->createMember($top, ['username' => 'mda' . bin2hex(random_bytes(5))]);
        $this->loginAs($agent);
        // 同 CheckAgentPermission：擋下回 400、訊息為「權限不足」
        $blocked = $this->postJson('agent/api/member/detail/view', ['member_id' => $member->id], $this->headers);
        $this->assertNotSame(200, $blocked->json('code'), 'detail/view 擋一般代理');
        $this->assertSame(__('error_message.9003'), $blocked->json('message'));
    }

    private function accessAs(Agent $agent): bool
    {
        $this->loginAs($agent);

        return (bool) $this->postJson('agent/api/member/detail/access', [], $this->headers)->assertOk()->json('data.can_use_drawer');
    }

    private function loginAs(Agent $agent): void
    {
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]'agent_id' => $agent->id, 'token' => $token, 'expire_date' => '2099-01-01 00:00:00', 'admin' => 1]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }
}
