<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ValidationCode;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AgentDomain;
use App\Models\Member;
use App\Models\Organization;
use App\Models\ValidateToken;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 工單 4540：檢核驗證碼（guest/check-validation-code）不能拿來猜忘記密碼的驗證碼。
 *
 * 這支原本沒有限流，而且對 type 2（忘記密碼）的碼：猜錯回 9992、猜對回另一段訊息，
 * 等於一個沒有次數限制的猜碼判定器，猜中後拿去重設密碼一次就成功，繞過重設密碼那邊的限流。
 *
 * php artisan test tests/Feature/Frontend/ValidationCode/GuestCheckValidationCodeGuardTest.php
 */
class GuestCheckValidationCodeGuardTest extends TestCase
{
    private const string API_CHECK_CODE = 'frontend/api/guest/check-validation-code';

    private const string MOBILE = '0912345678';

    private const string CORRECT_TOKEN = [REDACTED]';

    private const string WRONG_TOKEN = [REDACTED]';

    /** type 3（找回密碼）、type 1（資料驗證）的碼，用在網域不符的案例 */
    private const string OTHER_TYPE_TOKEN = [REDACTED]';

    protected Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(DecryptRequest::class);

        $organization = Organization::create([
            'name' => 'guard org',
            'currency_id' => 1,
            'memo' => '',
        ]);

        $this->agent = $this->createAgentAndDomain([
            'organization_id' => $organization->id,
        ]);

