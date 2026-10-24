<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ValidationCode;

use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\EmailTextTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\EmailGatewayGroupDetail;
use App\Models\EmailText;
use App\Models\Language;
use App\Models\Organization;
use App\Models\SmsGatewayGroupDetail;
use App\Models\ValidateToken;
use App\Services\EmailGatewayService;
use App\Services\EmailTextService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 訪客忘記密碼（type=2, verify_by=2）寄驗證碼信：主旨內文讀電子郵件設置組別樣板、發送上限依通道分流 工單4286
 *
 * php artisan test tests/Feature/Frontend/ValidationCode/EmailTemplateSendTest.php
 */
class EmailTemplateSendTest extends TestCase
{
    private const string API_SEND_CODE = 'frontend/api/guest/send-validation-code';

    private const string MEMBER_EMAIL = 'user@example.invalid';

    private Organization $organization;

    private AgentGroup $emailGroup;

    private Agent $agent;

    private Language $language;

    /** 每次 EmailGatewayService::send() 收到的參數（依宣告順序：agentGroupId, receiver, subject, content, agentId, memberId） */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(DecryptRequest::class);

        $this->organization = Organization::create(['name' => 'org-' . Str::random(4), 'currency_id' => 1, 'memo' => '']);
        $this->emailGroup = AgentGroup::create([
            'agent_group_type' => AgentGroupTypeEnum::EMAIL_GATEWAY->value,
            'organization_id' => $this->organization->id,
            'name' => 'email-group-' . Str::random(6),
            'memo' => '',
            'is_default' => 0,
        ]);
        $this->agent = $this->createAgentAndDomain([
            'organization_id' => $this->organization->id,
            'email_gateway_group_id' => $this->emailGroup->id,
        ]);
        $this->createMember($this->agent, [
            'username' => 'keroro' . Str::lower(Str::random(4)),
            'email' => self::MEMBER_EMAIL,
            'email_validate' => 1,
            'password' => Hash::make('123456'),
        ]);

        // DetectLanguage 以 languages.code 對 Accept-Language（TestCase 預設 zh-TW），並快取 active_languages
        $this->language = Language::create(['name' => '繁體中文', 'code' => 'zh-TW', 'sort' => 1, 'status' => 1]);
        cache()->forget('active_languages');

