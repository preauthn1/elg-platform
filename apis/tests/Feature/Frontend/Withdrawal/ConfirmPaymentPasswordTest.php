<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Withdrawal;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\LanguageEnum;
use App\Enums\MemberStatusEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberStatusLog;
use App\Services\MemberAccessTokenService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Withdrawal/ConfirmPaymentPasswordTest.php
 */
class ConfirmPaymentPasswordTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected string $token;

    private int $paymentPwdFailCountLimit = 3;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        Context::addHidden(ContextKeyEnum::LANGUAGE_ID->value, LanguageEnum::ZH_TW->value);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->token = [REDACTED];

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::PAYMENT_PWD_FAIL_COUNT_LIMIT, $this->paymentPwdFailCountLimit);

        $this->setupMember();
    }

    public function setupMember()
    {
        $this->member->payment_password = [REDACTED]'985201'); // 隨機6個數字
        $this->member->save();
    }

    public function test_success()
    {
        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/confirm-payment-password',
            data: [
                'payment_password' => '985201',
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        // Assert
        $response->assertStatus(200);
    }

    public function test_wrong_password_increments_fail_count(): void
    {
        $response = $this->postConfirmPaymentPassword('000000');

        $response->assertJson([
            'code' => 400,
            'message' => __('error_message.90052'),
        ]);

        $this->member->refresh();
        $this->member->load('statistics');
        $this->assertSame(1, $this->member->statistics->payment_pwd_fail_count);
    }

    public function test_limit_minus_one_increments_fail_count_without_lock(): void
    {
        $this->createAgentSetting(
            $this->agent,
            AgentCustomizedSettingKeyEnum::PAYMENT_PWD_FAIL_COUNT_LIMIT,
            -1,
        );

        $this->member->status = MemberStatusEnum::VERIFIED->value;
        $this->member->save();
        $this->member->statistics()->updateOrCreate([], ['payment_pwd_fail_count' => 0]);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postConfirmPaymentPassword('000000');

            $response->assertJson([
                'code' => 400,
                'message' => __('error_message.90052'),
            ]);
        }

        $this->member->refresh();
        $this->member->load('statistics');
        $this->assertSame(5, $this->member->statistics->payment_pwd_fail_count);
        $this->assertSame(MemberStatusEnum::VERIFIED->value, $this->member->status);
    }

    /**
     * 支付密碼刻意沿用「作廢 token 失敗時鎖定一起回滾」：呼叫端一定持有 token，
     * 要先有保證會重試作廢的機制，才能改成跟登入一樣失敗仍鎖住。失敗次數在鎖帳之前已提交，不跟著回滾。
     */
    public function test_lock_rolls_back_when_revoke_fails(): void
    {
        Exceptions::fake();
        $tokenService = Mockery::mock(MemberAccessTokenService::class)->makePartial();
        $tokenService->shouldReceive('revoke')->once()->andThrow(new RuntimeException('simulated revoke failure'));
        $this->app->instance(MemberAccessTokenService::class, $tokenService);

        $this->member->statistics()->updateOrCreate([], ['payment_pwd_fail_count' => $this->paymentPwdFailCountLimit - 1]);

        $this->postConfirmPaymentPassword('000000');

        $this->member->refresh();
        $this->member->load('statistics');
        $this->assertSame(MemberStatusEnum::VERIFIED->value, $this->member->status, '作廢 token 失敗時鎖定應一起回滾');
        $this->assertSame(0, MemberStatusLog::where('member_id', $this->member->id)->count());
        $this->assertSame($this->paymentPwdFailCountLimit, $this->member->statistics->payment_pwd_fail_count, '失敗次數已先提交，不跟著回滾');
        Exceptions::assertReported(
            static fn (RuntimeException $e): bool => $e->getMessage() === 'simulated revoke failure'
        );
    }

    #[DataProvider('paymentPasswordFailLimitScenarioProvider')]
    public function test_payment_password_fail_limit_scenarios(
        bool $enableRecoverPaymentPassword,
        bool $hasMobileBinding,
        bool $hasEmailBinding,
        int $expectedCode,
        bool $expectLocked,
    ): void {
        $this->createAgentSetting(
            $this->agent,
            AgentCustomizedSettingKeyEnum::ENABLE_RECOVER_PAYMENT_PASSWORD,
            $enableRecoverPaymentPassword ? 1 : 0,
        );

        $this->member->mobile = $hasMobileBinding ? '0912345678' : '';
        $this->member->mobile_validate = $hasMobileBinding ? 1 : 0;
        $this->member->email = $hasEmailBinding ? 'user@example.invalid' : '';
        $this->member->email_validate = $hasEmailBinding ? 1 : 0;
        $this->member->status = MemberStatusEnum::VERIFIED->value;
        $this->member->save();
        $this->member->statistics()->updateOrCreate([], ['payment_pwd_fail_count' => 0]);

        $response = null;
        for ($i = 0; $i < $this->paymentPwdFailCountLimit; $i++) {
            $response = $this->postConfirmPaymentPassword('000000');
        }

        $response->assertJson([
            'code' => $expectedCode,
            'message' => $expectedCode === HttpCode::HTTP_REDIRECT_RESET_PAYMENT_PASSWORD->value
                ? __('error_message.90052')
                : __('error_message.90062'),
        ]);

        $this->member->refresh();
        $this->member->load('statistics');
        $this->assertSame($this->paymentPwdFailCountLimit, $this->member->statistics->payment_pwd_fail_count);

        if ($expectLocked) {
            $this->assertSame(MemberStatusEnum::LOCKED->value, $this->member->status);
        } else {
            $this->assertSame(MemberStatusEnum::VERIFIED->value, $this->member->status);
        }
    }

    /**
     * @return array<string, array{
     *     enableRecoverPaymentPassword: [REDACTED],
     *     hasMobileBinding: bool,
     *     hasEmailBinding: bool,
     *     expectedCode: int,
     *     expectLocked: bool,
     * }>
     */
    public static function paymentPasswordFailLimitScenarioProvider(): array
    {
        return [
            '未啟用找回支付密碼_已綁手機_鎖定登出' => [
                'enableRecoverPaymentPassword' => false,
                'hasMobileBinding' => true,
                'hasEmailBinding' => false,
                'expectedCode' => HttpCode::HTTP_REDIRECT_LOGOUT->value,
                'expectLocked' => true,
            ],
            '啟用找回支付密碼_未綁手機與信箱_鎖定登出' => [
                'enableRecoverPaymentPassword' => true,
                'hasMobileBinding' => false,
                'hasEmailBinding' => false,
                'expectedCode' => HttpCode::HTTP_REDIRECT_LOGOUT->value,
                'expectLocked' => true,
            ],
            '啟用找回支付密碼_已綁手機_導向找回' => [
                'enableRecoverPaymentPassword' => true,
                'hasMobileBinding' => true,
                'hasEmailBinding' => false,
                'expectedCode' => HttpCode::HTTP_REDIRECT_RESET_PAYMENT_PASSWORD->value,
                'expectLocked' => false,
            ],
            '啟用找回支付密碼_僅綁信箱_導向找回' => [
                'enableRecoverPaymentPassword' => true,
                'hasMobileBinding' => false,
                'hasEmailBinding' => true,
                'expectedCode' => HttpCode::HTTP_REDIRECT_RESET_PAYMENT_PASSWORD->value,
                'expectLocked' => false,
            ],
        ];
    }

    private function postConfirmPaymentPassword(string $paymentPassword)
    {
        return $this->json(
            method: 'POST',
            uri: 'frontend/api/withdrawal/confirm-payment-password',
            data: [
                'payment_password' => $paymentPassword,
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ],
        );
    }
}
