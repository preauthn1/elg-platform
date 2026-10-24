<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\EmailTextTypeEnum;
use App\Enums\ValidationCodeTypeEnum;
use App\Models\AgentGroup;
use App\Models\EmailGatewayGroupDetail;
use App\Models\EmailText;
use App\Services\EmailTextService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 驗證碼郵件樣板服務 工單4286
 *
 * php artisan test tests/Feature/Services/EmailTextServiceTest.php
 */
class EmailTextServiceTest extends TestCase
{
    private EmailTextService $service;

    private AgentGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(EmailTextService::class);
        $this->group = AgentGroup::create([
            'agent_group_type' => AgentGroupTypeEnum::EMAIL_GATEWAY->value,
            'organization_id' => 1,
            'name' => 'email-group-' . Str::random(6),
            'memo' => '',
            'is_default' => 0,
        ]);
    }

    /**
     * 沒綁組別（group 0）不查表，直接回內建預設
     */
    public function test_resolve_returns_builtin_default_when_no_group(): void
    {
        $result = $this->service->resolve(0, EmailTextTypeEnum::FORGET, 1);

        $this->assertSame(EmailTextService::DEFAULT_SUBJECT, $result['subject']);
        $this->assertSame(EmailTextService::DEFAULT_CONTENT, $result['content']);
        $this->assertSame(0, EmailText::count() - EmailText::where('email_gateway_group_id', '!=', 0)->count());
    }

    /**
     * 組別從未初始化時先補 8 列預設，再回全語系預設列
     */
    public function test_resolve_seeds_defaults_for_uninitialized_group(): void
    {
        $result = $this->service->resolve($this->group->id, EmailTextTypeEnum::MEMBER_REGISTER, 1);

        $this->assertSame(8, EmailText::where('email_gateway_group_id', $this->group->id)->count());
        $this->assertSame(EmailTextService::DEFAULT_SUBJECT, $result['subject']);
        $this->assertSame(EmailTextService::DEFAULT_CONTENT, $result['content']);
    }

    /**
     * 有該語系列取該語系，沒有退回全語系
     */
    public function test_resolve_prefers_specific_language_then_falls_back_to_all(): void
    {
        $this->service->ensureDefaults($this->group->id);
        EmailText::create([
            'email_gateway_group_id' => $this->group->id,
            'type_id' => EmailTextTypeEnum::FORGET->value,
            'language_id' => 7,
            'subject' => 'S7',
            'content' => 'C7 {code}',
        ]);

        $this->assertSame('S7', $this->service->resolve($this->group->id, EmailTextTypeEnum::FORGET, 7)['subject']);
        $this->assertSame(
            EmailTextService::DEFAULT_SUBJECT,
            $this->service->resolve($this->group->id, EmailTextTypeEnum::FORGET, 8)['subject'],
        );
    }

    /**
     * 組別已初始化但該類型連全語系列都被刪掉 → 回 null（呼叫端拋例外中斷），不會自動補列
     */
    public function test_resolve_returns_null_when_type_has_no_rows(): void
    {
        $this->service->ensureDefaults($this->group->id);
        EmailText::where('email_gateway_group_id', $this->group->id)
            ->where('type_id', EmailTextTypeEnum::RETRIEVE->value)
            ->delete();

        $this->assertNull($this->service->resolve($this->group->id, EmailTextTypeEnum::RETRIEVE, 1));
        $this->assertSame(7, EmailText::where('email_gateway_group_id', $this->group->id)->count());
    }

    /**
     * ensureDefaults 重複呼叫不會重複插入
     */
    public function test_ensure_defaults_is_idempotent(): void
    {
        $this->service->ensureDefaults($this->group->id);
        $this->service->ensureDefaults($this->group->id);

        $this->assertSame(8, EmailText::where('email_gateway_group_id', $this->group->id)->count());
    }

    /**
     * 只替換 {code} {email}；其他 {xxx} 與 HTML 標籤原樣保留
     */
    public function test_render_replaces_only_supported_placeholders(): void
    {
        $out = $this->service->render('<b>test</b> {nickname} {code} ({email})', '123456', 'a@b.c');

        $this->assertSame('<b>test</b> {nickname} 123456 (a@b.c)', $out);
    }

    /**
     * 驗證類型 → 樣板類型：1–8 同值，9/10/11 → 資料驗證，12 → 會員註冊
     */
    public function test_from_validation_type_mapping(): void
    {
        $this->assertSame(EmailTextTypeEnum::FORGET, EmailTextTypeEnum::fromValidationType(ValidationCodeTypeEnum::FORGET_PASSWORD->value));
        $this->assertSame(EmailTextTypeEnum::MEMBER_REGISTER, EmailTextTypeEnum::fromValidationType(ValidationCodeTypeEnum::REGISTER->value));
        $this->assertSame(EmailTextTypeEnum::MEMBER_REGISTER, EmailTextTypeEnum::fromValidationType(ValidationCodeTypeEnum::REGISTER_V2->value));
        $this->assertSame(EmailTextTypeEnum::VERIFICATION, EmailTextTypeEnum::fromValidationType(ValidationCodeTypeEnum::REBINDING_DATA->value));
        $this->assertSame(EmailTextTypeEnum::VERIFICATION, EmailTextTypeEnum::fromValidationType(ValidationCodeTypeEnum::LOGIN_PHONE_EMAIL->value));
        $this->assertSame(EmailTextTypeEnum::VERIFICATION, EmailTextTypeEnum::fromValidationType(ValidationCodeTypeEnum::VERIFY_CODE->value));
        $this->assertSame(EmailTextTypeEnum::VERIFICATION, EmailTextTypeEnum::fromValidationType(999));
    }

    /**
     * 群組設定：沒設定吃 config('custom.mail.*')；有設定取設定值；不回退簡訊組別
     */
    public function test_get_token_expired_and_max_send(): void
    {
        config(['custom.mail.token_expired' => 6, 'custom.mail.max_send' => 3]);

        $this->assertSame([6, 3], $this->service->getTokenExpiredAndMaxSend(null));
        $this->assertSame([6, 3], $this->service->getTokenExpiredAndMaxSend(0));
        $this->assertSame([6, 3], $this->service->getTokenExpiredAndMaxSend($this->group->id));

        EmailGatewayGroupDetail::create([
            'agent_group_id' => $this->group->id,
            'setting' => ['token_expired' => '10', 'max_send' => '2'],
        ]);

        $this->assertSame([10, 2], $this->service->getTokenExpiredAndMaxSend($this->group->id));
    }
}