        $this->mock(EmailGatewayService::class, function (MockInterface $mock) {
            // 不限次數（Mockery 預設）；各測試自行斷言 $this->sent 的筆數
            $mock->shouldReceive('send')
                ->withArgs(function (...$args): bool {
                    $this->sent[] = $args;

                    return true;
                });
        });
    }

    protected function tearDown(): void
    {
        cache()->forget('active_languages');

        parent::tearDown();
    }

    private function sendCode(): TestResponse
    {
        return $this->requestApi(self::API_SEND_CODE, [
            'domain' => $this->domain,
            'type' => 2,
            'verify_by' => 2,
            'email' => self::MEMBER_EMAIL,
        ]);
    }

    /**
     * 有該語系樣板時，主旨與內文取該語系並替換 {code} {email}
     */
    public function test_sends_language_specific_template_with_placeholders_replaced(): void
    {
        // Arrange
        app(EmailTextService::class)->ensureDefaults($this->emailGroup->id);
        EmailText::create([
            'email_gateway_group_id' => $this->emailGroup->id,
            'type_id' => EmailTextTypeEnum::FORGET->value,
            'language_id' => $this->language->id,
            'subject' => '忘記密碼驗證碼',
            'content' => '您的驗證碼為【{code}】({email}) {nickname}',
        ]);

        // Act
        $this->sendCode()->assertStatus(200);

        // Assert
        $token = [REDACTED]'id')->value('token');
        $this->assertCount(1, $this->sent);
        [$groupId, $receiver, $subject, $content] = $this->sent[0];
        $this->assertSame($this->emailGroup->id, $groupId);
        $this->assertSame(self::MEMBER_EMAIL, $receiver);
        $this->assertSame('忘記密碼驗證碼', $subject);
        $this->assertSame('您的驗證碼為【' . $token . '】(' . self::MEMBER_EMAIL . ') {nickname}', $content);
    }

    /**
     * 沒有該語系樣板時退回全語系列；組別從未初始化會先自動補預設列，內容與改動前寫死文字一致
     */
    public function test_falls_back_to_all_language_default_and_seeds_uninitialized_group(): void
    {
        $this->assertSame(0, EmailText::where('email_gateway_group_id', $this->emailGroup->id)->count());

        $this->sendCode()->assertStatus(200);

        $token = [REDACTED]'id')->value('token');
        $this->assertSame(8, EmailText::where('email_gateway_group_id', $this->emailGroup->id)->count());
        [, , $subject, $content] = $this->sent[0];
        $this->assertSame('Verification Code', $subject);
        $this->assertSame('The PIN for ' . self::MEMBER_EMAIL . ' is: 【' . $token . '】', $content);
    }

    /**
     * 代理未綁電子郵件設置組別：不查樣板、用內建預設文字，仍寄得出去
     */
    public function test_agent_without_email_group_uses_builtin_default(): void
    {
        // Arrange：另一個沒綁組別的代理與會員（agent_domains.domain 唯一，先換網域）
        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $agent = $this->createAgentAndDomain(['organization_id' => $this->organization->id, 'email_gateway_group_id' => null]);
        $email = 'user@example.invalid';
        $this->createMember($agent, [
            'username' => 'nogroup' . Str::lower(Str::random(4)),
            'email' => $email,
            'email_validate' => 1,
            'password' => Hash::make('123456'),
        ]);

        // Act
        $this->requestApi(self::API_SEND_CODE, ['domain' => $this->domain, 'type' => 2, 'verify_by' => 2, 'email' => $email])
            ->assertStatus(200);

        // Assert
        $token = [REDACTED]'id')->value('token');
        [$groupId, , $subject, $content] = $this->sent[0];
        $this->assertSame(0, $groupId);
        $this->assertSame('Verification Code', $subject);
        $this->assertSame('The PIN for ' . $email . ' is: 【' . $token . '】', $content);
        $this->assertSame(0, EmailText::count() - EmailText::where('email_gateway_group_id', '!=', 0)->count());
    }

    /**
     * 組別已初始化但該類型的全語系列被刪掉：中斷寄送並回泛化訊息（error_message.2003），不寄信
     */
    public function test_missing_template_aborts_with_generic_error(): void
    {
        app(EmailTextService::class)->ensureDefaults($this->emailGroup->id);
        EmailText::where('email_gateway_group_id', $this->emailGroup->id)
            ->where('type_id', EmailTextTypeEnum::FORGET->value)
            ->delete();

        $response = $this->sendCode();

        $response->assertStatus(400)->assertJsonPath('message', __('error_message.2003'));
        $this->assertCount(0, $this->sent);
    }

    /**
     * 發送上限依通道分流：簡訊組別設 9、郵件組別設 2 → email 第 3 次被擋，不吃簡訊的 9
     */
    public function test_email_max_send_reads_email_group_not_sms_group(): void
    {
        // Arrange
        $smsGroup = AgentGroup::create([
            'agent_group_type' => AgentGroupTypeEnum::SMS_GATEWAY->value,
            'organization_id' => $this->organization->id,
            'name' => 'sms-group-' . Str::random(6),
            'memo' => '',
            'is_default' => 0,
        ]);
        SmsGatewayGroupDetail::create(['agent_group_id' => $smsGroup->id, 'setting' => ['token_expired' => '6', 'max_send' => '9']]);
        EmailGatewayGroupDetail::create(['agent_group_id' => $this->emailGroup->id, 'setting' => ['token_expired' => '6', 'max_send' => '2']]);

        // Act
        $this->sendCode()->assertStatus(200);
        $this->sendCode()->assertStatus(200);
        $third = $this->sendCode();

        // Assert
        $this->assertNotSame(200, $third->json('code'));
        $this->assertSame(__('error_message.2037', ['values' => 2]), $third->json('message'));
        $this->assertCount(2, $this->sent);
        // 同一個 token 累計 times，而不是每次重建
        $this->assertSame(1, ValidateToken:[REDACTED]'info', self::MEMBER_EMAIL)->where('status', 0)->count());
    }
}
