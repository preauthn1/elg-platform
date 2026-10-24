<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use App\Http\Middleware\Frontend\AuthMember;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\MemberRestrictionRule;
use App\Services\MemberRestrictionGuard;
use App\Services\WithdrawalService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 風控會員限制 enforcement 真值表（4118）。
 *
 * php artisan test tests/Unit/Services/MemberRestrictionGuardTest.php
 */
class MemberRestrictionGuardTest extends TestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = $this->createAgentAndDomain();
    }

    private function guard(): MemberRestrictionGuard
    {
        return app(MemberRestrictionGuard::class);
    }

    /** factory 預設 username 為固定值，需自帶唯一帳號避免撞 members_username_unique */
    private function newMember(Agent $agent, array $data = []): Member
    {
        return $this->createMember($agent, array_merge(['username' => 'mr' . bin2hex(random_bytes(6))], $data));
    }

    private function makeRule(string $targetType, int $targetId, array $overrides = []): MemberRestrictionRule
    {
        return MemberRestrictionRule::create(array_merge([
            'agent_id' => $this->agent->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'status' => 1,
            'is_disabled' => 0,
            'withdrawal_locked' => 0,
            'admin' => $this->agent->id,
        ], $overrides));
    }

    #[TestDox('無任何規則時兩旗標皆 false')]
    public function test_no_rule_means_not_blocked(): void
    {
        $member = $this->newMember($this->agent);

        $this->assertFalse($this->guard()->isLoginBlocked($member));
        $this->assertFalse($this->guard()->isWithdrawalLocked($member));
    }

    #[TestDox('會員型規則：is_disabled=1 只擋登入、withdrawal_locked=1 只擋提現（旗標互不影響）')]
    public function test_member_rule_flags_are_independent(): void
    {
        $memberA = $this->newMember($this->agent);
        $memberB = $this->newMember($this->agent);
        $this->makeRule('member', $memberA->id, ['is_disabled' => 1]);
        $this->makeRule('member', $memberB->id, ['withdrawal_locked' => 1]);

        $this->assertTrue($this->guard()->isLoginBlocked($memberA));
        $this->assertFalse($this->guard()->isWithdrawalLocked($memberA));

        $this->assertFalse($this->guard()->isLoginBlocked($memberB));
        $this->assertTrue($this->guard()->isWithdrawalLocked($memberB));
    }

    #[TestDox('規則總開關 status=0 時整筆不生效')]
    public function test_disabled_rule_is_ignored(): void
    {
        $member = $this->newMember($this->agent);
        $this->makeRule('member', $member->id, [
            'status' => 0,
            'is_disabled' => 1,
            'withdrawal_locked' => 1,
        ]);

        $this->assertFalse($this->guard()->isLoginBlocked($member));
        $this->assertFalse($this->guard()->isWithdrawalLocked($member));
    }

    #[TestDox('群組型規則作用於群組內成員；未入組的會員不受影響')]
    public function test_group_rule_applies_to_group_members_only(): void
    {
        $group = MemberGroup::create(['agent_id' => $this->agent->id, 'name' => 'g-' . uniqid(), 'status' => 1]);
        $inGroup = $this->newMember($this->agent, ['member_group_id' => $group->id]);
        $outside = $this->newMember($this->agent);
        $this->makeRule('member_group', $group->id, ['is_disabled' => 1, 'withdrawal_locked' => 1]);

        $this->assertTrue($this->guard()->isLoginBlocked($inGroup));
        $this->assertTrue($this->guard()->isWithdrawalLocked($inGroup));
        $this->assertFalse($this->guard()->isLoginBlocked($outside));
        $this->assertFalse($this->guard()->isWithdrawalLocked($outside));
    }

    #[TestDox('群組被停用（member_groups.status=0）時風控封鎖仍生效（刻意不同於限額語意）')]
    public function test_group_rule_survives_disabled_group(): void
    {
        $group = MemberGroup::create(['agent_id' => $this->agent->id, 'name' => 'g-' . uniqid(), 'status' => 0]);
        $member = $this->newMember($this->agent, ['member_group_id' => $group->id]);
        $this->makeRule('member_group', $group->id, ['is_disabled' => 1]);

        $this->assertTrue($this->guard()->isLoginBlocked($member));
    }

    #[TestDox('會員型與群組型同時命中時旗標取 OR')]
    public function test_member_and_group_rules_or_aggregate(): void
    {
        $group = MemberGroup::create(['agent_id' => $this->agent->id, 'name' => 'g-' . uniqid(), 'status' => 1]);
        $member = $this->newMember($this->agent, ['member_group_id' => $group->id]);
        $this->makeRule('member', $member->id, ['withdrawal_locked' => 1]);
        $this->makeRule('member_group', $group->id, ['is_disabled' => 1]);

        $this->assertTrue($this->guard()->isLoginBlocked($member));
        $this->assertTrue($this->guard()->isWithdrawalLocked($member));
    }

    #[TestDox('AuthMember middleware：被停用會員的授權請求回 HTTP_REDIRECT_LOGOUT（自然踢線）')]
    public function test_auth_member_middleware_blocks_disabled_member(): void
    {
        $member = $this->newMember($this->agent);
        $token = [REDACTED];
        $this->makeRule('member', $member->id, ['is_disabled' => 1]);

        $request = Request::create('/frontend/api/anything', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = app(AuthMember::class)->handle($request, fn () => response('should-not-reach'));
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(HttpCode::HTTP_REDIRECT_LOGOUT->value, (int) $payload['code']);
    }

    #[TestDox('AuthMember middleware：僅提款鎖定（未停用）的會員可正常通過授權')]
    public function test_auth_member_middleware_passes_withdrawal_locked_member(): void
    {
        $member = $this->newMember($this->agent);
        $token = [REDACTED];
        $this->makeRule('member', $member->id, ['withdrawal_locked' => 1]);

        $request = Request::create('/frontend/api/anything', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = app(AuthMember::class)->handle($request, fn () => response('passed'));

        $this->assertSame('passed', $response->getContent());
    }

    #[TestDox('WithdrawalService::checkMemberRestriction：提款鎖定時拋 ApiException')]
    public function test_withdrawal_service_check_throws_when_locked(): void
    {
        $member = $this->newMember($this->agent);
        $this->makeRule('member', $member->id, ['withdrawal_locked' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.member_restriction.withdrawal_locked'));

        app(WithdrawalService::class)->checkMemberRestriction($member);
    }

    #[TestDox('WithdrawalService::checkMemberRestriction：未鎖定時不拋例外')]
    public function test_withdrawal_service_check_passes_when_not_locked(): void
    {
        $member = $this->newMember($this->agent);
        $this->makeRule('member', $member->id, ['is_disabled' => 1]); // 僅停用登入，不鎖提款

        app(WithdrawalService::class)->checkMemberRestriction($member);

        $this->assertTrue(true);
    }

    #[TestDox('morph 關聯：target() 依 alias 解析回 Member / MemberGroup')]
    public function test_target_morph_resolves(): void
    {
        $group = MemberGroup::create(['agent_id' => $this->agent->id, 'name' => 'g-' . uniqid(), 'status' => 1]);
        $member = $this->newMember($this->agent);

        $memberRule = $this->makeRule('member', $member->id);
        $groupRule = $this->makeRule('member_group', $group->id);

        $this->assertInstanceOf(Member::class, $memberRule->target()->first());
        $this->assertInstanceOf(MemberGroup::class, $groupRule->target()->first());
    }
}
