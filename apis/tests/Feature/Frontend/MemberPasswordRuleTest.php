<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\Member;
use App\Models\ValidateToken;
use App\Services\EventService;
use App\Services\Frontend\EventInvite\EventInviteService;
use App\Services\MemberCodeService;
use App\Services\MemberFieldSettingService;
use App\Services\MemberService;
use App\Services\RegisterService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * 會員註冊、忘記密碼、修改密碼三處都照代理註冊欄位設定的 password 規則檢查新密碼；
 * 登入密碼與修改密碼的舊密碼只做比對，不套用規則。
 *
 * php artisan test tests/Feature/Frontend/MemberPasswordRuleTest.php
 */
class MemberPasswordRuleTest extends TestCase
{
    /** 規則上線前設定、不符合新規則的舊密碼（全形＋範圍外符號） */
    private const string NON_COMPLIANT_OLD_PASSWORD = [REDACTED]';

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'org-' . bin2hex(random_bytes(4)),
            'currency_id' => 1,
            'memo' => 'password rule test',
        ]);

        $groupId = (int) DB::table('agent_groups')->insertGetId([
            'agent_group_type' => 6,
            'organization_id' => $orgId,
            'name' => 'password rule test',
            'memo' => '',
            'is_default' => 0,
        ]);

        $base = ['agent_group_id' => $groupId, 'currency_id' => 1, 'page_type' => 1, 'field_group' => 1, 'is_required' => 1, 'status' => 1, 'admin' => 0];
        DB::table('agent_member_field_settings')->insert([
            $base + ['field_key' => 'username', 'sort' => 1, 'extra_config' => json_encode(['min_length' => 4, 'max_length' => 12])],
            $base + ['field_key' => 'password', 'sort' => 2, 'extra_config' => json_encode([
                'min_length' => 6,
                'max_length' => 12,
                'rules' => ['alphabet' => 'required', 'number' => 'required', 'symbol' => 'forbidden'],
            ])],
        ]);

        $this->agent = $this->createAgentAndDomain([
            'organization_id' => $orgId,
            'member_field_setting_group_id' => $groupId,
        ]);

        $registerServiceMock = Mockery::mock(RegisterService::class, [
            $this->app->make(EventService::class),
            $this->app->make(MemberService::class),
            $this->app->make(MemberCodeService::class),
            $this->app->make(EventInviteService::class),
        ])->makePartial();
        $registerServiceMock->shouldReceive('triggerCsSyncData')->andReturnNull();
        $registerServiceMock->shouldReceive('runRegisterEventStoredProcedures')->andReturnNull();
        $this->app->instance(RegisterService::class, $registerServiceMock);
    }

    public function test_register_applies_password_rules(): void
    {
        $username = 'r' . substr(bin2hex(random_bytes(6)), 0, 9);

        $this->assertSame(2056, $this->register($username, 'abcdef')->json('code'));
        $this->assertSame(2062, $this->register($username, 'abc12!')->json('code'));
        $this->assertSame(2060, $this->register($username, 'ａｂｃ１２３')->json('code'));

        $this->register($username, 'abc123')->assertJson(['code' => 200]);
        $this->assertDatabaseHas('members', ['username' => $username]);
    }

    public function test_reset_password_applies_password_rules_without_forcing_logout(): void
    {
        $member = $this->createMember($this->agent, [
            'username' => 'reset' . substr(bin2hex(random_bytes(4)), 0, 6),
            'mobile' => '0912345678',
            'mobile_validate' => 1,
        ]);
        $token = [REDACTED];

        // 規則沒過要回對應錯誤碼，不能被 controller 的 try/catch 轉成登出
        $response = $this->resetPassword($member, $token, 'abcdef');
        $this->assertSame(2056, $response->json('code'));
        $this->assertSame(
            __('error_message.2056', ['rules' => __('error_message.password_char_type.number')]),
            $response->json('message'),
        );

        $this->resetPassword($member, $token, 'abc123')->assertJson(['code' => 200]);
        $this->assertTrue(Hash::check('abc123', $member->refresh()->password));
    }

    public function test_change_password_applies_rules_to_new_password_only(): void
    {
        $member = $this->createMember($this->agent, ['password' => Hash::make(self::NON_COMPLIANT_OLD_PASSWORD)]);
        $loginToken = [REDACTED];

        $this->assertSame(2056, $this->changePassword($loginToken, self::NON_COMPLIANT_OLD_PASSWORD, 'abcdef')->json('code'));

        // 舊密碼不符新規則仍要能當舊密碼比對
        $this->changePassword($loginToken, self::NON_COMPLIANT_OLD_PASSWORD, 'abc123')->assertJson(['code' => 200]);
        $this->assertTrue(Hash::check('abc123', $member->refresh()->password));
    }

    public function test_login_accepts_password_that_breaks_new_rules(): void
    {
        $member = $this->createMember($this->agent, [
            'username' => 'login' . substr(bin2hex(random_bytes(4)), 0, 6),
            'password' => Hash::make(self::NON_COMPLIANT_OLD_PASSWORD),
        ]);

        $this->requestApi('frontend/api/guest/login', [
            'domain' => $this->domain,
            'username' => $member->username,
            'password' => self::NON_COMPLIANT_OLD_PASSWORD,
        ])->assertJson(['code' => 200]);

        $injection = $this->requestApi('frontend/api/guest/login', [
            'domain' => $this->domain,
            'username' => $member->username,
            'password' => "' OR '1'='1",
        ]);
        $this->assertSame(__('error_message.9005'), $injection->json('message'));
    }

    public function test_presentation_returns_normalized_password_rules(): void
    {
        $register = $this->app->make(MemberFieldSettingService::class)->presentation($this->agent)['register'];
        $password = [REDACTED]'field_key', 'password');

        $this->assertEquals([
            'min_length' => 6,
            'max_length' => 12,
            'rules' => ['alphabet' => 'required', 'number' => 'required', 'symbol' => 'forbidden'],
            'allowed_symbols' => '!@#$%^&*-_+=?',
        ], $password['extra_config']);
    }

    private function register(string $username, string $password): TestResponse
    {
        return $this->requestApi('frontend/api/guest/register-v2', [
            'domain' => $this->domain,
            'username' => $username,
            'password' => $password,
            'confirm_password' => $password,
        ]);
    }

    private function resetPassword(Member $member, string $token, string $password): TestResponse
    {
        return $this->requestApi('frontend/api/guest/reset-password', [
            'domain' => $this->domain,
            'token' => $token,
            'username' => $member->username,
            'new_password' => $password,
            'new_password_confirmation' => $password,
        ]);
    }

    private function changePassword(string $loginToken, string $old, string $new): TestResponse
    {
        return $this->postJson('frontend/api/member/change-password', [
            'password' => $old,
            'new_password' => $new,
            'new_password_confirmation' => $new,
        ], ['Accept-Language' => 'zh-TW', 'Authorization' => 'Bearer ' . $loginToken]);
    }

    private function createResetToken(Member $member): string
    {
        $token = [REDACTED] random_int(100000, 999999);
        ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => $member->id,
            'type_id' => 21, // type=2（FORGET_PASSWORD）、verify_by=1
            'info' => $member->mobile,
            'info_2' => '',
            'token' => $token,
            'status' => 0,
            'expire_date' => now()->addHour()->toDateTimeString(),
            'admin' => $member->id,
        ]);

        return $token;
    }
}
