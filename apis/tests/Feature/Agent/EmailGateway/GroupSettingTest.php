<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\EmailGateway;

use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\EmailTextTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentGroup;
use App\Models\EmailGatewayGroupDetail;
use App\Models\EmailText;
use App\Models\Language;
use App\Services\EmailTextService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 電子郵件設置組別 › 高級設置（群組設定＋郵件樣板）工單4286
 *
 * php artisan test tests/Feature/Agent/EmailGateway/GroupSettingTest.php
 */
class GroupSettingTest extends TestCase
{
    private Agent $agent;

    private AgentGroup $group;

    private Language $language;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->group = $this->createEmailGroup();
        $this->agent = $this->createAgentAndDomain([
            'organization_id' => 1,
            'email_gateway_group_id' => $this->group->id,
        ]);
        $this->language = Language::create([
            'name' => 'Test Lang ' . Str::random(4),
            'code' => 'tl-' . Str::lower(Str::random(4)),
            'sort' => 99,
            'status' => 1,
        ]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function act(string $action, array $payload = []): TestResponse
    {
        return $this->postJson('agent/api/email-gateway/group-setting/' . $action, $payload, $this->headers);
    }

    private function createEmailGroup(array $overrides = []): AgentGroup
    {
        return AgentGroup::create(array_merge([
            'agent_group_type' => AgentGroupTypeEnum::EMAIL_GATEWAY->value,
            'organization_id' => 1,
            'name' => 'email-group-' . Str::random(6),
            'memo' => '',
            'is_default' => 0,
        ], $overrides));
    }

    /**
     * 8 個類型的全語系列（text/save 的最小合法 payload）
     */
    private function defaultRows(): array
    {
        return array_map(fn (EmailTextTypeEnum $type): array => [
            'type_id' => $type->value,
            'language_id' => EmailTextService::ALL_LANGUAGE_ID,
            'subject' => EmailTextService::DEFAULT_SUBJECT,
            'content' => EmailTextService::DEFAULT_CONTENT,
        ], EmailTextTypeEnum::cases());
    }

    // -------------------------------------------------------
    // view
    // -------------------------------------------------------

    /**
     * 首次載入應補齊 8 個類型的全語系預設列，並回傳啟用中的語系清單
     */
    public function test_view_seeds_default_texts_and_returns_active_languages(): void
    {
        // Arrange
        $disabled = Language::create(['name' => 'Disabled', 'code' => 'xx-' . Str::lower(Str::random(3)), 'sort' => 99, 'status' => 0]);
        $this->assertSame(0, EmailText::where('email_gateway_group_id', $this->group->id)->count());

        // Act
        $response = $this->act('view', ['group_id' => $this->group->id]);

        // Assert
        $response->assertStatus(200)->assertJsonPath('code', 200);
        $data = $response->json('data');

        $this->assertSame(8, EmailText::where('email_gateway_group_id', $this->group->id)->count());
        $this->assertCount(8, $data['email_text']);
        $this->assertSame([0], array_values(array_unique(array_column($data['email_text'], 'language_id'))));
        $this->assertSame('member_register', collect($data['email_text'])->firstWhere('type_id', 8)['parameter']);
        $this->assertNull($data['group_detail']);
        $this->assertSame(['{code}', '{email}'], $data['placeholders']);

        $languageIds = array_column($data['languages'], 'id');
        $this->assertContains($this->language->id, $languageIds);
        $this->assertNotContains($disabled->id, $languageIds);
    }

    // -------------------------------------------------------
    // detail/save
    // -------------------------------------------------------

    /**
     * 兩個值都只接受正整數
     */
    public function test_save_detail_rejects_non_positive_integer(): void
    {
        // 本專案的驗證失敗統一回 400（bootstrap/app.php render）
        $this->act('detail/save', ['group_id' => $this->group->id, 'token_expired' => -1, 'max_send' => 5])
            ->assertStatus(400)->assertJsonPath('code', 400);

        $this->act('detail/save', ['group_id' => $this->group->id, 'token_expired' => 10, 'max_send' => 'abc'])
            ->assertStatus(400)->assertJsonPath('code', 400);

        $this->act('detail/save', ['group_id' => $this->group->id, 'token_expired' => 0, 'max_send' => 5])
            ->assertStatus(400)->assertJsonPath('code', 400);

        $this->assertNull(EmailGatewayGroupDetail::where('agent_group_id', $this->group->id)->first());
    }

    /**
     * 儲存後 setting 形狀與簡訊組別一致（值為字串），再次儲存為更新而非新增
     */
    public function test_save_detail_persists_setting_and_updates_in_place(): void
    {
        // Act
        $this->act('detail/save', ['group_id' => $this->group->id, 'token_expired' => 10, 'max_send' => 5])
            ->assertStatus(200)->assertJsonPath('code', 200);
        $this->act('detail/save', ['group_id' => $this->group->id, 'token_expired' => 12, 'max_send' => 5])
            ->assertStatus(200)->assertJsonPath('code', 200);

        // Assert
        $details = EmailGatewayGroupDetail::where('agent_group_id', $this->group->id)->get();
        $this->assertCount(1, $details);
        $this->assertSame(
            ['token_expired' => '12', 'max_send' => '5'],
            json_decode($details->first()->getRawOriginal('setting'), true),
        );
        $this->assertSame($this->agent->id, $details->first()->admin);

        $response = $this->act('view', ['group_id' => $this->group->id]);
        $this->assertSame('12', $response->json('data.group_detail.setting.token_expired'));
        $this->assertSame('5', $response->json('data.group_detail.setting.max_send'));
    }

    // -------------------------------------------------------
    // text/save
    // -------------------------------------------------------

    /**
     * 新增特定語系列後再送不含該列的清單，該列應被移除；全語系列始終保留
     */
    public function test_save_text_adds_then_removes_language_row(): void
    {
        // Arrange
        $rows = $this->defaultRows();
        $rows[] = [
            'type_id' => EmailTextTypeEnum::MEMBER_REGISTER->value,
            'language_id' => $this->language->id,
            'subject' => '會員註冊驗證碼',
            'content' => '您的驗證碼為【{code}】',
        ];

        // Act: 新增
        $this->act('text/save', ['group_id' => $this->group->id, 'email_text' => $rows])
            ->assertStatus(200)->assertJsonPath('code', 200);

        // Assert
        $this->assertSame(9, EmailText::where('email_gateway_group_id', $this->group->id)->count());
        $added = EmailText::where('email_gateway_group_id', $this->group->id)
            ->where('type_id', EmailTextTypeEnum::MEMBER_REGISTER->value)
            ->where('language_id', $this->language->id)
            ->first();
        $this->assertNotNull($added);
        $this->assertSame('會員註冊驗證碼', $added->subject);
        $this->assertSame($this->agent->id, $added->admin);

        // Act: 移除（清單不再含該語系列）
        $this->act('text/save', ['group_id' => $this->group->id, 'email_text' => $this->defaultRows()])
            ->assertStatus(200)->assertJsonPath('code', 200);

        // Assert
        $this->assertSame(8, EmailText::where('email_gateway_group_id', $this->group->id)->count());
        $this->assertSame(
            8,
            EmailText::where('email_gateway_group_id', $this->group->id)
                ->where('language_id', EmailTextService::ALL_LANGUAGE_ID)
                ->count(),
        );
    }

    /**
     * 8 個類型每個都必須含全語系列，缺任何一個即拒絕且不落庫
     */
    public function test_save_text_requires_default_row_for_every_type(): void
    {
        $rows = array_values(array_filter(
            $this->defaultRows(),
            fn (array $row): bool => $row['type_id'] !== EmailTextTypeEnum::RETRIEVE->value,
        ));

        $response = $this->act('text/save', ['group_id' => $this->group->id, 'email_text' => $rows]);

        $this->assertNotSame(200, $response->json('code'));
        $this->assertSame(__('error_message.email_gateway.default_language_required'), $response->json('message'));
        $this->assertSame(0, EmailText::where('email_gateway_group_id', $this->group->id)->count());
    }

    /**
     * 停用或不存在的語系不可用
     */
    public function test_save_text_rejects_inactive_language(): void
    {
        $disabled = Language::create(['name' => 'Disabled', 'code' => 'xx-' . Str::lower(Str::random(3)), 'sort' => 99, 'status' => 0]);
        $rows = $this->defaultRows();
        $rows[] = [
            'type_id' => EmailTextTypeEnum::FORGET->value,
            'language_id' => $disabled->id,
            'subject' => 'x',
            'content' => 'y',
        ];

        $response = $this->act('text/save', ['group_id' => $this->group->id, 'email_text' => $rows]);

        $this->assertNotSame(200, $response->json('code'));
        $this->assertSame(__('error_message.email_gateway.language_invalid'), $response->json('message'));
    }

    /**
     * 同一類型同一語系重複給兩筆應拒絕
     */
    public function test_save_text_rejects_duplicate_rows(): void
    {
        $rows = $this->defaultRows();
        $rows[] = $rows[0];

        $response = $this->act('text/save', ['group_id' => $this->group->id, 'email_text' => $rows]);

        $this->assertNotSame(200, $response->json('code'));
        $this->assertSame(__('error_message.email_gateway.duplicate_text'), $response->json('message'));
    }

    /**
     * 類型值超出 1–8 由 Request 擋下
     */
    public function test_save_text_rejects_unknown_type(): void
    {
        $rows = $this->defaultRows();
        $rows[] = ['type_id' => 9, 'language_id' => 0, 'subject' => 'x', 'content' => 'y'];

        // 本專案的驗證失敗統一回 400（bootstrap/app.php render）
        $this->act('text/save', ['group_id' => $this->group->id, 'email_text' => $rows])
            ->assertStatus(400)->assertJsonPath('code', 400);
    }

    // -------------------------------------------------------
    // 範圍
    // -------------------------------------------------------

    /**
     * 一般代理不可操作其他組織的組別
     */
    public function test_other_organization_group_is_forbidden(): void
    {
        $otherGroup = $this->createEmailGroup(['organization_id' => 2]);

        $response = $this->act('view', ['group_id' => $otherGroup->id]);

        $this->assertNotSame(200, $response->json('code'));
        $this->assertSame(__('error_message.email_gateway.group_forbidden'), $response->json('message'));
        $this->assertSame(0, EmailText::where('email_gateway_group_id', $otherGroup->id)->count());
    }

    /**
     * 非電子郵件設置組別（例如簡訊組別）不可用本功能
     */
    public function test_non_email_group_is_rejected(): void
    {
        $smsGroup = $this->createEmailGroup(['agent_group_type' => AgentGroupTypeEnum::SMS_GATEWAY->value]);

        $response = $this->act('view', ['group_id' => $smsGroup->id]);

        $this->assertNotSame(200, $response->json('code'));
        $this->assertSame(__('error_message.email_gateway.group_not_email_type'), $response->json('message'));
    }
}
