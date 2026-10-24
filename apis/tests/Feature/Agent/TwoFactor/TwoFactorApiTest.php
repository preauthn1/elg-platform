<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\TwoFactor;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentOperateTypeEnum;
use App\Enums\Agent\TwoFactorResultCodeEnum;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentAuthentication;
use App\Models\AgentOperateLog;
use App\Models\AgentPermissionGroup;
use App\Services\Agent\AgentTwoFactorService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\Feature\Agent\AgentPermission\Concerns\BuildsAgentPermissionFixtures;
use Tests\TestCase;

/**
 * 代理 Google 2FA 接口（target/tickets#4518 自 API_Agent 搬入）。
 *
 * Redis 只動本測試自己建的 key（金鑰暫存、模擬 API_Agent 的 token 快取），tearDown 逐把刪。
 */
class TwoFactorApiTest extends TestCase
{
    use BuildsAgentPermissionFixtures;

    /** @var list<string> */
    private array $redisKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAgentApi();
    }

    protected function tearDown(): void
    {
        if ($this->redisKeys !== []) {
            Redis::connection()->del(...array_unique($this->redisKeys));
        }
        parent::tearDown();
    }

    public function test_image_generates_secret_once_and_caches_it(): void
    {
        $agent = $this->bareAgent(['has_2fa' => 0]);
        $this->trackLegacyKey($agent);
        $this->actAs($agent);

        $first = $this->callApi('2fa/image')->assertJsonPath('code', 200)->json('data');
        $second = $this->callApi('2fa/image')->assertJsonPath('code', 200)->json('data');

        $this->assertNotSame('', $first['secret']);
        $this->assertStringContainsString('<svg', $first['image']);
        $this->assertStringContainsString('version="1.1" width="100%"', $first['image']);
        $this->assertStringContainsString('<rect x="0" y="0" width="400" height="400"', $first['image'], '背景 rect 要保留寬高，否則深色主題下 QR 碼沒有白底');
        $this->assertSame($first['secret'], $second['secret'], '第二次應命中暫存，金鑰不變');
        $this->assertSame(1, AgentAuthentication::query()->where('agent_id', $agent->id)->where('status', 1)->count());

        // API_Agent 用 predis 讀原始值再 json_decode：暫存必須是純 JSON 字串（不能被 apis 預設連線 PHP 序列化）
        $raw = $this->rawRedisGet(CacheKeys::legacyAgentAuthGoogle($agent->id));
        $this->assertIsString($raw, '暫存要用 API_Agent 同一把 key');
        $decoded = json_decode($raw, true);
        $this->assertSame($first['secret'], $decoded['secret'] ?? null, '暫存必須是 API_Agent 解得出來的純 JSON');
    }

    public function test_image_reads_secret_cached_by_legacy_backend(): void
    {
        $agent = $this->bareAgent(['has_2fa' => 0]);
        $key = $this->trackLegacyKey($agent);
        // 模擬 API_Agent（predis、原始 JSON 字串）寫的暫存
        $this->rawRedisSet($key, (string) json_encode(['image' => '<svg>legacy</svg>', 'secret' => 'LEGACYSECRET']));
        $this->actAs($agent);

        $this->callApi('2fa/image')
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.secret', 'LEGACYSECRET')
            ->assertJsonPath('data.image', '<svg>legacy</svg>');
    }

    public function test_image_hides_secret_once_enabled(): void
    {
        $agent = $this->bareAgent(['has_2fa' => 1]);
        $this->actAs($agent);

        $this->callApi('2fa/image')
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.secret', '')
            ->assertJsonPath('data.image', '');
    }

    public function test_enable_with_valid_otp_sets_flag_logs_and_revokes_own_login(): void
    {
        $agent = $this->bareAgent(['has_2fa' => 0]);
        $secret = [REDACTED];
        $this->actAs($agent);
        $token = [REDACTED];
        $apiAgentKey = $this->fakeApiAgentCache($token);

        $this->callApi('2fa/enable', ['secret' => $this->otp($secret)])
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.relogin_required', true);

        $this->assertSame(1, (int) $agent->refresh()->has_2fa);
        $this->assertOperateLogged($agent, $agent, AgentOperateTypeEnum::ENABLE_AGENT_2FA);
        $this->assertTokenRevoked($token);
        $this->assertNull(Redis::connection()->get($apiAgentKey), 'API_Agent 的 token 快取要一併刪掉');
    }

    public function test_enable_with_wrong_otp_returns_1098_and_keeps_flag_off(): void
    {
        $agent = $this->bareAgent(['has_2fa' => 0]);
        $secret = [REDACTED];
        $this->actAs($agent);

        $this->callApi('2fa/enable', ['secret' => $this->wrongOtp($secret)])
            ->assertStatus(200)
            ->assertJsonPath('code', TwoFactorResultCodeEnum::OTP_INVALID->value)
            ->assertJsonPath('message', __('error_message.agent_2fa.otp_invalid'));

        $this->assertSame(0, (int) $agent->refresh()->has_2fa);
        $this->assertFalse($this->tokenIsRevoked($this->tokenOf($agent)), '驗證碼錯誤不能踢人');
    }

    public function test_enable_twice_returns_1131(): void
    {
        $agent = $this->bareAgent(['has_2fa' => 1]);
        $secret = [REDACTED];
        $this->actAs($agent);

        $this->callApi('2fa/enable', ['secret' => $this->otp($secret)])
            ->assertJsonPath('code', TwoFactorResultCodeEnum::ALREADY_ENABLED->value);
    }

    public function test_disable_self_turns_off_and_requires_relogin(): void
    {
        $agent = $this->bareAgent(['has_2fa' => 1]);
        $secret = [REDACTED];
        $this->actAs($agent);
        $token = [REDACTED];

        $this->callApi('2fa/disable', ['secret' => $this->otp($secret)])
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.relogin_required', true);

        $this->assertSame(0, (int) $agent->refresh()->has_2fa);
        $this->assertOperateLogged($agent, $agent, AgentOperateTypeEnum::DISABLE_AGENT_2FA);
        $this->assertTokenRevoked($token);
        // 關閉即作廢舊金鑰、發一組新的（#4541）
        $active = AgentAuthentication::query()->where('agent_id', $agent->id)->where('status', 1)->get();
        $this->assertCount(1, $active);
        $this->assertNotSame($secret, (string) $active->first()->secret);
    }

    public function test_disable_rotates_secret_and_image_shows_new_one_until_enabled(): void
    {
        $agent = $this->bareAgent(['has_2fa' => 1]);
        $oldSecret = [REDACTED];
        $legacyKey = $this->trackLegacyKey($agent);
        $this->rawRedisSet($legacyKey, (string) json_encode(['image' => 'old', 'secret' => $oldSecret]));
        $this->actAs($agent);

        $this->callApi('2fa/disable', ['secret' => $this->otp($oldSecret)])->assertJsonPath('code', 200);
        $this->assertNull($this->rawRedisGet($legacyKey), '舊金鑰暫存要刪');

        // 自己關閉後原 token 已作廢（#4518），要重新登入才能繼續
        $this->actAs($agent);

        // 綁定前反覆打開設置，都是同一組新的
        $first = $this->callApi('2fa/image')->assertJsonPath('code', 200)->json('data');
        $second = $this->callApi('2fa/image')->assertJsonPath('code', 200)->json('data');
        $this->assertNotSame($oldSecret, $first['secret']);
        $this->assertStringContainsString('<svg', $first['image']);
        $this->assertSame($first['secret'], $second['secret']);
        $this->assertSame(1, AgentAuthentication::query()->where('agent_id', $agent->id)->where('status', 1)->count());

        // 舊金鑰產生的驗證碼不能重新開啟
        $this->callApi('2fa/enable', ['secret' => $this->otp($oldSecret)])
            ->assertJsonPath('code', TwoFactorResultCodeEnum::OTP_INVALID->value);
        $this->assertSame(0, (int) $agent->refresh()->has_2fa);
    }

    public function test_disable_other_requires_operator_2fa(): void
    {
        $top = $this->topAgent();
        $target = $this->downline($top, ['has_2fa' => 1]);
        $this->bindSecret($target);
        $this->actAs($top);

        $this->callApi('2fa/disable', ['agent_id' => $target->id, 'secret' => '000000'])
            ->assertJsonPath('code', TwoFactorResultCodeEnum::OPERATOR_NOT_ENABLED->value);

        $this->assertSame(1, (int) $target->refresh()->has_2fa);
    }

    public function test_disable_other_not_enabled_returns_1100(): void
    {
        $top = $this->topAgent();
        $top->forceFill(['has_2fa' => 1])->save();
        $secret = [REDACTED];
        $target = $this->downline($top, ['has_2fa' => 0]);
        $this->actAs($top);

        $this->callApi('2fa/disable', ['agent_id' => $target->id, 'secret' => $this->otp($secret)])
            ->assertJsonPath('code', TwoFactorResultCodeEnum::TARGET_NOT_ENABLED->value);
    }

    public function test_disable_other_blocked_when_target_uses_admin_role(): void
    {
        $operator = $this->adminSubAccount();
        $operator->forceFill(['has_2fa' => 1])->save();
        $secret = [REDACTED];
        $target = $this->adminSubAccount();
        $target->forceFill(['has_2fa' => 1])->save();
        $this->bindRole($target, $this->role('admin', AgentPermissionGroup::ADMIN_OWNER_ID));
        $this->actAs($operator);

        $this->callApi('2fa/disable', ['agent_id' => $target->id, 'secret' => $this->otp($secret)])
            ->assertJsonPath('code', TwoFactorResultCodeEnum::TARGET_ADMIN_ROLE->value);

        $this->assertSame(1, (int) $target->refresh()->has_2fa);
    }

    public function test_disable_other_revokes_target_login_but_keeps_operator(): void
    {
        $top = $this->topAgent();
        $top->forceFill(['has_2fa' => 1])->save();
        $secret = [REDACTED];
        $target = $this->downline($top, ['has_2fa' => 1]);
        $this->bindSecret($target);
        $targetToken = [REDACTED];
        $this->actAs($top);
        $operatorToken = [REDACTED];

        $this->callApi('2fa/disable', ['agent_id' => $target->id, 'secret' => $this->otp($secret)])
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.relogin_required', false);

        $this->assertSame(0, (int) $target->refresh()->has_2fa);
        $this->assertOperateLogged($top, $target, AgentOperateTypeEnum::DISABLE_AGENT_2FA);
        $this->assertTokenRevoked($targetToken);
        $this->assertFalse($this->tokenIsRevoked($operatorToken), '別人被關 2FA，操作者自己不該被踢');
    }

    public function test_reset_requires_permission_code(): void
    {
        // 選單碼要先存在：CheckAgentPermission 對查無選單的碼刻意 fail-open
        $this->menu('agent_reset_2fa');
        $this->menu('cj7_user_list_reset_2fa');
        $top = $this->topAgent();
        $top->forceFill(['has_2fa' => 1])->save();
        $secret = [REDACTED];
        $target = $this->downline($top, ['has_2fa' => 1]);
        $this->bindSecret($target);
        $this->actAs($top);

        $this->callApi('2fa/reset-agent', ['agent_id' => $target->id, 'secret' => $this->otp($secret)])
            ->assertJsonPath('message', __('error_message.9003'));

        $this->assertSame(1, (int) $target->refresh()->has_2fa);
    }

    public function test_reset_self_returns_1130(): void
    {
        $top = $this->topAgent();
        $top->forceFill(['has_2fa' => 1])->save();
        $secret = [REDACTED];
        $this->grant($top, 'agent_reset_2fa');
        $this->actAs($top);

        $this->callApi('2fa/reset-agent', ['agent_id' => $top->id, 'secret' => $this->otp($secret)])
            ->assertJsonPath('code', TwoFactorResultCodeEnum::CANNOT_RESET_SELF->value);
    }

    public function test_reset_disables_target_voids_secret_and_revokes_login(): void
    {
        $top = $this->topAgent();
        $top->forceFill(['has_2fa' => 1])->save();
        $secret = [REDACTED];
        $this->grant($top, 'cj7_user_list_reset_2fa');
        $target = $this->downline($top, ['has_2fa' => 1]);
        $this->bindSecret($target);
        $targetToken = [REDACTED];
        $legacyKey = $this->trackLegacyKey($target);
        $this->rawRedisSet($legacyKey, '{"image":"x","secret":"y"}');
        $this->actAs($top);

        $this->callApi('2fa/reset-agent', ['agent_id' => $target->id, 'secret' => $this->otp($secret)])
            ->assertJsonPath('code', 200)
            ->assertJsonPath('data.relogin_required', false);

        $this->assertSame(0, (int) $target->refresh()->has_2fa);
        $this->assertSame(0, AgentAuthentication::query()->where('agent_id', $target->id)->where('status', 1)->count(), '舊金鑰要作廢');
        $this->assertNull($this->rawRedisGet($legacyKey), '金鑰暫存要刪');
        $this->assertOperateLogged($top, $target, AgentOperateTypeEnum::RESET_AGENT_2FA);
        $this->assertTokenRevoked($targetToken);
    }

    public function test_auth_rejects_agent_outside_scope(): void
    {
        $top = $this->topAgent();
        $other = $this->topAgent();
        $this->bindSecret($other);
        $this->actAs($top);

        $this->callApi('2fa/auth', ['agent_id' => $other->id, 'secret' => '000000'])
            ->assertJsonPath('message', __('error_message.9003'));
    }

    public function test_auth_verifies_own_otp(): void
    {
        $agent = $this->bareAgent();
        $secret = [REDACTED];
        $this->actAs($agent);

        $this->callApi('2fa/auth', ['agent_id' => $agent->id, 'secret' => $this->otp($secret)])
            ->assertJsonPath('code', 200);
        $this->callApi('2fa/auth', ['agent_id' => $agent->id, 'secret' => $this->wrongOtp($secret)])
            ->assertJsonPath('code', TwoFactorResultCodeEnum::OTP_INVALID->value);
    }

    public function test_wrong_otp_is_business_result_not_system_error(): void
    {
        $agent = $this->bareAgent(['has_2fa' => 0]);
        $secret = [REDACTED];
        $this->actAs($agent);
        Log::spy();

        $this->callApi('2fa/enable', ['secret' => $this->wrongOtp($secret)])
            ->assertStatus(200)
            ->assertJsonPath('code', TwoFactorResultCodeEnum::OTP_INVALID->value);

        // 輸錯驗證碼是正常的業務拒絕，不能寫 error log、不能觸發例外告警
        Log::shouldNotHaveReceived('error');
    }

    public function test_expire_all_still_revokes_when_redis_fails(): void
    {
        $agent = $this->bareAgent();
        $token = [REDACTED];
        Log::spy();
        Redis::shouldReceive('connection')->andThrow(new RuntimeException('redis down'));

        AgentAccessToken:[REDACTED] $agent->id);

        $this->assertTokenRevoked($token);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_enable_still_revokes_login_when_redis_fails(): void
    {
        $agent = $this->bareAgent(['has_2fa' => 0]);
        $secret = [REDACTED];
        $token = [REDACTED];
        Log::spy();
        Redis::shouldReceive('connection')->andThrow(new RuntimeException('redis down'));

        app(AgentTwoFactorService::class)->enable($agent, $this->otp($secret));

        $this->assertSame(1, (int) $agent->refresh()->has_2fa);
        $this->assertTokenRevoked($token);
    }

    /** 替代理綁一組啟用中的金鑰，回傳金鑰 */
    private function bindSecret(Agent $agent): string
    {
        $secret = [REDACTED] Google2FA)->generateSecretKey();
        AgentAuthentication::query()->create([
            'agent_id' => $agent->id,
            'type' => 1,
            'secret' => $secret,
            'image' => '',
            'status' => 1,
            'admin' => 0,
        ]);

        return $secret;
    }

    private function otp(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    private function wrongOtp(string $secret): string
    {
        return $this->otp($secret) === '000000' ? '000001' : '000000';
    }

    /** actAs() 發的 token（登入中的這一把） */
    private function tokenOf(Agent $agent): string
    {
        return (string) AgentAccessToken:[REDACTED]'agent_id', $agent->id)->latest('id')->value('token');
    }

    /** 替對象另發一把有效 token，模擬他在別處登入中 */
    private function issueToken(Agent $agent): string
    {
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $agent->id,
            'token' => $token,
            'expire_date' => '2099-01-01 00:00:00',
            'admin' => 1,
        ]);

        return $token;
    }

    /** 模擬 API_Agent Authenticate middleware 已把這把 token 快取在 redis */
    private function fakeApiAgentCache(string $token): string
    {
        $key = CacheKeys::apiAgentAccessToken($token);
        Redis::connection()->setex($key, 300, '1');
        $this->redisKeys[] = $key;

        return $key;
    }

    /**
     * 繞過 apis 連線的序列化，直接讀寫 Redis 原始值：模擬 API_Agent（predis、純 JSON 字串）那一端。
     */
    private function rawRedisGet(string $key): ?string
    {
        $value = Redis::connection()->client()->rawCommand('GET', $key);

        return is_string($value) ? $value : null;
    }

    private function rawRedisSet(string $key, string $json): void
    {
        Redis::connection()->client()->rawCommand('SETEX', $key, 60, $json);
        $this->redisKeys[] = $key;
    }

    private function trackLegacyKey(Agent $agent): string
    {
        $key = CacheKeys::legacyAgentAuthGoogle($agent->id);
        $this->redisKeys[] = $key;

        return $key;
    }

    private function tokenIsRevoked(string $token): bool
    {
        $expireDate = (string) AgentAccessToken:[REDACTED]'token', $token)->value('expire_date');

        return $expireDate !== '' && $expireDate <= now()->toDateTimeString();
    }

    private function assertTokenRevoked(string $token): void
    {
        $this->assertTrue($this->tokenIsRevoked($token), 'token 到期時間應改成現在');
    }

    private function assertOperateLogged(Agent $operator, Agent $target, AgentOperateTypeEnum $type): void
    {
        $this->assertTrue(
            AgentOperateLog::query()
                ->where('agent_id', $operator->id)
                ->where('type', $type->value)
                ->where('reference_id', (string) $target->id)
                ->where('reference_type', 'agent')
                ->exists(),
            "應寫一筆 {$type->value} 操作紀錄",
        );
    }
}
