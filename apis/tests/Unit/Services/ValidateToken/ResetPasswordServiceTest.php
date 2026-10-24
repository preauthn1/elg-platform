<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ValidateToken;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberBindingLog;
use App\Models\ValidateToken;
use App\Services\ValidateToken\ResetPasswordService;
use Tests\TestCase;

/**
 * 驗證訪客重設密碼：forget_pwd_count 累加寫入 member_statistics；
 * 工單 4540：非忘記／找回密碼類型的驗證碼必須拒絕、綁定紀錄不存新密碼原文。
 *
 * php artisan test tests/Unit/Services/ValidateToken/ResetPasswordServiceTest.php
 */
class ResetPasswordServiceTest extends TestCase
{
    private ResetPasswordService $service;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ResetPasswordService::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, [
            'username' => 'reset_' . uniqid(),
            'mobile' => '0912345678',
            'mobile_validate' => 1,
        ]);
    }

    public function test_reset_password_increments_forget_pwd_count_in_member_statistics(): void
    {
        // 基準：member_statistics 塞 2，驗證累加
        $this->member->statistics()->updateOrCreate([], ['forget_pwd_count' => 2]);

        $token = [REDACTED]' . uniqid();
        ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'type_id' => 21, // type=2（FORGET_PASSWORD）、verify_by=1
            'info' => $this->member->mobile,
            'info_2' => '',
            'token' => $token,
            'status' => 0,
            'expire_date' => now()->addHour()->toDateTimeString(),
            'admin' => $this->member->id,
        ]);

        $this->service->resetPassword([
            'token' => $token,
            'username' => $this->member->username,
            'new_password' => 'newpass123',
        ]);

        $this->member->refresh();
        $this->member->load('statistics');

        // 2 → 3（累加寫進 member_statistics）
        $this->assertSame(3, $this->member->statistics->forget_pwd_count);
    }

    /**
     * 迴歸：雙方 email 同為 null 時不得放行。
     *
     * 舊條件 `mobile != && email !=` 在此情境下 `null != null` 為 false，整條 AND
     * 不成立即通過，攻擊者用自己帳號的驗證碼就能改任意「email 為空」帳號的密碼。
     */
    public function test_reset_password_rejects_other_member_when_both_emails_are_null(): void
    {
        // 攻擊者：持有合法驗證碼，手機與受害者不同、email 為空
        $attacker = $this->createMember($this->agent, [
            'username' => 'attacker_' . uniqid(),
            'mobile' => '0900000001',
            'mobile_validate' => 1,
            'email' => null,
        ]);

        // 受害者：email 同樣為空（純手機註冊者的常態）
        $victim = $this->createMember($this->agent, [
            'username' => 'victim_' . uniqid(),
            'mobile' => '0900000002',
            'mobile_validate' => 1,
            'email' => null,
        ]);

        $originalPassword = [REDACTED];

        $token = [REDACTED]' . uniqid();
        ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => $attacker->id,
            'type_id' => 21, // type=2（FORGET_PASSWORD）、verify_by=1
            'info' => $attacker->mobile,
            'info_2' => '',
            'token' => $token,
            'status' => 0,
            'expire_date' => now()->addHour()->toDateTimeString(),
            'admin' => $attacker->id,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('member not match token');

        try {
            $this->service->resetPassword([
                'token' => $token,
                'username' => $victim->username,
                'new_password' => 'pwned123',
            ]);
        } finally {
            // 密碼必須完全沒被動過
            $this->assertSame($originalPassword, $victim->refresh()->password);
        }
    }

    /**
     * 工單 4540：驗證碼類型不是 2（忘記密碼）／3（找回密碼）時必須拒絕。
     *
     * 舊寫法遇到其他類型直接 return，controller 照樣回成功，前端以為改好了、密碼其實沒動；
     * 也等於讓其他用途（例如資料驗證）的驗證碼能走進重設密碼流程而不留下任何拒絕紀錄。
     */
    public function test_reset_password_rejects_token_of_other_type(): void
    {
        $originalPassword = [REDACTED];

        $token = [REDACTED]' . uniqid();
        ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'type_id' => 11, // type=1（資料驗證）、verify_by=1
            'info' => $this->member->mobile,
            'info_2' => '',
            'token' => $token,
            'status' => 0,
            'expire_date' => now()->addHour()->toDateTimeString(),
            'admin' => $this->member->id,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.9992'));

        try {
            $this->service->resetPassword([
                'token' => $token,
                'username' => $this->member->username,
                'new_password' => 'newpass123',
            ]);
        } finally {
            $this->assertSame($originalPassword, $this->member->refresh()->password);
        }
    }

    /**
     * 工單 4540：重設密碼的綁定紀錄不存新密碼原文。
     *
     * SystemNotifyService::getMemberBindingLogs() 會把 value 當通知內容送出，存了原文就等於把密碼外送。
     */
    public function test_reset_password_binding_log_does_not_store_new_password(): void
    {
        $token = [REDACTED]' . uniqid();
        ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'type_id' => 21, // type=2（FORGET_PASSWORD）、verify_by=1
            'info' => $this->member->mobile,
            'info_2' => '',
            'token' => $token,
            'status' => 0,
            'expire_date' => now()->addHour()->toDateTimeString(),
            'admin' => $this->member->id,
        ]);

        $this->service->resetPassword([
            'token' => $token,
            'username' => $this->member->username,
            'new_password' => 'newpass123',
        ]);

        $log = MemberBindingLog::where('member_id', $this->member->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(5, (int) $log->type);
        $this->assertSame($this->member->id, (int) $log->member_id);
        $this->assertNull($log->value);
    }
}
