<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ValidationCode;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Organization;
use App\Models\ValidateToken;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/ValidationCode/GuestForgetPasswordTest.php
 */
class GuestForgetPasswordTest extends TestCase
{
    protected Agent $agent;

    private const string API_SEND_CODE = 'frontend/api/guest/send-validation-code';

    private const string API_MEMBER_ACCOUNTS = 'frontend/api/guest/get-member-accounts';

    private const string API_RESET_PASSWORD = [REDACTED]';

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $organization = Organization::create([
            'name' => '黒の組織',
            'currency_id' => 1,
            'memo' => '',
        ]);

        $this->agent = $this->createAgentAndDomain([
            'organization_id' => $organization->id,
        ]);

        $this->createMember($this->agent, [
            'username' => 'keroro',
            'email' => 'user@example.invalid',
            'email_validate' => 1,
            'password' => Hash::make('123456'),
        ]);
    }

    private function requestValidationCode(array $params): string
    {
        $this->requestApi(self::API_SEND_CODE, $params);

        return ValidateToken:[REDACTED]'token');
    }

    /**
     * 訪客請求忘記密碼驗證碼 step.1
     */
    public function test_request_validation_code_by_email()
    {
        $params = [
            'domain' => $this->domain,
            'type' => 2,
            'verify_by' => 2,
            'email' => 'user@example.invalid',
        ];

        $response = $this->requestApi(self::API_SEND_CODE, $params);

        $response->assertStatus(200);
    }

    /**
     * 取得匹配的會員帳號 step.2
     */
    public function test_list_member_accounts()
    {
        $token = [REDACTED]
            'domain' => $this->domain,
            'type' => 2,
            'verify_by' => 2,
            'email' => 'user@example.invalid',
        ]);

        $params = [
            'domain' => $this->domain,
            'token' => $token,
            'email' => 'user@example.invalid',
        ];

        $response = $this->requestApi(self::API_MEMBER_ACCOUNTS, $params);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'username_list',
                ],
            ]);
    }

    /**
     * 更新密碼 step.3
     */
    public function test_update_password()
    {
        $token = [REDACTED]
            'domain' => $this->domain,
            'type' => 2,
            'verify_by' => 2,
            'email' => 'user@example.invalid',
        ]);

        $params = [
            'domain' => $this->domain,
            'token' => $token,
            'username' => 'keroro',
            'new_password' => '08064494449',
            'new_password_confirmation' => '08064494449',
        ];

        $response = $this->requestApi(self::API_RESET_PASSWORD, $params);

        $response->assertStatus(200);

        // 驗證密碼是否更新成功
        $member = Member::where('username', 'keroro')->first();
        $this->assertTrue(Hash::check('08064494449', $member->password), 'Password was not updated successfully.');
    }

    /**
     * 工單 4540：重設成功後，請求紀錄與響應紀錄（RequestLogger）都不含新密碼原文，
     * 新密碼、確認密碼與前端多送的驗證碼欄位都是遮罩值。
     */
    public function test_request_log_masks_new_password_and_verify_code(): void
    {
        config(['custom.log_request' => true]);

        $logs = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logs): void {
            $logs[] = $event;
        });

        $token = [REDACTED]
            'domain' => $this->domain,
            'type' => 2,
            'verify_by' => 2,
            'email' => 'user@example.invalid',
        ]);

        $this->requestApi(self::API_RESET_PASSWORD, [
            'domain' => $this->domain,
            'token' => $token,
            'verifyCode' => $token,
            'username' => 'keroro',
            'new_password' => '08064494449',
            'new_password_confirmation' => '08064494449',
        ])->assertStatus(200);

        $requestLogs = array_values(array_filter(
            $logs,
            static fn (MessageLogged $event): bool => str_starts_with($event->message, 'frontend/api/guest/reset-password '),
        ));
        $this->assertCount(2, $requestLogs, 'Expect one request log and one response log.');

        foreach ($requestLogs as $event) {
            $params = $event->context['request']['params'];

            $this->assertStringNotContainsString('08064494449', json_encode($event->context), $event->message);
            $this->assertSame('***', $params['new_password'], $event->message);
            $this->assertSame('***', $params['new_password_confirmation'], $event->message);
            $this->assertSame('***', $params['verifyCode'], $event->message);
        }
    }

    /**
     * 工單 4540：驗證碼前後多帶的空白先去掉再比對，正確碼前後各一個空白仍能重設成功。
     *
     * 全域 TrimStrings 幫不上忙：DecryptRequest 在它之後才把解密的參數放進 request。
     */
    public function test_update_password_accepts_token_with_surrounding_spaces(): void
    {
        $token = [REDACTED]
            'domain' => $this->domain,
            'type' => 2,
            'verify_by' => 2,
            'email' => 'user@example.invalid',
        ]);

        $response = $this->requestEncryptedApi(self::API_RESET_PASSWORD, [
            'domain' => $this->domain,
            'token' => ' ' . $token . ' ',
            'username' => 'keroro',
            'new_password' => '08064494449',
            'new_password_confirmation' => '08064494449',
        ]);

        $response->assertStatus(200);

        $member = Member::where('username', 'keroro')->first();
        $this->assertTrue(Hash::check('08064494449', $member->password), 'Password was not updated successfully.');
    }

    /**
     * 工單 4540：驗證碼必須是 6 位數字，非數字或長度不對在表單驗證就擋下（ValidationException 回 400）。
     */
    #[DataProvider('invalidTokenProvider')]
    public function test_reset_password_rejects_non_digit_token(string $token): void
    {
        $params = [
            'domain' => $this->domain,
            'token' => $token,
            'username' => 'keroro',
            'new_password' => '08064494449',
            'new_password_confirmation' => '08064494449',
        ];

        $response = $this->requestApi(self::API_RESET_PASSWORD, $params);

        $response->assertStatus(400);

        $member = Member::where('username', 'keroro')->first();
        $this->assertTrue(Hash::check('123456', $member->password), 'Password must not be changed.');
    }

    public static function invalidTokenProvider(): array
    {
        return [
            '含英文字母' => ['abc123'],
            '單一數字' => ['0'],
            '五位數' => ['12345'],
            '中間有空白' => ['12 3456'],
        ];
    }

    /**
     * 工單 4540：同一帳號 10 次內放行、第 11 次擋下（帳號 key）。
     *
     * 每次換來源 IP，確保擋下來的是帳號 key 而不是 IP key；驗證碼錯也算次數，限流不看結果。
     */
    public function test_reset_password_is_throttled_per_username(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->resetPasswordWithWrongToken('keroro', "203.0.113.{$i}");

            $this->assertNotSame(429, $response->getStatusCode(), "Request #{$i} should not be throttled.");
        }

        $this->resetPasswordWithWrongToken('keroro', '0.0.0.0')
            ->assertStatus(429)
            ->assertJson(['code' => 2037]);
    }

    /**
     * 工單 4540：同一 IP 換不同帳號，打滿 10 次後第 11 次也擋下（IP key）。
     */
    public function test_reset_password_is_throttled_per_ip(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->resetPasswordWithWrongToken("someone_{$i}", '0.0.0.0');

            $this->assertNotSame(429, $response->getStatusCode(), "Request #{$i} should not be throttled.");
        }

        $this->resetPasswordWithWrongToken('someone_11', '0.0.0.0')
            ->assertStatus(429)
            ->assertJson(['code' => 2037]);
    }

    /**
     * 工單 4540：429 訊息要跟著這次請求的語系。
     *
     * 前 10 次用預設的 zh-TW、第 11 次改帶 th-th：同一個測試的 app 會留著上一次請求設定的語系，
     * 第 11 次也用 zh-TW 的話，就算限流跑在 DetectLanguage 之前也會碰巧是對的語系。
     */
    public function test_throttled_message_follows_request_language(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->resetPasswordWithWrongToken('keroro', "203.0.113.{$i}");
        }

        $this->addHeader('Accept-Language', 'th-th');

        $this->resetPasswordWithWrongToken('keroro', '0.0.0.0')
            ->assertStatus(429)
            ->assertJson([
                'code' => 2037,
                'message' => __('error_message.2037', ['values' => 10], 'th'),
            ]);
    }

    /**
     * 工單 4540：取得帳號列表不看 username，多帶 username 換不到新額度。
     *
     * CheckCodeRequest 不驗 username，validated() 會丟掉它；限流若讀它，攻擊者固定聯絡方式、
     * 每次附不同 username 就能換 bucket。每次也換 IP，確保擋下來的是聯絡方式的 key。
     */
    public function test_member_accounts_ignores_username(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->memberAccountsWithWrongToken(
                ['email' => 'user@example.invalid', 'username' => "someone_{$i}"],
                "203.0.113.{$i}",
            );

            $this->assertNotSame(429, $response->getStatusCode(), "Request #{$i} should not be throttled.");
        }

        $this->memberAccountsWithWrongToken(['email' => 'user@example.invalid', 'username' => 'someone_11'], '0.0.0.0')
            ->assertStatus(429)
            ->assertJson(['code' => 2037]);
    }

    /**
     * 工單 4540：固定信箱、每次換手機（也換 IP），第 11 次擋下。
     *
     * 查驗證碼用的是「手機或信箱」任一相符，所以兩個聯絡方式要各自計數，不能只看其中一個；
     * 最後換一組全新的聯絡方式與 IP 仍放行，證明不是全站共用一把。
     */
    public function test_member_accounts_throttles_email_while_mobile_rotates(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->memberAccountsWithWrongToken(
                ['email' => 'user@example.invalid', 'mobile' => sprintf('09120000%02d', $i)],
                "203.0.113.{$i}",
            );

            $this->assertNotSame(429, $response->getStatusCode(), "Request #{$i} should not be throttled.");
        }

        $this->memberAccountsWithWrongToken(['email' => 'user@example.invalid', 'mobile' => '0912000011'], '0.0.0.0')
            ->assertStatus(429)
            ->assertJson(['code' => 2037]);

        $response = $this->memberAccountsWithWrongToken(['email' => 'user@example.invalid', 'mobile' => '0987654321'], '0.0.0.0');
        $this->assertNotSame(429, $response->getStatusCode(), 'Another contact from another IP should not be throttled.');
    }

    /**
     * 工單 4540：固定手機、每次換信箱（也換 IP），第 11 次擋下。
     */
    public function test_member_accounts_throttles_mobile_while_email_rotates(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->memberAccountsWithWrongToken(
                ['email' => "rotate{$i}@example.invalid", 'mobile' => '0912345678'],
                "203.0.113.{$i}",
            );

            $this->assertNotSame(429, $response->getStatusCode(), "Request #{$i} should not be throttled.");
        }

        $this->memberAccountsWithWrongToken(['email' => 'user@example.invalid', 'mobile' => '0912345678'], '0.0.0.0')
            ->assertStatus(429)
            ->assertJson(['code' => 2037]);
    }

    /**
     * 工單 4540：次數上限設成 0 等於關閉限流，誤擋時改 env 就能止血、不必回滾。
     */
    public function test_throttle_can_be_disabled_by_config(): void
    {
        config(['custom.guest_password_reset_throttle.max_attempts' => 0]);

        for ($i = 1; $i <= 11; $i++) {
            $response = $this->resetPasswordWithWrongToken('keroro', '0.0.0.0');

            $this->assertNotSame(429, $response->getStatusCode(), "Request #{$i} should not be throttled.");
        }
    }

    /**
     * 工單 4540：被擋時記一筆 warning，帶限流器與 key 的種類、來源 IP，不記帳號與聯絡方式的值。
     */
    public function test_throttled_request_is_logged_without_identity_value(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->resetPasswordWithWrongToken('keroro', "203.0.113.{$i}");
        }

        $log = Log::spy();

        $this->resetPasswordWithWrongToken('keroro', '0.0.0.0')->assertStatus(429);

        $log->shouldHaveReceived('warning')
            ->with('guest password reset throttled', Mockery::on(static fn (array $context): bool => $context === [
                'limiter' => 'guest-reset-password',
                'key_type' => 'user',
                'ip' => '0.0.0.0',
            ]))
            ->once();
    }

    /**
     * 走真正的 DecryptRequest 送加密參數，重現正式環境：解密後的參數是在全域 TrimStrings 之後才放進
     * request，前後空白不會被修掉。setUp 為了方便把 DecryptRequest 換成直接放行，這裡把它換回來。
     */
    private function requestEncryptedApi(string $uri, array $params): TestResponse
    {
        $this->app->forgetInstance(DecryptRequest::class);

        return $this->requestApi($uri, $this->encryptParams($params));
    }

    private function memberAccountsWithWrongToken(array $contact, string $ip): TestResponse
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);

        return $this->requestApi(self::API_MEMBER_ACCOUNTS, [
            'domain' => $this->domain,
            'token' => '000000',
            ...$contact,
        ]);
    }

    private function resetPasswordWithWrongToken(string $username, string $ip): TestResponse
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);

        return $this->requestApi(self::API_RESET_PASSWORD, [
            'domain' => $this->domain,
            'token' => '000000',
            'username' => $username,
            'new_password' => '08064494449',
            'new_password_confirmation' => '08064494449',
        ]);
    }
}
