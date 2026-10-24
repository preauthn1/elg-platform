<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\MemberStatusEnum;
use App\Enums\MemberStatusLogTypeEnum;
use App\Enums\ValidationCodeTypeEnum;
use App\Models\Member;
use App\Models\MemberAccessToken;
use App\Models\MemberAuthentication;
use App\Models\MemberStatusLog;
use App\Models\ValidateToken;
use App\Services\LoginService;
use App\Services\MemberAccessTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\TestCase;

/**
 * LoginService 測試
 *
 * php artisan test tests/Unit/Services/LoginServiceTest.php
 */
class LoginServiceTest extends TestCase
{
    private function service(): LoginService
    {
        return App::make(LoginService::class);
    }

    /**
     * Common::getIp() 使用 request()->server('REMOTE_ADDR')，僅設 $_SERVER 不足。
     */
    private function bindClientIp(string $ip): void
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        $this->app->instance('request', Request::create(
            uri: '/',
            method: 'GET',
            server: ['REMOTE_ADDR' => $ip],
        ));
    }

    /**
     * 回傳第一筆符合 pattern 的查詢在查詢紀錄中的位置，查無回 null
     *
     * @param  list<string>  $queries
     */
    private function firstQueryIndex(array $queries, string $pattern): ?int
    {
        foreach ($queries as $index => $query) {
            if (preg_match($pattern, $query) === 1) {
                return $index;
            }
        }

        return null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = 'login-' . uniqid('', true) . '.test';
        Cache::flush();
    }

    public function test_get_ip_change_auth_methods_returns_mobile_when_enabled(): void
    {
        $member = Member::factory()->make([
            'ip_change_mobile_verify' => 1,
            'mobile_validate' => 1,
            'mobile' => '0912345678',
            'ip_change_email_verify' => 0,
            'email_validate' => 0,
            'email' => null,
        ]);

        $methods = $this->service()->getIpChangeAuthMethods($member);

        $this->assertSame(['mobile'], $methods);
    }

    public function test_get_ip_change_auth_methods_returns_email_when_enabled(): void
    {
        $member = Member::factory()->make([
            'ip_change_mobile_verify' => 0,
            'mobile_validate' => 0,
            'mobile' => null,
            'ip_change_email_verify' => 1,
            'email_validate' => 1,
            'email' => 'user@example.invalid',
        ]);

        $methods = $this->service()->getIpChangeAuthMethods($member);

        $this->assertSame(['email'], $methods);
    }

    public function test_is_super_account_false_even_when_password_and_ip_match_office(): void
    {
        Config::set('custom.office.password', 'office-secret');
        Config::set('custom.office.ip', '0.0.0.0');
        $this->bindClientIp('0.0.0.0');

        $this->assertFalse($this->service()->isSuperAccount('office-secret'));
    }

    public function test_is_super_account_false_when_ip_mismatch(): void
    {
        Config::set('custom.office.password', 'office-secret');
        Config::set('custom.office.ip', '0.0.0.0');
        $this->bindClientIp('0.0.0.0');

        $this->assertFalse($this->service()->isSuperAccount('office-secret'));
    }

    public function test_get_member_finds_by_username(): void
    {
        $agent = $this->createAgentAndDomain(['username' => 'ls_agent_' . uniqid()]);
        $member = $this->createMember($agent, [
            'username' => 'login_user_' . uniqid(),
        ]);

        $found = $this->service()->getMember($agent->organization_id, $member->username);

        $this->assertNotNull($found);
        $this->assertSame($member->id, $found->id);
    }

    public function test_get_member_finds_by_mobile_when_validated(): void
    {
        $agent = $this->createAgentAndDomain(['username' => 'ls_agent2_' . uniqid()]);
        $mobile = '0988' . random_int(1000000, 9999999);
        $member = $this->createMember($agent, [
            'username' => 'u_' . uniqid(),
            'mobile' => $mobile,
            'mobile_validate' => 1,
        ]);

        $found = $this->service()->getMember($agent->organization_id, $mobile);

        $this->assertNotNull($found);
        $this->assertSame($member->id, $found->id);
    }

    public function test_get_member_by_mobile_resolves_member_of_same_binding_scope(): void
    {
        // 4309：同組織不同 binding_scope 各有一位已驗證同手機的會員，從 scope 2 的站登入要拿到 scope 2 那位
        $mobile = '0977' . random_int(1000000, 9999999);
        $agentA = $this->createAgentAndDomain(['username' => 'ls_a_' . uniqid(), 'binding_scope_id' => 1]);
        $memberA = $this->createMember($agentA, ['username' => 'ua_' . uniqid(), 'mobile' => $mobile, 'mobile_validate' => 1]);

        $this->domain = 'login-b-' . uniqid('', true) . '.test';
        $agentB = $this->createAgentAndDomain([
            'username' => 'ls_b_' . uniqid(),
            'organization_id' => $agentA->organization_id,
            'binding_scope_id' => 2,
        ]);
        $memberB = $this->createMember($agentB, ['username' => 'ub_' . uniqid(), 'mobile' => $mobile, 'mobile_validate' => 1]);

        $foundB = $this->service()->getMember($agentB->organization_id, $mobile, [], 2);
        $foundA = $this->service()->getMember($agentA->organization_id, $mobile, [], 1);
        $foundNone = $this->service()->getMember($agentA->organization_id, $mobile, [], 3);

        $this->assertSame($memberB->id, $foundB?->id, 'scope 2 的站只認 scope 2 的會員');
        $this->assertSame($memberA->id, $foundA?->id);
        $this->assertNull($foundNone, '該 scope 沒人綁這支手機 → 查無');
        // 帳號登入不受 scope 影響（username 全站唯一）
        $this->assertSame($memberA->id, $this->service()->getMember($agentA->organization_id, $memberA->username, [], 2)?->id);
    }

    public function test_verify_login_code_updates_token_and_returns_true(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'm_' . uniqid()]);

        // LoginService: (string) LOGIN_PHONE_EMAIL->value . $verifyBy → '10'.'1' === 101
        $typeId = (int) (ValidationCodeTypeEnum::LOGIN_PHONE_EMAIL->value . '1');

        $tokenRow = ValidateToken:[REDACTED]
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'type_id' => $typeId,
            'token' => 'otp-secret-123',
            'expire_date' => now()->addHour()->toDateTimeString(),
            'status' => 0,
            'admin' => 0,
        ]);

        $ok = $this->service()->verifyLoginCode($member, 'mobile', 'otp-secret-123');

        $this->assertTrue($ok);
        $tokenRow->refresh();
        $this->assertSame(1, (int) $tokenRow->status);
    }

    public function test_verify_login_code_returns_false_for_invalid_channel(): void
    {
        $member = Member::factory()->make();

        $this->assertFalse($this->service()->verifyLoginCode($member, 'invalid', 'x'));
    }

    public function test_unbind_device_id_clears_other_members(): void
    {
        $agent = $this->createAgentAndDomain();
        $keep = $this->createMember($agent, ['username' => 'keep_' . uniqid()]);
        $other = $this->createMember($agent, ['username' => 'other_' . uniqid()]);

        $deviceId = 'device-' . uniqid();
        Member::where('id', $other->id)->update(['device_id' => $deviceId]);
        Member::where('id', $keep->id)->update(['device_id' => $deviceId]);
        $keep->refresh();
        $other->refresh();

        $this->service()->unbindDeviceId($deviceId, $keep->id);

        $other->refresh();
        $keep->refresh();

        $this->assertNull($other->device_id);
        $this->assertSame($deviceId, $keep->device_id);
    }

    public function test_log_member_login_creates_row(): void
    {
        $this->bindClientIp('0.0.0.0');

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'log_' . uniqid()]);

        $this->service()->logMemberLogin($member->id, 1);

        $this->assertDatabaseHas('member_login_logs', [
            'member_id' => $member->id,
            'status' => 1,
            'ip' => '0.0.0.0',
        ]);
    }

    public function test_gen_token_delegates_to_member_access_token_service(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'deleg_' . uniqid()]);

        $token = [REDACTED];

        $this->assertSame(32, strlen($token));
        $this->assertDatabaseHas('member_access_tokens', [
            'member_id' => $member->id,
            'token' => $token,
        ]);
    }

    public function test_update_member_login_data_updates_fields(): void
    {
        $this->bindClientIp('0.0.0.0');

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, [
            'username' => 'upd_' . uniqid(),
        ]);
        $member->statistics()->create([
            'login_fail_count' => 3,
            'validation_code_fail_count' => 2,
        ]);

        $this->service()->updateMemberLoginData($member, [
            'device_id' => 'dev-xyz',
            'platform' => 'ios',
            'domain' => 'example.invalid',
        ]);

        $member->refresh();
        $member->load('statistics');

        $this->assertSame(0, (int) $member->statistics->login_fail_count);
        $this->assertSame(0, (int) $member->statistics->validation_code_fail_count);
        $this->assertSame('dev-xyz', $member->device_id);
        $this->assertSame('ios', $member->device_type);
        $this->assertSame('example.invalid', $member->register_domain);
        $this->assertSame((int) ip2long('0.0.0.0'), (int) $member->last_login_ip);
    }

    public function test_update_member_login_data_clears_mobile_email_match_fail_count_cache(): void
    {
        $this->bindClientIp('0.0.0.0');

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'cache_' . uniqid()]);

        $cacheKey = CacheKeys::moblieEmailMatchFailCount($member->id);
        Redis::set($cacheKey, 2);

        $this->service()->updateMemberLoginData($member, [
            'platform' => 'ios',
        ]);

        $this->assertNull(Redis::get($cacheKey));
    }

    public function test_prepare_response_data_basic_shape(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, [
            'username' => 'resp_' . uniqid(),
            'event_mode' => 0,
        ]);

        $settings = [
            AgentCustomizedSettingKeyEnum::INVITATION_CODE_SAME_ACCOUNT => 0,
        ];

        $data = $this->service()->prepareResponseData(
            $member,
            $agent,
            'test-token-value',
            $agent->organization_id,
            $settings,
            ['api_user' => 'cs-api-user', 'api_key' => 'cs-api-key']
        );

        $this->assertSame($member->id, $data['id']);
        $this->assertSame($member->username, $data['username']);
        $this->assertSame('test-token-value', $data['token']);
        $this->assertSame(0, $data['event_mode']);
        $this->assertSame(0, $data['is_force_unlock_event_mode']);
        $this->assertSame($agent->code, $data['SHARE_CODE']);
        $this->assertSame('cs-api-user', $data['CS_API_USER']);
        $this->assertSame('cs-api-key', $data['CS_API_KEY']);
    }

    /**
     * 會員專屬客服聊天 key：CS 回應成功時，CS_API_USER 維持代理層級識別，
     * CS_API_KEY 改用 CS 核發的會員專屬值（客服聊天會員身分驗證修正）。
     */
    public function test_issue_member_chat_key_uses_cs_issued_key_on_success(): void
    {
        $agent = $this->createAgentAndDomain([
            'username' => 'ls_cs_agent_' . uniqid(),
            'CS_API_USER' => 'agent-cs-user',
            'CS_API_KEY' => 'agent-shared-key',
        ]);
        $member = $this->createMember($agent, ['username' => 'cs_member_' . uniqid()]);

        Http::fake([
            '*' => Http::response(['code' => 1000, 'data' => ['key' => 'member-only-key']], 200),
        ]);

        $result = $this->service()->issueMemberChatKey($member, $agent);

        $this->assertSame('agent-cs-user', $result['api_user']);
        $this->assertSame('member-only-key', $result['api_key']);
    }

    /**
     * CS 呼叫失敗（code 非 1000）時退回代理共用值，不中斷登入。
     */
    public function test_issue_member_chat_key_falls_back_when_cs_call_fails(): void
    {
        $agent = $this->createAgentAndDomain([
            'username' => 'ls_cs_agent2_' . uniqid(),
            'CS_API_USER' => 'agent-cs-user-2',
            'CS_API_KEY' => 'agent-shared-key-2',
        ]);
        $member = $this->createMember($agent, ['username' => 'cs_member2_' . uniqid()]);

        Http::fake([
            '*' => Http::response(['code' => 0, 'message' => 'invalid agent'], 200),
        ]);

        $result = $this->service()->issueMemberChatKey($member, $agent);

        $this->assertSame('agent-cs-user-2', $result['api_user']);
        $this->assertSame('agent-shared-key-2', $result['api_key']);
    }

    /**
     * 代理根本沒接 CS（CS_API_USER／CS_API_KEY 為空）時直接退回，不打 CS。
     */
    public function test_issue_member_chat_key_skips_cs_call_when_agent_has_no_credentials(): void
    {
        $agent = $this->createAgentAndDomain([
            'username' => 'ls_cs_agent3_' . uniqid(),
            'CS_API_USER' => null,
            'CS_API_KEY' => null,
        ]);
        $member = $this->createMember($agent, ['username' => 'cs_member3_' . uniqid()]);

        Http::fake();

        $result = $this->service()->issueMemberChatKey($member, $agent);

        $this->assertSame('', $result['api_user']);
        $this->assertSame('', $result['api_key']);
        Http::assertNothingSent();
    }

    public function test_log_member_ip_writes_ipv4_log(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'ip_' . uniqid()]);

        $this->service()->logMemberIp($member->id, $agent->hierarchy_path ?? '/', '0.0.0.0');

        $this->assertDatabaseHas('member_login_ip_logs', [
            'member_id' => $member->id,
            'ip_1' => 192,
            'ip_2' => 0,
            'ip_3' => 2,
            'ip_4' => 55,
            'ip_address' => '0.0.0.0',
        ]);
    }

    public function test_log_member_ip_writes_ipv6_to_ip_address(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'ip6_' . uniqid()]);

        $this->service()->logMemberIp($member->id, '/', '2001:db8::1');

        // 3963：IPv6 不再跳過，四段填 0、完整字串存 ip_address
        $this->assertDatabaseHas('member_login_ip_logs', [
            'member_id' => $member->id,
            'ip_1' => 0,
            'ip_2' => 0,
            'ip_3' => 0,
            'ip_4' => 0,
            'ip_address' => '2001:db8::1',
        ]);
    }

    public function test_auth2fa_verifies_otp(): void
    {
        $g = new Google2FA;
        $secret = [REDACTED];
        $otp = $g->getCurrentOtp($secret);

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'g2fa_' . uniqid()]);

        MemberAuthentication::create([
            'member_id' => $member->id,
            'type' => 1,
            'secret' => $secret,
            'status' => 1,
            'admin' => 0,
        ]);

        $this->assertTrue($this->service()->auth2FA($member->id, $otp));
    }

    /**
     * 登入密碼錯誤計次：會員鎖（members 主鍵列 FOR UPDATE）必須排在讀失敗次數之前，
     * 失敗次數也要用鎖定讀重取後才加一寫回，同一會員的併發失敗請求才會依序計次、不少算。
     * 兩個請求真的同時進來的情境在測試框架重現不了，這裡改驗查詢順序。
     */
    public function test_increase_login_fail_count_locks_member_row_before_reading_statistics(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'lfc_order_' . uniqid()]);

        DB::enableQueryLog();
        $count = $this->service()->increaseLoginFailCount($member, limit: 6, canRecoverPassword: [REDACTED];
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame(1, $count, '沒有統計列時要先建立，再從 0 加到 1');

        $memberLockAt = $this->firstQueryIndex($queries, '/^select .* from `members` .* for update$/');
        $statisticsLockAt = $this->firstQueryIndex($queries, '/^select .* from `member_statistics` .* for update$/');
        $statisticsUpdateAt = $this->firstQueryIndex($queries, '/^update `member_statistics`/');

        // 交易內第一個一般讀（不論哪張表）決定讀取快照，所以會員鎖必須是第一條查詢
        $this->assertSame(0, $memberLockAt, '會員鎖應是交易內第一條查詢，排在任何一般讀之前');
        $this->assertNotNull($statisticsLockAt, '失敗次數應以鎖定讀重取');
        $this->assertNotNull($statisticsUpdateAt, '應寫回失敗次數');
        $this->assertLessThan($statisticsUpdateAt, $statisticsLockAt, '寫回前應先鎖定讀重取');
    }

    public function test_increase_login_fail_count_locks_member_when_limit_reached(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'lfc_lock_' . uniqid()]);
        $member->statistics()->create(['login_fail_count' => 5, 'total_login_fail_count' => 10]);

        $count = $this->service()->increaseLoginFailCount($member, limit: 6, canRecoverPassword: [REDACTED];

        $this->assertSame(6, $count);
        $statistics = $member->statistics()->first();
        $this->assertSame(6, (int) $statistics->login_fail_count);
        $this->assertSame(11, (int) $statistics->total_login_fail_count);

        $this->assertSame(MemberStatusEnum::LOCKED->value, $member->fresh()->status);
        $logs = MemberStatusLog::where('member_id', $member->id)->get();
        $this->assertCount(1, $logs);
        $this->assertSame(MemberStatusLogTypeEnum::LOGIN_PASSWORD_LIMIT, $logs->first()->type);
        $this->assertSame(MemberStatusEnum::VERIFIED, $logs->first()->before_status);
    }

    public function test_increase_login_fail_count_does_not_lock_when_password_recoverable(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'lfc_recover_' . uniqid()]);
        $member->statistics()->create(['login_fail_count' => 5]);

        $count = $this->service()->increaseLoginFailCount($member, limit: 6, canRecoverPassword: [REDACTED];

        $this->assertSame(6, $count, '達上限仍要計次，由呼叫端導向找回密碼');
        $this->assertSame(MemberStatusEnum::VERIFIED->value, $member->fresh()->status);
        $this->assertSame(0, MemberStatusLog::where('member_id', $member->id)->count());
    }

    /**
     * 同時進來的另一個請求已經把會員鎖定，手上的 $member 仍是鎖定前讀到的舊狀態：
     * 以鎖內重讀的狀態為準，不重複寫狀態紀錄，只作廢 token
     */
    public function test_increase_login_fail_count_skips_status_log_when_member_already_locked(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'lfc_locked_' . uniqid()]);
        $member->statistics()->create(['login_fail_count' => 6]);
        $token = [REDACTED]
            'member_id' => $member->id,
            'token' => 'lfc-locked-token-' . uniqid(),
            'expire_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'admin' => 0,
        ]);
        Member::whereKey($member->id)->update(['status' => MemberStatusEnum::LOCKED->value]);

        $count = $this->service()->increaseLoginFailCount($member, limit: 6, canRecoverPassword: [REDACTED];

        $this->assertSame(7, $count);
        $this->assertSame(0, MemberStatusLog::where('member_id', $member->id)->count(), '已鎖定的會員不應再寫一筆狀態紀錄');
        $this->assertLessThanOrEqual(time(), strtotime($token->fresh()->expire_date), '已鎖定的會員仍要作廢有效 token');
    }

    /**
     * 寫入鎖定失敗時，失敗次數一起回滾，下一次失敗會重新計次並再嘗試鎖帳
     */
    public function test_increase_login_fail_count_rolls_back_count_when_lock_write_fails(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'lfc_rollback_' . uniqid()]);
        $member->statistics()->create(['login_fail_count' => 5, 'total_login_fail_count' => 10]);

        // 限定只被呼叫一次並核對例外訊息，確保回滾是寫入鎖定失敗造成的，不是更早的例外
        $tokenService = Mockery::mock(MemberAccessTokenService::class)->makePartial();
        $tokenService->shouldReceive('lockMember')->once()->andThrow(new RuntimeException('simulated lock failure'));
        $this->app->instance(MemberAccessTokenService::class, $tokenService);

        $caught = null;
        try {
            $this->service()->increaseLoginFailCount($member, limit: 6, canRecoverPassword: [REDACTED];
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertSame('simulated lock failure', $caught?->getMessage(), '寫入鎖定失敗的例外應向外拋出');
        $statistics = $member->statistics()->first();
        $this->assertSame(5, (int) $statistics->login_fail_count, '失敗次數應隨交易回滾');
        $this->assertSame(10, (int) $statistics->total_login_fail_count, '總失敗次數應隨交易回滾');
    }

    /**
     * 鎖定提交後作廢 token 失敗：帳號仍維持鎖定、失敗次數保留、不往外拋，只告警
     */
    public function test_increase_login_fail_count_keeps_lock_and_count_when_revoke_fails(): void
    {
        Exceptions::fake();

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'lfc_revokefail_' . uniqid()]);
        $member->statistics()->create(['login_fail_count' => 5, 'total_login_fail_count' => 10]);

        $tokenService = Mockery::mock(MemberAccessTokenService::class)->makePartial();
        $tokenService->shouldReceive('revoke')->once()->andThrow(new RuntimeException('simulated revoke failure'));
        $this->app->instance(MemberAccessTokenService::class, $tokenService);

        $count = $this->service()->increaseLoginFailCount($member, limit: 6, canRecoverPassword: [REDACTED];

        $this->assertSame(6, $count);
        $this->assertSame(6, (int) $member->statistics()->first()->login_fail_count, '作廢 token 失敗不應回滾失敗次數');
        $this->assertSame(MemberStatusEnum::LOCKED->value, $member->fresh()->status, '作廢 token 失敗時帳號仍應維持鎖定');
        $this->assertSame(1, MemberStatusLog::where('member_id', $member->id)->count());
        Exceptions::assertReported(
            static fn (RuntimeException $e): bool => $e->getMessage() === 'simulated revoke failure'
        );
    }

    public function test_check_block_returns_true_when_no_blocks_enabled(): void
    {
        $member = Member::factory()->make();
        $event = new \stdClass;
        $event->ip_block = false;
        $event->phone_block = false;
        $event->account_block = false;

        $this->assertTrue($this->service()->checkBlock($member, $event));
    }
}
