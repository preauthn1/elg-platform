<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ValidateToken;

use App\Enums\General\HttpCode;
use App\Enums\MemberStatusEnum;
use App\Enums\MemberStatusLogTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Models\MemberAccessToken;
use App\Models\MemberStatusLog;
use App\Services\AgentService;
use App\Services\EmailGatewayService;
use App\Services\EmailTextService;
use App\Services\MemberAccessTokenService;
use App\Services\MemberFailCountService;
use App\Services\MemberService;
use App\Services\ValidateToken\AbstractValidationCodeService;
use Illuminate\Support\Facades\Exceptions;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 驗證碼流程的鎖帳回應（AbstractValidationCodeService）
 *
 * - throwMemberLockedException()：發送驗證碼時手機或信箱比對錯到上限，鎖帳後作廢 token。
 *   訪客發送（找回密碼）不用先登入、會員發送（找回支付密碼）要先登入，兩者都在作廢 token 失敗時維持鎖定。
 * - throwMemberAlreadyLockedException()：讀到的會員已經是鎖定，只作廢 token、不鎖帳。
 *
 * php artisan test tests/Unit/Services/ValidateToken/ThrowMemberLockedExceptionTest.php
 */
class ThrowMemberLockedExceptionTest extends TestCase
{
    /**
     * 取得可呼叫 protected 鎖帳回應方法的探針
     */
    private function probe(): object
    {
        return new class(app(AgentService::class), app(MemberService::class), app(MemberAccessTokenService::class), app(EmailGatewayService::class), app(EmailTextService::class), app(MemberFailCountService::class)) extends AbstractValidationCodeService
        {
            public function lock(Member $member): never
            {
                $this->throwMemberLockedException($member);
            }

            public function alreadyLocked(Member $member): never
            {
                $this->throwMemberAlreadyLockedException($member);
            }
        };
    }

    private function lockAndCatch(Member $member): ApiException
    {
        try {
            $this->probe()->lock($member);
        } catch (ApiException $e) {
            return $e;
        }

        $this->fail('Expected ApiException was not thrown');
    }

    private function alreadyLockedAndCatch(Member $member): ApiException
    {
        try {
            $this->probe()->alreadyLocked($member);
        } catch (ApiException $e) {
            return $e;
        }

        $this->fail('Expected ApiException was not thrown');
    }

    private function createActiveToken(Member $member): MemberAccessToken
    {
        return MemberAccessToken:[REDACTED]
            'member_id' => $member->id,
            'token' => 'vc-lock-token-' . uniqid(),
            'expire_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'admin' => 0,
        ]);
    }

    public function test_locks_member_revokes_tokens_and_responds_logout(): void
    {
        $member = $this->createMember($this->createAgentAndDomain(), ['username' => 'vclock_' . uniqid()]);
        $token = [REDACTED];

        $e = $this->lockAndCatch($member);

        $this->assertSame(__('error_message.90062'), $e->getMessage());
        $this->assertSame(HttpCode::HTTP_REDIRECT_LOGOUT->value, $e->getCode());
        $this->assertSame(MemberStatusEnum::LOCKED->value, $member->fresh()->status);
        $logs = MemberStatusLog::where('member_id', $member->id)->get();
        $this->assertCount(1, $logs);
        $this->assertSame(MemberStatusLogTypeEnum::VERIFICATION_CODE_LIMIT, $logs->first()->type);
        $this->assertLessThanOrEqual(time(), strtotime($token->fresh()->expire_date), '有效 token 應被作廢');
    }

    public function test_lock_stays_when_revoke_fails(): void
    {
        Exceptions::fake();
        $tokenService = Mockery::mock(MemberAccessTokenService::class)->makePartial();
        $tokenService->shouldReceive('revoke')->once()->andThrow(new RuntimeException('simulated revoke failure'));
        $this->app->instance(MemberAccessTokenService::class, $tokenService);

        $member = $this->createMember($this->createAgentAndDomain(), ['username' => 'vcrevokefail_' . uniqid()]);

        $e = $this->lockAndCatch($member);

        $this->assertSame(HttpCode::HTTP_REDIRECT_LOGOUT->value, $e->getCode(), '作廢 token 失敗時回應照常是鎖帳');
        $this->assertSame(MemberStatusEnum::LOCKED->value, $member->fresh()->status, '作廢 token 失敗時帳號仍應維持鎖定');
        $this->assertSame(1, MemberStatusLog::where('member_id', $member->id)->count());
        Exceptions::assertReported(
            static fn (RuntimeException $e): bool => $e->getMessage() === 'simulated revoke failure'
        );
    }

    /**
     * 找到的會員原本就被鎖定：不重寫狀態紀錄，但仍作廢 token
     */
    public function test_already_locked_member_gets_no_status_log_but_tokens_revoked(): void
    {
        $member = $this->createMember($this->createAgentAndDomain(), ['username' => 'vclocked_' . uniqid()]);
        $token = [REDACTED];
        Member::whereKey($member->id)->update(['status' => MemberStatusEnum::LOCKED->value]);

        $e = $this->lockAndCatch($member->fresh());

        $this->assertSame(HttpCode::HTTP_REDIRECT_LOGOUT->value, $e->getCode());
        $this->assertSame(0, MemberStatusLog::where('member_id', $member->id)->count(), '已鎖定的會員不應再寫一筆狀態紀錄');
        $this->assertLessThanOrEqual(time(), strtotime($token->fresh()->expire_date), '已鎖定的會員仍要作廢有效 token');
    }

    /**
     * 呼叫端從從庫讀到「已鎖定」，但客服剛在主庫解鎖：只作廢 token 並回應鎖帳，不能把帳號重新鎖上
     */
    public function test_already_locked_response_does_not_relock_member_unlocked_meanwhile(): void
    {
        $member = $this->createMember($this->createAgentAndDomain(), ['username' => 'vcunlocked_' . uniqid()]);
        $token = [REDACTED];
        $staleLockedMember = clone $member;
        $staleLockedMember->status = MemberStatusEnum::LOCKED->value;

        $e = $this->alreadyLockedAndCatch($staleLockedMember);

        $this->assertSame(__('error_message.90062'), $e->getMessage());
        $this->assertSame(HttpCode::HTTP_REDIRECT_LOGOUT->value, $e->getCode());
        $this->assertSame(MemberStatusEnum::VERIFIED->value, $member->fresh()->status, '客服剛解鎖的帳號不應被重新鎖上');
        $this->assertSame(0, MemberStatusLog::where('member_id', $member->id)->count());
        $this->assertLessThanOrEqual(time(), strtotime($token->fresh()->expire_date), '仍要作廢有效 token');
    }

    public function test_already_locked_response_still_responds_logout_when_revoke_fails(): void
    {
        Exceptions::fake();
        $tokenService = Mockery::mock(MemberAccessTokenService::class)->makePartial();
        $tokenService->shouldReceive('revoke')->once()->andThrow(new RuntimeException('simulated revoke failure'));
        $this->app->instance(MemberAccessTokenService::class, $tokenService);

        $member = $this->createMember($this->createAgentAndDomain(), [
            'username' => 'vcalreadyfail_' . uniqid(),
            'status' => MemberStatusEnum::LOCKED->value,
        ]);

        $e = $this->alreadyLockedAndCatch($member);

        $this->assertSame(HttpCode::HTTP_REDIRECT_LOGOUT->value, $e->getCode(), '作廢 token 失敗時回應照常是鎖帳');
        Exceptions::assertReported(
            static fn (RuntimeException $e): bool => $e->getMessage() === 'simulated revoke failure'
        );
    }
}
