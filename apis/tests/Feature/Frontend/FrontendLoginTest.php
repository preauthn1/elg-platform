<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\MemberStatusEnum;
use App\Enums\MemberStatusLogTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberAccessToken;
use App\Models\MemberStatusLog;
use App\Services\MemberAccessTokenService;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/FrontendLoginTest.php
 */
class FrontendLoginTest extends TestCase
{
    private string $apiPath = 'frontend/api/guest/login';

    private Agent $agent;

    private Member $member;

    private string $memberPassword = [REDACTED]';

    private int $loginFailCountLimit = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->agent = $this->createAgentAndDomain();

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'status' => 1,
            'password' => Hash::make($this->memberPassword),
        ]);

        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::LOGIN_FAIL_COUNT_LIMIT, $this->loginFailCountLimit);
    }

    public function test_login_success()
    {
        // Arrange
        $params = [
            'username' => $this->member->username,
            'password' => $this->memberPassword,
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertStatus(200)
            ->assertJson([
                'code' => 200,
            ])
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'id',
                    'token',
                ],
            ]);
    }

    /**
     * php artisan test --filter test_login_failed_super_password_from_office_ip tests/Feature/Frontend/FrontendLoginTest.php
     */
    public function test_login_failed_super_password_from_office_ip()
    {
        // Arrange
        $params = [
            'username' => $this->member->username,
            'password' => 'm4VcOYhZbzZEtQ28lsXq',
            'domain' => $this->domain,
        ];
        $this->withServerVariables([
            'REMOTE_ADDR' => '0.0.0.0',
        ]);

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertJson([
                'code' => 400,
            ]);
    }

    public function test_login_success_need_mobile_verify()
    {
        // Arrange
        $this->member->mobile_validate = 1;
        $this->member->save();

        $this->agent->mobile_validate = 1;
        $this->agent->save();
        $params = [
            'username' => $this->member->username,
            'password' => $this->memberPassword,
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertStatus(200)
            ->assertJson([
                'code' => 200,
            ]);
    }

    public function test_login_success_need_email_verify()
    {
        // Arrange
        $this->member->email_validate = 1;
        $this->member->save();

        $this->agent->email_validate = 1;
        $this->agent->save();
        $params = [
            'username' => $this->member->username,
            'password' => $this->memberPassword,
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertStatus(200)
            ->assertJson([
                'code' => 200,
            ]);
    }

    /************************************************************
     *     Login Failed
     ************************************************************/

    public function test_login_failed_super_password_wrong_ip()
    {
        // Arrange
        $params = [
            'username' => $this->member->username,
            'password' => 'm4VcOYhZbzZEtQ28lsXq',
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // # Assert
        $response
            ->assertJson([
                'code' => 400,
            ]);
    }

    public function test_login_failed_wrong_password()
    {
        // Arrange
        $params = [
            'username' => $this->member->username,
            'password' => 'wrongpassword',
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertJson([
                'code' => 400,
            ]);
    }

    /**
     * php artisan test --filter test_login_failed_wrong_password_too_many_times tests/Feature/Frontend/FrontendLoginTest.php
     */
    public function test_login_failed_wrong_password_too_many_times()
    {
        // Arrange
        $params = [
            'username' => $this->member->username,
            'password' => 'wrongpassword',
            'domain' => $this->domain,
        ];

        for ($i = 0; $i < $this->loginFailCountLimit; $i++) {
            // Request
            $response = $this->requestApi($this->apiPath, $params);
        }

        // Assert
        $response
            ->assertJson([
                'code' => HttpCode::HTTP_REDIRECT_LOGOUT->value,
                'message' => __('error_message.90062'),
            ]);
    }

    /**
     * 密碼錯到上限鎖帳：會員轉為鎖定、寫一筆登入密碼上限的狀態紀錄、有效 token 全數作廢
     *
     * php artisan test --filter test_login_lock_writes_status_log_and_revokes_tokens tests/Feature/Frontend/FrontendLoginTest.php
     */
    public function test_login_lock_writes_status_log_and_revokes_tokens()
    {
        // Arrange
        $token = [REDACTED]
            'member_id' => $this->member->id,
            'token' => 'login-lock-token-' . uniqid(),
            'expire_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'admin' => 0,
        ]);

        $params = [
            'username' => $this->member->username,
            'password' => 'wrongpassword',
            'domain' => $this->domain,
        ];

        // Request：前幾次只累計，最後一次達上限
        for ($i = 1; $i < $this->loginFailCountLimit; $i++) {
            $this->requestApi($this->apiPath, $params);
        }
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response->assertJson(['code' => HttpCode::HTTP_REDIRECT_LOGOUT->value]);

        $this->assertSame(MemberStatusEnum::LOCKED->value, $this->member->fresh()->status);

        $logs = MemberStatusLog::where('member_id', $this->member->id)->get();
        $this->assertCount(1, $logs);
        $this->assertSame(MemberStatusLogTypeEnum::LOGIN_PASSWORD_LIMIT, $logs->first()->type);
        $this->assertSame(MemberStatusEnum::VERIFIED, $logs->first()->before_status);
        $this->assertSame(MemberStatusEnum::LOCKED, $logs->first()->after_status);

        $this->assertLessThanOrEqual(time(), strtotime($token->fresh()->expire_date));
    }

    /**
     * 鎖帳後作廢 token 失敗：帳號仍維持鎖定、狀態紀錄照寫、回應照常是鎖帳，作廢失敗只告警不回 500
     *
     * php artisan test --filter test_login_lock_stays_when_revoke_fails tests/Feature/Frontend/FrontendLoginTest.php
     */
    public function test_login_lock_stays_when_revoke_fails()
    {
        // Arrange：作廢 token 的環節拋例外；限定只被呼叫一次，確保真的走到鎖帳
        Exceptions::fake();
        $service = Mockery::mock(MemberAccessTokenService::class)->makePartial();
        $service->shouldReceive('revoke')->once()->andThrow(new RuntimeException('simulated revoke failure'));
        $this->app->instance(MemberAccessTokenService::class, $service);

        $params = [
            'username' => $this->member->username,
            'password' => 'wrongpassword',
            'domain' => $this->domain,
        ];

        // Request：前幾次只累計，最後一次達上限、鎖帳後作廢 token 失敗
        for ($i = 1; $i < $this->loginFailCountLimit; $i++) {
            $this->requestApi($this->apiPath, $params);
        }
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response->assertJson(['code' => HttpCode::HTTP_REDIRECT_LOGOUT->value]);

        $this->assertSame(
            MemberStatusEnum::LOCKED->value,
            $this->member->fresh()->status,
            '作廢 token 失敗時，帳號仍應維持鎖定'
        );

        $logs = MemberStatusLog::where('member_id', $this->member->id)->get();
        $this->assertCount(1, $logs, '作廢 token 失敗時，狀態紀錄仍應寫入');
        $this->assertSame(MemberStatusLogTypeEnum::LOGIN_PASSWORD_LIMIT, $logs->first()->type);

        $this->assertSame(
            $this->loginFailCountLimit,
            (int) $this->member->statistics()->first()->login_fail_count,
            '作廢 token 失敗時，失敗次數不應回滾'
        );

        Exceptions::assertReported(
            static fn (RuntimeException $e): bool => $e->getMessage() === 'simulated revoke failure'
        );
    }

    /**
     * 密碼錯到上限、但有啟用找回密碼且綁定已驗證的手機：不鎖帳，導向重設密碼並發一組訪客驗證 token
     *
     * php artisan test --filter test_login_failed_too_many_times_redirects_to_reset_password_when_recoverable tests/Feature/Frontend/FrontendLoginTest.php
     */
    public function test_login_failed_too_many_times_redirects_to_reset_password_when_recoverable()
    {
        // Arrange
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::ENABLE_RECOVER_PASSWORD, 1);
        $this->member->mobile = '0966' . random_int(100000, 999999);
        $this->member->mobile_validate = 1;
        $this->member->save();

        $params = [
            'username' => $this->member->username,
            'password' => 'wrongpassword',
            'domain' => $this->domain,
        ];

        // Request：前幾次只累計，最後一次達上限
        for ($i = 1; $i < $this->loginFailCountLimit; $i++) {
            $this->requestApi($this->apiPath, $params);
        }
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertJson([
                'code' => HttpCode::HTTP_REDIRECT_RESET_PASSWORD->value,
                'data' => ['member_id' => $this->member->id],
            ])
            ->assertJsonStructure(['data' => ['guest_validate_token']]);

        $this->assertSame(MemberStatusEnum::VERIFIED->value, $this->member->fresh()->status);
        $this->assertSame(0, MemberStatusLog::where('member_id', $this->member->id)->count());
        $this->assertSame($this->loginFailCountLimit, (int) $this->member->statistics()->first()->login_fail_count);
    }

    public function test_login_failed_with_wrong_domain()
    {
        // Arrange
        $params = [
            'username' => $this->member->username,
            'password' => $this->memberPassword,
            'domain' => 'wrongdomain',
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertJson([
                'code' => 400,
            ]);
    }

    public function test_login_failed_system_locked()
    {
        // Arrange
        $this->member->sys_lock = 1;
        $this->member->save();

        $params = [
            'username' => $this->member->username,
            'password' => $this->memberPassword,
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertJson([
                'code' => 400,
            ]);
    }

    public function test_login_failed_status_not_active()
    {
        // Arrange
        $this->member->status = 0;
        $this->member->save();

        $params = [
            'username' => $this->member->username,
            'password' => $this->memberPassword,
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertJson([
                'code' => 400,
            ]);
    }

    public function test_login_failed_status_disabled()
    {
        // Arrange
        $this->member->status = 2;
        $this->member->save();

        $params = [
            'username' => $this->member->username,
            'password' => $this->memberPassword,
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertJson([
                'code' => HttpCode::HTTP_REDIRECT_LOGOUT->value,
                'message' => __('error_message.90062'),
            ]);
    }

    /** 4130:直屬代理停用 → 會員登入被擋並回明確訊息(而非 9005 帳密錯誤) */
    public function test_login_failed_direct_agent_disabled()
    {
        // Arrange
        $this->agent->update(['status' => 0]);

        $params = [
            'username' => $this->member->username,
            'password' => $this->memberPassword,
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertJson([
                'code' => 400,
                'message' => __('error_message.agent_status.ancestor_disabled'),
            ]);
    }

    /** 4130:非直屬的上級代理停用 → 整棵下線樹的會員登入被擋 */
    public function test_login_failed_ancestor_agent_disabled()
    {
        // Arrange:$this->agent(持有網域) → 下級代理 → 會員,停用最上層
        $unique = bin2hex(random_bytes(8));
        $childAgent = Agent::factory()->create([
            'code' => substr($unique, 0, 10),
            'username' => 'a' . substr($unique, 0, 15),
            'email' => substr($unique, 0, 12) . '@t.ag',
            'password' => Hash::make('123456'),
            'status' => 1,
            'parent' => $this->agent->id,
            'organization_id' => $this->agent->organization_id,
        ]);
        $childAgent->hierarchy_path = $this->agent->hierarchy_path . '/' . $childAgent->id;
        $childAgent->save();

        $member = $this->createMember($childAgent, [
            'username' => 'up' . bin2hex(random_bytes(6)),
            'password' => Hash::make($this->memberPassword),
        ]);

        $this->agent->update(['status' => 0]);

        $params = [
            'username' => $member->username,
            'password' => $this->memberPassword,
            'domain' => $this->domain,
        ];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response
            ->assertJson([
                'code' => 400,
                'message' => __('error_message.agent_status.ancestor_disabled'),
            ]);
    }

    // public function test_login_failed_need_mobile_verify()
    // {
    //     // Arrange
    //     $this->member->mobile_validate = 0;
    //     $this->member->save();
    //
    //     $this->agent->mobile_validate = 1;
    //     $this->agent->save();
    //     $params = [
    //         'username' => $this->member->username,
    //         'password' => $this->memberPassword,
    //         'domain' => $this->domain,
    //     ];
    //
    //     // Request
    //     $response = $this->requestApi($this->apiPath, $params);
    //
    //     // Assert
    //     $response
    //         ->assertJson([
    //             'code' => 324,
    //         ]);
    // }

    // public function test_login_failed_need_email_verify()
    // {
    //     // Arrange
    //     $this->member->email_validate = 0;
    //     $this->member->save();
    //
    //     $this->agent->email_validate = 1;
    //     $this->agent->save();
    //     $params = [
    //         'username' => $this->member->username,
    //         'password' => $this->memberPassword,
    //         'domain' => $this->domain,
    //     ];
    //
    //     // Request
    //     $response = $this->requestApi($this->apiPath, $params);
    //
    //     // Assert
    //     $response
    //         ->assertJson([
    //             'code' => 326,
    //         ]);
    // }
}
