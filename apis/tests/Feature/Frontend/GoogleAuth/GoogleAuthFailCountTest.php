<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\GoogleAuth;

use App\Enums\General\HttpCode;
use App\Enums\MemberStatusEnum;
use App\Enums\MemberStatusLogTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberAccessToken;
use App\Models\MemberAuthentication;
use App\Models\MemberStatusLog;
use App\Services\MemberAccessTokenService;
use Illuminate\Support\Facades\Exceptions;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\TestCase;

/**
 * 會員 Google 2FA 錯誤次數上限鎖定（工單4146）。
 *
 * 次數記於 member_statistics.google_auth_fail_count；代理未設定上限時預設 6 次。
 *
 * php artisan test tests/Feature/Frontend/GoogleAuth/GoogleAuthFailCountTest.php
 */
class GoogleAuthFailCountTest extends TestCase
{
    /** 代理未設定 GOOGLE_AUTH_FAIL_COUNT_LIMIT 時的預設上限 */
    private const int DEFAULT_LIMIT = 6;

    private string $apiPath = 'frontend/api/google-auth/auth';

    private Agent $agent;

    private Member $member;

    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, [
            'username' => 'ga_' . uniqid(),
        ]);

        $this->secretKey = (new Google2FA)->generateSecretKey();

        MemberAuthentication::create([
            'member_id' => $this->member->id,
            'type' => 1,
            'status' => 1,
            'secret' => $this->secretKey,
            'admin' => 0,
        ]);
    }

    private function auth(string $secret)
    {
        return $this->requestApi($this->apiPath, [
            'username' => $this->member->username,
            'secret' => $secret,
        ]);
    }

    private function failCount(): int
    {
        return (int) $this->member->statistics()->firstOrCreate([])->refresh()->google_auth_fail_count;
    }

    /**
     * 驗證失敗累計次數。
     */
    public function test_increases_fail_count_on_wrong_code(): void
    {
        $this->auth('000000')->assertJsonPath('message', __('error_message.9992'));
        $this->assertSame(1, $this->failCount());

        $this->auth('000000');
        $this->assertSame(2, $this->failCount());

        // 未達上限前帳號維持正常
        $this->assertSame(MemberStatusEnum::VERIFIED->value, (int) $this->member->refresh()->status);
    }

    /**
     * 達上限：鎖定帳號、作廢 token（前端登出）、留下狀態紀錄。
     */
    public function test_locks_member_when_reaching_limit(): void
    {
        $token = [REDACTED];

        for ($i = 1; $i < self::DEFAULT_LIMIT; $i++) {
            $this->auth('000000');
        }

        // 第 6 次達上限
        $response = $this->auth('000000');

        $response->assertJsonPath('code', HttpCode::HTTP_REDIRECT_LOGOUT->value)
            ->assertJsonPath('message', __('error_message.90062'));

        $this->assertSame(MemberStatusEnum::LOCKED->value, (int) $this->member->refresh()->status);

        // 會員狀態紀錄可查到「Google驗證錯誤達上限」這個理由
        $this->assertDatabaseHas('member_status_logs', [
            'member_id' => $this->member->id,
            'type' => MemberStatusLogTypeEnum::GOOGLE_AUTH_LIMIT->value,
            'after_status' => MemberStatusEnum::LOCKED->value,
        ]);

        // token 已作廢：會員被登出（revoke 是把 expire_date 設為當下，不刪除列）
        $this->assertSame(
            0,
            MemberAccessToken:[REDACTED]'member_id', $this->member->id)
                ->where('expire_date', '>', now())
                ->count(),
        );
        $this->assertNotEmpty($token);
        $this->assertSame(1, MemberStatusLog::where('member_id', $this->member->id)->count());
    }

    /**
     * 達上限後作廢 token 失敗：帳號仍維持鎖定、狀態紀錄照寫、回應照常是鎖帳，作廢失敗只告警。
     * 這個入口不用先登入就能打，鎖不上的話攻擊者可以一直猜驗證碼。
     */
    public function test_lock_stays_when_revoke_fails(): void
    {
        Exceptions::fake();
        $service = Mockery::mock(MemberAccessTokenService::class)->makePartial();
        $service->shouldReceive('revoke')->once()->andThrow(new RuntimeException('simulated revoke failure'));
        $this->app->instance(MemberAccessTokenService::class, $service);

        for ($i = 1; $i < self::DEFAULT_LIMIT; $i++) {
            $this->auth('000000');
        }
        $response = $this->auth('000000');

        $response->assertJsonPath('code', HttpCode::HTTP_REDIRECT_LOGOUT->value);
        $this->assertSame(MemberStatusEnum::LOCKED->value, (int) $this->member->refresh()->status, '作廢 token 失敗時帳號仍應維持鎖定');
        $this->assertSame(1, MemberStatusLog::where('member_id', $this->member->id)->count(), '作廢 token 失敗時狀態紀錄仍應寫入');
        $this->assertSame(self::DEFAULT_LIMIT, $this->failCount());
        Exceptions::assertReported(
            static fn (RuntimeException $e): bool => $e->getMessage() === 'simulated revoke failure'
        );
    }

    /**
     * 已鎖定的帳號再打錯：照樣回鎖帳，但不重寫狀態紀錄
     */
    public function test_already_locked_member_does_not_get_another_status_log(): void
    {
        for ($i = 1; $i <= self::DEFAULT_LIMIT; $i++) {
            $this->auth('000000');
        }
        $this->assertSame(MemberStatusEnum::LOCKED->value, (int) $this->member->refresh()->status);

        $this->auth('000000')->assertJsonPath('code', HttpCode::HTTP_REDIRECT_LOGOUT->value);

        $this->assertSame(self::DEFAULT_LIMIT + 1, $this->failCount());
        $this->assertSame(1, MemberStatusLog::where('member_id', $this->member->id)->count(), '已鎖定的會員不應再寫一筆狀態紀錄');
    }

    /**
     * 驗證通過即歸零，避免長期累積誤觸鎖定。
     */
    public function test_resets_fail_count_on_success(): void
    {
        $this->auth('000000');
        $this->auth('000000');
        $this->assertSame(2, $this->failCount());

        $otp = (new Google2FA)->getCurrentOtp($this->secretKey);

        $this->auth($otp)->assertJsonPath('code', 200);

        $this->assertSame(0, $this->failCount());
    }

    /**
     * 帳號不存在時回與驗證失敗相同的錯誤（原本取 $member->id 會 500，可用來枚舉帳號）。
     */
    public function test_unknown_username_does_not_error(): void
    {
        $response = $this->requestApi($this->apiPath, [
            'username' => 'not_exists_' . uniqid(),
            'secret' => '000000',
        ]);

        $response->assertJsonPath('message', __('error_message.9992'));
    }

    /**
     * 未啟用 2FA 的會員：視同驗證失敗並計次，不可 500。
     */
    public function test_member_without_2fa_is_counted_as_failure(): void
    {
        MemberAuthentication::where('member_id', $this->member->id)->delete();

        $this->auth('000000')->assertJsonPath('message', __('error_message.9992'));

        $this->assertSame(1, $this->failCount());
    }
}
