<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\General\HttpCode;
use App\Enums\IpRestrictionBlockItemEnum;
use App\Http\Middleware\Frontend\AuthMember;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\IpRestriction;
use App\Models\Member;
use App\Services\IpRestrictionGuard;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 風控IP限制 enforcement 真值表（4096，含追加的阻擋項目多選）。
 *
 * php artisan test tests/Unit/Services/IpRestrictionGuardTest.php
 */
class IpRestrictionGuardTest extends TestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = $this->createAgentAndDomain();
    }

    private function guard(): IpRestrictionGuard
    {
        return app(IpRestrictionGuard::class);
    }

    private function makeRule(string $ip, array $overrides = []): IpRestriction
    {
        return IpRestriction::create(array_merge([
            'ip' => $ip,
            'status' => 1,
            'admin' => $this->agent->id,
            // 預設只勾「登入」，與追加阻擋項目(4096)前的既有語意一致
            'block_login' => 1,
        ], $overrides));
    }

    /**
     * Common::getIp() 使用 request()->server('REMOTE_ADDR')，僅設 $_SERVER 不足。
     */
    private function bindClientIp(string $ip, ?Request $request = null): Request
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        $request ??= Request::create(uri: '/', method: 'GET', server: ['REMOTE_ADDR' => $ip]);
        $this->app->instance('request', $request);

        return $request;
    }

    /** factory 預設 username 為固定值，需自帶唯一帳號避免撞 members_username_unique */
    private function newMember(Agent $agent): Member
    {
        return $this->createMember($agent, ['username' => 'ir' . bin2hex(random_bytes(6))]);
    }

    #[TestDox('無任何規則時不擋')]
    public function test_no_rule_means_not_blocked(): void
    {
        $this->assertFalse($this->guard()->isBlocked('0.0.0.0', IpRestrictionBlockItemEnum::LOGIN));
        $this->assertFalse($this->guard()->blockAttempt('0.0.0.0', IpRestrictionBlockItemEnum::LOGIN));
    }

    #[TestDox('status=1 擋、status=0 不擋（關閉即停用）')]
    public function test_status_switch(): void
    {
        $this->makeRule('0.0.0.0');
        $this->makeRule('0.0.0.0', ['status' => 0]);

        $this->assertTrue($this->guard()->isBlocked('0.0.0.0', IpRestrictionBlockItemEnum::LOGIN));
        $this->assertFalse($this->guard()->isBlocked('0.0.0.0', IpRestrictionBlockItemEnum::LOGIN));
        $this->assertFalse($this->guard()->blockAttempt('0.0.0.0', IpRestrictionBlockItemEnum::LOGIN));
        $this->assertSame(0, (int) IpRestriction::where('ip', '0.0.0.0')->value('repeat_count'));
    }

    #[TestDox('blockAttempt：命中回 true 且 repeat_count 逐次累計')]
    public function test_block_login_attempt_increments(): void
    {
        $this->makeRule('0.0.0.0');

        foreach ([1, 2, 3] as $expected) {
            $this->assertTrue($this->guard()->blockAttempt('0.0.0.0', IpRestrictionBlockItemEnum::LOGIN));
            $this->assertSame($expected, (int) IpRestriction::where('ip', '0.0.0.0')->value('repeat_count'));
        }
    }

    #[TestDox('累加不動 updated_at / admin（更新者/更新時間＝最後人工編輯語意）')]
    public function test_increment_preserves_updated_at_and_admin(): void
    {
        $rule = $this->makeRule('0.0.0.0');
        $before = $rule->fresh();

        $this->travel(90)->seconds();
        $this->assertTrue($this->guard()->blockAttempt('0.0.0.0', IpRestrictionBlockItemEnum::LOGIN));

        $after = $rule->fresh();
        $this->assertSame(1, (int) $after->repeat_count);
        $this->assertSame((string) $before->updated_at, (string) $after->updated_at);
        $this->assertSame((int) $before->admin, (int) $after->admin);
    }

    #[TestDox('IPv6 canonicalize：縮寫/大小寫變體視為同一 IP')]
    public function test_ipv6_variants_canonicalized(): void
    {
        $this->makeRule('2001:db8::1');

        $this->assertTrue($this->guard()->isBlocked('2001:DB8:0:0:0:0:0:1', IpRestrictionBlockItemEnum::LOGIN));
        $this->assertTrue($this->guard()->blockAttempt('2001:DB8::0001', IpRestrictionBlockItemEnum::LOGIN));
        $this->assertSame(1, (int) IpRestriction::where('ip', '2001:db8::1')->value('repeat_count'));
    }

    #[TestDox('AuthMember middleware：被封 IP 的授權請求回 HTTP_REDIRECT_LOGOUT 且不累計 repeat_count')]
    public function test_auth_member_middleware_kicks_blocked_ip_without_increment(): void
    {
        $member = $this->newMember($this->agent);
        $token = [REDACTED];
        $this->makeRule('0.0.0.0');

        $request = Request::create('/frontend/api/anything', 'POST', server: ['REMOTE_ADDR' => '0.0.0.0']);
        $request->headers->set('Authorization', 'Bearer ' . $token);
        $this->bindClientIp('0.0.0.0', $request);

        $response = app(AuthMember::class)->handle($request, fn () => response('should-not-reach'));
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(HttpCode::HTTP_REDIRECT_LOGOUT->value, (int) $payload['code']);
        $this->assertSame(0, (int) IpRestriction::where('ip', '0.0.0.0')->value('repeat_count'));
    }

    #[TestDox('AuthMember middleware：status=0 的 IP 正常通過')]
    public function test_auth_member_middleware_passes_disabled_rule(): void
    {
        $member = $this->newMember($this->agent);
        $token = [REDACTED];
        $this->makeRule('0.0.0.0', ['status' => 0]);

        $request = Request::create('/frontend/api/anything', 'POST', server: ['REMOTE_ADDR' => '0.0.0.0']);
        $request->headers->set('Authorization', 'Bearer ' . $token);
        $this->bindClientIp('0.0.0.0', $request);

        $response = app(AuthMember::class)->handle($request, fn () => response('passed'));

        $this->assertSame('passed', $response->getContent());
    }

    #[TestDox('登入整層：被封 IP 擋登入並累計（連帳號不存在的嘗試也擋）')]
    public function test_login_endpoint_blocks_and_increments(): void
    {
        $this->withoutMiddleware(DecryptRequest::class);
        $this->makeRule('0.0.0.0');
        $this->withServerVariables(['REMOTE_ADDR' => '0.0.0.0']);

        $params = [
            // 帳號不存在也要被擋：IP 檢查先於 member 解析
            'username' => 'no-such-user',
            'password' => 'whatever123',
            'domain' => $this->domain,
        ];

        $first = $this->postJson('frontend/api/guest/login', $params);
        $first->assertJson([
            'code' => IpRestrictionBlockItemEnum::LOGIN->errorCode(),
            'message' => IpRestrictionBlockItemEnum::LOGIN->message(),
        ]);
        $this->assertSame(1, (int) IpRestriction::where('ip', '0.0.0.0')->value('repeat_count'));

        $this->withServerVariables(['REMOTE_ADDR' => '0.0.0.0']);
        $this->postJson('frontend/api/guest/login', $params);
        $this->assertSame(2, (int) IpRestriction::where('ip', '0.0.0.0')->value('repeat_count'));
    }

    #[TestDox('登入整層：status=0 的 IP 不擋登入')]
    public function test_login_endpoint_passes_disabled_rule(): void
    {
        $this->withoutMiddleware(DecryptRequest::class);
        $this->makeRule('0.0.0.0', ['status' => 0]);
        $this->withServerVariables(['REMOTE_ADDR' => '0.0.0.0']);

        $response = $this->postJson('frontend/api/guest/login', [
            'username' => 'no-such-user',
            'password' => 'whatever123',
            'domain' => $this->domain,
        ]);

        // 走到帳號不存在的原有錯誤，而非 IP 封鎖訊息
        $this->assertNotSame(
            IpRestrictionBlockItemEnum::LOGIN->message(),
            $response->json('message'),
        );
        $this->assertSame(0, (int) IpRestriction::where('ip', '0.0.0.0')->value('repeat_count'));
    }

    #[TestDox('阻擋項目互相隔離：只勾註冊時，登入與簡訊皆不擋')]
    public function test_block_items_are_isolated(): void
    {
        $this->makeRule('0.0.0.0', ['block_register' => 1, 'block_login' => 0, 'block_sms' => 0]);

        $this->assertTrue($this->guard()->isBlocked('0.0.0.0', IpRestrictionBlockItemEnum::REGISTER));
        $this->assertFalse($this->guard()->isBlocked('0.0.0.0', IpRestrictionBlockItemEnum::LOGIN));
        $this->assertFalse($this->guard()->isBlocked('0.0.0.0', IpRestrictionBlockItemEnum::SMS));

        // 未勾選的項目不得累計 repeat_count
        $this->assertFalse($this->guard()->blockAttempt('0.0.0.0', IpRestrictionBlockItemEnum::LOGIN));
        $this->assertSame(0, (int) IpRestriction::where('ip', '0.0.0.0')->value('repeat_count'));
    }

    #[TestDox('repeat_count 不分項目共用同一計數')]
    public function test_repeat_count_is_shared_across_items(): void
    {
        $this->makeRule('0.0.0.0', ['block_register' => 1, 'block_login' => 1, 'block_sms' => 1]);

        $this->assertTrue($this->guard()->blockAttempt('0.0.0.0', IpRestrictionBlockItemEnum::REGISTER));
        $this->assertTrue($this->guard()->blockAttempt('0.0.0.0', IpRestrictionBlockItemEnum::LOGIN));
        $this->assertTrue($this->guard()->blockAttempt('0.0.0.0', IpRestrictionBlockItemEnum::SMS));

        $this->assertSame(3, (int) IpRestriction::where('ip', '0.0.0.0')->value('repeat_count'));
    }

    #[TestDox('註冊整層：勾選註冊的 IP 擋註冊並回錯誤碼 9021')]
    public function test_register_endpoint_blocks_and_increments(): void
    {
        $this->withoutMiddleware(DecryptRequest::class);
        $this->makeRule('0.0.0.0', ['block_register' => 1, 'block_login' => 0]);
        $this->withServerVariables(['REMOTE_ADDR' => '0.0.0.0']);

        $response = $this->postJson('frontend/api/guest/register', [
            'username' => 'ipr' . random_int(1000, 9999),
            'password' => 'abc12345',
            'password_confirmation' => 'abc12345',
            'domain' => $this->domain,
        ]);

        $response->assertJson([
            'code' => IpRestrictionBlockItemEnum::REGISTER->errorCode(),
            'message' => IpRestrictionBlockItemEnum::REGISTER->message(),
        ]);
        $this->assertSame(1, (int) IpRestriction::where('ip', '0.0.0.0')->value('repeat_count'));
    }

    #[TestDox('註冊整層：只勾登入的 IP 不擋註冊')]
    public function test_register_endpoint_passes_when_item_not_selected(): void
    {
        $this->withoutMiddleware(DecryptRequest::class);
        $this->makeRule('0.0.0.0');
        $this->withServerVariables(['REMOTE_ADDR' => '0.0.0.0']);

        $response = $this->postJson('frontend/api/guest/register', [
            'username' => 'ipr' . random_int(1000, 9999),
            'password' => 'abc12345',
            'password_confirmation' => 'abc12345',
            'domain' => $this->domain,
        ]);

        $this->assertNotSame(
            IpRestrictionBlockItemEnum::REGISTER->message(),
            $response->json('message'),
        );
        $this->assertSame(0, (int) IpRestriction::where('ip', '0.0.0.0')->value('repeat_count'));
    }

    #[TestDox('簡訊整層：勾選簡訊的 IP 擋簡訊發送，email 驗證不受影響')]
    public function test_send_validation_code_blocks_sms_only(): void
    {
        $this->withoutMiddleware(DecryptRequest::class);
        $this->makeRule('0.0.0.0', ['block_sms' => 1, 'block_login' => 0]);

        $this->withServerVariables(['REMOTE_ADDR' => '0.0.0.0']);
        $sms = $this->postJson('frontend/api/guest/send-validation-code', [
            'domain' => $this->domain,
            'type' => 2,
            'verify_by' => 1,
            'mobile' => '0912345678',
        ]);
        $sms->assertJson([
            'code' => IpRestrictionBlockItemEnum::SMS->errorCode(),
            'message' => IpRestrictionBlockItemEnum::SMS->message(),
        ]);
        $this->assertSame(1, (int) IpRestriction::where('ip', '0.0.0.0')->value('repeat_count'));

        $this->withServerVariables(['REMOTE_ADDR' => '0.0.0.0']);
        $email = $this->postJson('frontend/api/guest/send-validation-code', [
            'domain' => $this->domain,
            'type' => 2,
            'verify_by' => 2,
            'email' => 'user@example.invalid',
        ]);
        $this->assertNotSame(
            IpRestrictionBlockItemEnum::SMS->message(),
            $email->json('message'),
        );
        // email 驗證未被擋，計數維持簡訊那一次
        $this->assertSame(1, (int) IpRestriction::where('ip', '0.0.0.0')->value('repeat_count'));
    }
}
