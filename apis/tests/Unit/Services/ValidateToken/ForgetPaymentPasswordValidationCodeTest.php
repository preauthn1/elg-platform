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
use App\Models\ValidateToken;
use App\Services\ValidateToken\CheckValidationCodeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Services/ValidateToken/ForgetPaymentPasswordValidationCodeTest.php
 */
class ForgetPaymentPasswordValidationCodeTest extends TestCase
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
            'payment_password' => Hash::make('123456'),
        ]);
    }

    public function test_type6_wrong_code_locks_account_after_three_failures(): void
    {
        $guestToken = [REDACTED]';
        Cache::put(CacheKeys::guestValidateToken($guestToken), $this->member->id, 3600);

        $this->createValidateToken('222222');

        for ($i = 0; $i < self::FAIL_LIMIT; $i++) {
            try {
                $this->service->checkCodeProcess([
                    'domain' => $this->domain,
                    'mobile' => $this->member->mobile,
                    'token' => '000000',
                    'type' => ValidationCodeTypeEnum::FORGET_PAYMENT_PASSWORD->value,
                    'guest_validate_token' => $guestToken,
                ]);
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

    public function test_type6_success_resets_validation_code_fail_count_and_payment_pwd_fail_count(): void
    {
        $correctToken = [REDACTED]';
        $this->createValidateToken($correctToken, Hash::make('998877'));

        $this->member->statistics()->firstOrCreate([])->update([
            'validation_code_fail_count' => self::FAIL_LIMIT,
            'payment_pwd_fail_count' => 2,
        ]);

        $member = $this->service->checkCodeProcess([
            'domain' => $this->domain,
            'mobile' => $this->member->mobile,
            'token' => $correctToken,
            'type' => ValidationCodeTypeEnum::FORGET_PAYMENT_PASSWORD->value,
        ]);

        $this->assertSame($this->member->id, $member->id);

        $this->member->refresh();
        $this->member->load('statistics');
        $this->assertSame(0, $this->member->statistics->validation_code_fail_count);
        $this->assertSame(0, $this->member->statistics->payment_pwd_fail_count);
    }

    private function createValidateToken(string $token, ?string $hashedPaymentPassword = [REDACTED] ValidateToken
    {
        return ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'type_id' => (int) (ValidationCodeTypeEnum::FORGET_PAYMENT_PASSWORD->value . '1'),
            'info' => $this->member->mobile,
            'info_2' => $hashedPaymentPassword ?? '',
            'token' => $token,
            'status' => 0,
            'expire_date' => now()->addHour()->toDateTimeString(),
            'admin' => $this->member->id,
        ]);
    }
}
