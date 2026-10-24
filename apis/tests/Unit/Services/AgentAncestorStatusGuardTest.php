<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Agent\AuthAgent;
use App\Http\Middleware\Frontend\AuthMember;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\Member;
use App\Services\AgentAncestorStatusGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 上級代理停用 enforcement 真值表（4130）。
 *
 * php artisan test tests/Unit/Services/AgentAncestorStatusGuardTest.php
 */
class AgentAncestorStatusGuardTest extends TestCase
{
    private Agent $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->createAgentAndDomain();
    }

    private function guard(): AgentAncestorStatusGuard
    {
        return app(AgentAncestorStatusGuard::class);
    }

    /** 於 $parent 底下建立下級代理（hierarchy_path 依正式建立規則串接） */
    private function makeChildAgent(Agent $parent, array $data = []): Agent
    {
        $unique = bin2hex(random_bytes(8));

        $agent = Agent::factory()->create(array_merge([
            'code' => substr($unique, 0, 10),
            'username' => 'a' . substr($unique, 0, 15),
            'email' => substr($unique, 0, 12) . '@t.ag',
            'password' => Hash::make('123456'),
            'status' => 1,
            'parent' => $parent->id,
            'organization_id' => $parent->organization_id,
        ], $data));

        $agent->hierarchy_path = $parent->hierarchy_path . '/' . $agent->id;
        $agent->save();

        return $agent;
    }

    /** factory 預設 username 為固定值，需自帶唯一帳號避免撞 members_username_unique */
    private function newMember(Agent $agent, array $data = []): Member
    {
        return $this->createMember($agent, array_merge(['username' => 'up' . bin2hex(random_bytes(6))], $data));
    }

    private function createAgentToken(Agent $agent): string
    {
        $token = [REDACTED];

        AgentAccessToken:[REDACTED]
            'agent_id' => $agent->id,
            'token' => $token,
            'expire_date' => date('Y-m-d H:i:s', strtotime('+60 minute')),
            'admin' => 1,
        ]);

        return $token;
    }

    #[TestDox('整條上線皆啟用時，會員與代理視角皆不封鎖')]
    public function test_all_active_ancestor_is_not_blocked(): void
    {
        $child = $this->makeChildAgent($this->root);
        $grandChild = $this->makeChildAgent($child);

        $this->assertFalse($this->guard()->isMemberAncestorDisabled($grandChild));
        $this->assertFalse($this->guard()->isAgentAncestorDisabled($grandChild));
    }

    #[TestDox('會員視角：直屬代理停用即封鎖（直屬代理即會員的上級）')]
    public function test_member_view_blocks_when_direct_agent_disabled(): void
    {
        $child = $this->makeChildAgent($this->root, ['status' => 0]);

        $this->assertTrue($this->guard()->isMemberAncestorDisabled($child));
    }

    #[TestDox('祖先層（非直屬）停用時，會員與下級代理視角皆封鎖')]
    public function test_ancestor_disabled_blocks_member_and_agent_views(): void
    {
        $child = $this->makeChildAgent($this->root);
        $grandChild = $this->makeChildAgent($child);

        $child->update(['status' => 0]);
        $grandChild->refresh();

        $this->assertTrue($this->guard()->isMemberAncestorDisabled($grandChild));
        $this->assertTrue($this->guard()->isAgentAncestorDisabled($grandChild));
    }

    #[TestDox('代理視角：自身停用不算上級停用（自身狀態由既有檢查處理）')]
    public function test_agent_view_excludes_self(): void
    {
        $child = $this->makeChildAgent($this->root, ['status' => 0]);

        $this->assertFalse($this->guard()->isAgentAncestorDisabled($child));
    }

    #[TestDox('子帳號：host 主帳號停用即封鎖（host 在 hierarchy_path 祖先鏈上）')]
    public function test_sub_account_blocked_when_host_disabled(): void
    {
        $host = $this->makeChildAgent($this->root);
        $subAccount = $this->makeChildAgent($host, ['host_id' => $host->id]);

        $host->update(['status' => 0]);
        $subAccount->refresh();

        $this->assertTrue($this->guard()->isAgentAncestorDisabled($subAccount));
    }

    #[TestDox('hierarchy_path 缺失時不誤鎖：代理視角 false、會員視角只看直屬代理自身狀態')]
    public function test_missing_hierarchy_path_does_not_false_positive(): void
    {
        $orphan = $this->makeChildAgent($this->root);
        $orphan->hierarchy_path = null;
        $orphan->save();
        $orphan->refresh();

        $this->assertFalse($this->guard()->isAgentAncestorDisabled($orphan));
        $this->assertFalse($this->guard()->isMemberAncestorDisabled($orphan));

        $orphan->update(['status' => 0]);
        $orphan->refresh();

        $this->assertTrue($this->guard()->isMemberAncestorDisabled($orphan));
    }

    #[TestDox('AuthMember middleware：上級代理停用的會員授權請求回 HTTP_REDIRECT_LOGOUT＋ancestor_disabled 訊息（自然踢線）')]
    public function test_auth_member_middleware_kicks_member_under_disabled_ancestor(): void
    {
        $child = $this->makeChildAgent($this->root);
        $member = $this->newMember($child);
        $token = [REDACTED];

        $this->root->update(['status' => 0]);

        $request = Request::create('/frontend/api/anything', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = app(AuthMember::class)->handle($request, fn () => response('should-not-reach'));
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(HttpCode::HTTP_REDIRECT_LOGOUT->value, (int) $payload['code']);
        $this->assertSame(__('error_message.agent_status.ancestor_disabled'), $payload['message']);
    }

    #[TestDox('AuthAgent middleware：上級代理停用的下級代理授權請求回 HTTP_REDIRECT_LOGOUT＋ancestor_disabled 訊息（自然踢線）')]
    public function test_auth_agent_middleware_kicks_agent_under_disabled_ancestor(): void
    {
        $child = $this->makeChildAgent($this->root);
        $token = [REDACTED];

        $this->root->update(['status' => 0]);

        $request = Request::create('/agent/api/anything', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = app(AuthAgent::class)->handle($request, fn () => response('should-not-reach'));
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(HttpCode::HTTP_REDIRECT_LOGOUT->value, (int) $payload['code']);
        $this->assertSame(__('error_message.agent_status.ancestor_disabled'), $payload['message']);
    }

    #[TestDox('AuthAgent middleware：代理自身停用回 HTTP_REDIRECT_LOGOUT（既有 token 未過期也擋）')]
    public function test_auth_agent_middleware_kicks_disabled_agent_itself(): void
    {
        $child = $this->makeChildAgent($this->root);
        $token = [REDACTED];

        $child->update(['status' => 0]);

        $request = Request::create('/agent/api/anything', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = app(AuthAgent::class)->handle($request, fn () => response('should-not-reach'));
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(HttpCode::HTTP_REDIRECT_LOGOUT->value, (int) $payload['code']);
        $this->assertSame(__('error_message.90062'), $payload['message']);
    }

    #[TestDox('AuthAgent middleware：上線健全的代理可正常通過授權')]
    public function test_auth_agent_middleware_passes_healthy_agent(): void
    {
        $child = $this->makeChildAgent($this->root);
        $token = [REDACTED];

        $request = Request::create('/agent/api/anything', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = app(AuthAgent::class)->handle($request, fn () => response('passed'));

        $this->assertSame('passed', $response->getContent());
    }
}