        $this->member = $this->createMember($this->agent, [
            'username' => 'guard' . substr(bin2hex(random_bytes(3)), 0, 5),
            'mobile' => self::MOBILE,
            'mobile_validate' => 1,
        ]);

        ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'type_id' => 21, // type=2（FORGET_PASSWORD）、verify_by=1
            'info' => self::MOBILE,
            'info_2' => '',
            'token' => self::CORRECT_TOKEN,
            'status' => 0,
            'expire_date' => now()->addHour()->toDateTimeString(),
            'admin' => $this->member->id,
        ]);
    }

    /**
     * 對 type 2 的碼，猜錯與猜對的回應必須完全相同（tid 每次不同，除外）。
     *
     * 連 ApiException 回應裡的 file（建立例外的位置）也要一樣，否則看行號就知道猜對了。
     */
    public function test_type2_code_response_does_not_reveal_whether_guess_is_correct(): void
    {
        $wrong = $this->checkCode(['mobile' => self::MOBILE, 'token' => self::WRONG_TOKEN], '0.0.0.0');
        $correct = $this->checkCode(['mobile' => self::MOBILE, 'token' => self::CORRECT_TOKEN], '0.0.0.0');

        $wrong->assertJson(['message' => __('error_message.9992')]);

        $this->assertSame($wrong->getStatusCode(), $correct->getStatusCode());
        $this->assertSame($this->withoutTid($wrong), $this->withoutTid($correct));
    }

    /**
     * type 2 的碼帶另一個代理的合法網域：回應仍須與猜錯完全相同。
     *
     * 碼查到之後原本會先驗網域（domain not match agent）才判斷類型，猜對的碼會因為網域不符
     * 而回不同訊息，等於換個網域又能當判定器用。
     */
    public function test_type2_code_with_other_agents_domain_does_not_reveal_guess(): void
    {
        $otherDomain = $this->createOtherAgentDomain();

        $wrong = $this->checkCode(['domain' => $otherDomain, 'mobile' => self::MOBILE, 'token' => self::WRONG_TOKEN], '0.0.0.0');
        $correct = $this->checkCode(['domain' => $otherDomain, 'mobile' => self::MOBILE, 'token' => self::CORRECT_TOKEN], '0.0.0.0');

        $this->assertSame($wrong->getStatusCode(), $correct->getStatusCode());
        $this->assertSame($this->withoutTid($wrong), $this->withoutTid($correct));
    }

    /**
     * type 2 的碼帶不存在的網域：回應仍須與猜錯完全相同（目前在 HasDomain 就被擋，留作回歸保護）。
     */
    public function test_type2_code_with_unknown_domain_does_not_reveal_guess(): void
    {
        $wrong = $this->checkCode(['domain' => 'no-such-domain.test', 'mobile' => self::MOBILE, 'token' => self::WRONG_TOKEN], '0.0.0.0');
        $correct = $this->checkCode(['domain' => 'no-such-domain.test', 'mobile' => self::MOBILE, 'token' => self::CORRECT_TOKEN], '0.0.0.0');

        $this->assertSame($wrong->getStatusCode(), $correct->getStatusCode());
        $this->assertSame($this->withoutTid($wrong), $this->withoutTid($correct));
    }

    /**
     * type 3（找回密碼）的碼網域不符：回應須與猜錯完全相同。
     */
    public function test_type3_code_with_domain_mismatch_does_not_reveal_guess(): void
    {
        $this->createOtherTypeToken(31); // type=3（RECOVER_PASSWORD）、verify_by=1
        $otherDomain = $this->createOtherAgentDomain();

        $wrong = $this->checkCode(['domain' => $otherDomain, 'mobile' => self::MOBILE, 'token' => self::WRONG_TOKEN], '0.0.0.0');
        $correct = $this->checkCode(['domain' => $otherDomain, 'mobile' => self::MOBILE, 'token' => self::OTHER_TYPE_TOKEN], '0.0.0.0');

        $this->assertSame($wrong->getStatusCode(), $correct->getStatusCode());
        $this->assertSame($this->withoutTid($wrong), $this->withoutTid($correct));
    }

    /**
     * 回歸：其他類型（type 1 資料驗證）的碼網域不符，仍回原本的訊息，不受忘記密碼的收斂影響。
     */
    public function test_type1_code_with_domain_mismatch_keeps_original_message(): void
    {
        $this->createOtherTypeToken(11); // type=1（資料驗證）、verify_by=1
        $otherDomain = $this->createOtherAgentDomain();

        $this->checkCode(['domain' => $otherDomain, 'mobile' => self::MOBILE, 'token' => self::OTHER_TYPE_TOKEN], '0.0.0.0')
            ->assertJson(['message' => 'domain not match agent']);
    }

    /**
     * 工單 4540：檢核驗證碼也先去掉前後空白，前後各帶一個空白的正確碼與不帶空白一樣通過。
     */
    public function test_correct_code_with_surrounding_spaces_passes(): void
    {
        $this->createOtherTypeToken(111); // type=11（單純驗證）、verify_by=1

        $this->requestEncryptedApi(self::API_CHECK_CODE, [
            'domain' => $this->domain,
            'mobile' => self::MOBILE,
            'token' => ' ' . self::OTHER_TYPE_TOKEN . ' ',
        ])
            ->assertStatus(200)
            ->assertJson(['data' => [$this->member->username]]);
    }

    /**
     * 同一支手機第 11 次擋下；每次換 IP，確保擋下來的是手機的 key。
     */
    public function test_check_code_is_throttled_per_mobile(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->checkCode(['mobile' => self::MOBILE, 'token' => self::WRONG_TOKEN], "203.0.113.{$i}");

            $this->assertNotSame(429, $response->getStatusCode(), "Request #{$i} should not be throttled.");
        }

        $this->checkCode(['mobile' => self::MOBILE, 'token' => self::WRONG_TOKEN], '0.0.0.0')
            ->assertStatus(429)
            ->assertJson(['code' => 2037]);
    }

    /**
     * 固定手機、每次多帶不同信箱（也換 IP）仍擋下：信箱換新不會讓手機的額度重來。
     */
    public function test_check_code_throttles_mobile_while_email_rotates(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->checkCode(
                ['mobile' => self::MOBILE, 'email' => "rotate{$i}@example.invalid", 'token' => self::WRONG_TOKEN],
                "203.0.113.{$i}",
            );

            $this->assertNotSame(429, $response->getStatusCode(), "Request #{$i} should not be throttled.");
        }

        $this->checkCode(['mobile' => self::MOBILE, 'email' => 'user@example.invalid', 'token' => self::WRONG_TOKEN], '0.0.0.0')
            ->assertStatus(429)
            ->assertJson(['code' => 2037]);
    }

    private function createOtherTypeToken(int $typeId): void
    {
        ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'type_id' => $typeId,
            'info' => self::MOBILE,
            'info_2' => '',
            'token' => self::OTHER_TYPE_TOKEN,
            'status' => 0,
            'expire_date' => now()->addHour()->toDateTimeString(),
            'admin' => $this->member->id,
        ]);
    }

    /**
     * 另一個代理（不同組織、不在會員的代理線上）的合法網域。
     */
    private function createOtherAgentDomain(): string
    {
        $organization = Organization::create([
            'name' => 'other org',
            'currency_id' => 1,
            'memo' => '',
        ]);

        $unique = bin2hex(random_bytes(6));
        $otherAgent = Agent::factory()->create([
            'code' => substr($unique, 0, 10),
            'username' => 'o' . $unique,
            'organization_id' => $organization->id,
            'status' => 1,
        ]);
        $otherAgent->hierarchy_path = "/{$otherAgent->id}";
        $otherAgent->hierarchy = "[-1,{$otherAgent->id},-2]";
        $otherAgent->save();

        $domain = 'other-' . $unique . '.test.local';
        AgentDomain::create([
            'agent_id' => $otherAgent->id,
            'domain' => $domain,
            'admin' => 1,
        ]);

        return $domain;
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

    private function checkCode(array $params, string $ip): TestResponse
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);

        return $this->requestApi(self::API_CHECK_CODE, [
            'domain' => $this->domain,
            ...$params,
        ]);
    }

    private function withoutTid(TestResponse $response): array
    {
        $json = $response->json();
        unset($json['tid']);

        return $json;
    }
}
