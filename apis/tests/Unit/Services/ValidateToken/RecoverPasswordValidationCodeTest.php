<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ValidateToken;

use App\CacheKeys\CacheKeys;
use App\Enums\General\HttpCode;
use App\Enums\MemberStatusEnum;
use App\Enums\ValidationCodeTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberStatusLog;
use App\Models\ValidateToken;
use App\Services\MemberAccessTokenService;
use App\Services\ValidateToken\CheckValidationCodeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Services/ValidateToken/RecoverPasswordValidationCodeTest.php
 */
class RecoverPasswordValidationCodeTest extends TestCase
{
    private const int FAIL_LIMIT = 3;

    private CheckValidationCodeService $service;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CheckValidationCodeService::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, [
            'mobile' => '0912345678',
            'mobile_validate' => 1,
        ]);
    }

    public function test_type3_wrong_code_increments_validation_code_fail_count(): void
    {
        $guestToken = [REDACTED];

        $this->createValidateToken('111111');

        try {
            $this->checkCode('000000', $guestToken);
            $this->fail('Expected ApiException was not thrown');
        } catch (ApiException $e) {
            $this->assertSame(__('error_message.9992'), $e->getMessage());
        }

        $this->member->refresh();
        $this->member->load('statistics');
        $this->assertSame(1, $this->member->statistics->validation_code_fail_count);
        $this->assertSame(MemberStatusEnum::VERIFIED->value, $this->member->status);
    }

    public function test_type3_wrong_code_locks_account_after_three_failures(): void
    {
        $guestToken = [REDACTED];

        $this->createValidateToken('222222');

        for ($i = 0; $i < self::FAIL_LIMIT; $i++) {
            try {
                $this->checkCode('000000', $guestToken);
                $this->fail('Expected ApiException was not thrown');
            } catch (ApiException $e) {
                if ($i < self::FAIL_LIMIT - 1) {
                    $this->assertSame(__('error_message.9992'), $e->getMessage());
                } else {
                    $this->assertSame(__('error_message.90062'), $e->getMessage());
                    $this->assertSame(HttpCode::HTTP_REDIRECT_LOGOUT->value, $e->getCode());
                }
            }
        }

        $this->member->refresh();
        $this->member->load('statistics');
        $this->assertSame(self::FAIL_LIMIT, $this->member->statistics->validation_code_fail_count);
        $this->assertSame(MemberStatusEnum::LOCKED->value, $this->member->status);
    }

    /**
     * 達上限後作廢 token 失敗：帳號仍維持鎖定、回應照常是鎖帳，作廢失敗只告警。
     * 找回密碼不用先登入就能打，鎖不上的話攻擊者可以一直猜驗證碼。
     */
    public function test_type3_lock_stays_when_revoke_fails(): void
    {
        Exceptions::fake();
        $tokenService = Mockery::mock(MemberAccessTokenService::class)->makePartial();
        $tokenService->shouldReceive('revoke')->once()->andThrow(new RuntimeException('simulated revoke failure'));
        $this->app->instance(MemberAccessTokenService::class, $tokenService);
        $this->service = app(CheckValidationCodeService::class);

        $guestToken = [REDACTED];
        $this->createValidateToken('555555');

        $lastException = null;
        for ($i = 0; $i < self::FAIL_LIMIT; $i++) {
            try {
                $this->checkCode('000000', $guestToken);
                $this->fail('Expected ApiException was not thrown');
            } catch (ApiException $e) {
                $lastException = $e;
            }
        }

        $this->assertSame(__('error_message.90062'), $lastException?->getMessage());
        $this->assertSame(HttpCode::HTTP_REDIRECT_LOGOUT->value, $lastException?->getCode());

        $this->member->refresh();
        $this->member->load('statistics');
        $this->assertSame(MemberStatusEnum::LOCKED->value, $this->member->status, '作廢 token 失敗時帳號仍應維持鎖定');
        $this->assertSame(self::FAIL_LIMIT, $this->member->statistics->validation_code_fail_count, '作廢 token 失敗不應回滾失敗次數');
        $this->assertSame(1, MemberStatusLog::where('member_id', $this->member->id)->count());
        Exceptions::assertReported(
            static fn (RuntimeException $e): bool => $e->getMessage() === 'simulated revoke failure'
        );
    }

    public function test_type3_success_resets_validation_code_fail_count(): void
    {
        $guestToken = [REDACTED];
        $correctToken = [REDACTED]';

        $validateToken = [REDACTED];

        $this->member->statistics()->firstOrCreate([])->update(['validation_code_fail_count' => self::FAIL_LIMIT]);

        $member = $this->checkCode($correctToken, $guestToken);

        $this->assertSame($this->member->id, $member->id);

        $this->member->refresh();
        $this->member->load('statistics');
        $this->assertSame(0, $this->member->statistics->validation_code_fail_count);

        $validateToken->refresh();
        $this->assertSame(0, $validateToken->status, '找回密碼驗證成功後驗證碼應保留給重設密碼使用');
    }

    public function test_type3_guest_member_mismatch_throws(): void
    {
        $otherMember = $this->createMember($this->agent, [
            'username' => 'other_' . uniqid(),
            'mobile' => '0988777666',
            'mobile_validate' => 1,
        ]);

        $guestToken = [REDACTED]';
        Cache::put(CacheKeys::guestValidateToken($guestToken), $otherMember->id, 3600);

        $this->createValidateToken('444444');

        // 工單 4540：與驗證碼錯誤同一個訊息，回應不能讓人分辨碼其實是對的
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.9992'));

        $this->checkCode('444444', $guestToken);
    }

    private function putGuestValidateToken(): string
    {
        $guestToken = [REDACTED]' . $this->member->id;
        Cache::put(CacheKeys::guestValidateToken($guestToken), $this->member->id, 3600);

        return $guestToken;
    }

    private function checkCode(string $token, string $guestValidateToken): Member
    {
        return $this->service->checkCodeProcess([
            'domain' => $this->domain,
            'mobile' => $this->member->mobile,
            'token' => $token,
            'type' => ValidationCodeTypeEnum::RECOVER_PASSWORD->value,
            'guest_validate_token' => $guestValidateToken,
        ]);
    }

    private function createValidateToken(string $token): ValidateToken
    {
        return ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'type_id' => (int) (ValidationCodeTypeEnum::RECOVER_PASSWORD->value . '1'),
            'info' => $this->member->mobile,
            'info_2' => '',
            'token' => $token,
            'status' => 0,
            'expire_date' => now()->addHour()->toDateTimeString(),
            'admin' => $this->member->id,
        ]);
    }
}
