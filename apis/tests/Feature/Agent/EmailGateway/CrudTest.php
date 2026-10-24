<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\EmailGateway;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\EmailSendStatusEnum;
use App\Enums\StatusEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentGroup;
use App\Models\EmailGateway;
use App\Models\EmailSendRecord;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 商戶管理 - 電子郵件提供商後台 CRUD
 *
 * php artisan test tests/Feature/Agent/EmailGateway/CrudTest.php
 */
class CrudTest extends TestCase
{
    private Agent $agent;

    private AgentGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->group = $this->createEmailGroup();
        $this->agent = $this->createAgentAndDomain(['email_gateway_group_id' => $this->group->id]);

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
        return $this->postJson('agent/api/email-gateway/' . $action, $payload, $this->headers);
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

    private function createGateway(array $overrides = []): EmailGateway
    {
        return EmailGateway::create(array_merge([
            'agent_group_id' => $this->group->id,
            'email' => 'noreply' . Str::random(6) . '@example.invalid',
            'password' => 'secret',
            'host' => 'example.invalid',
            'port' => 587,
            'scheme' => 'smtp',
            'from_name' => 'Example',
            'sort' => 0,
            'status' => StatusEnum::ENABLE->value,
            'admin' => $this->agent->id,
        ], $overrides));
    }

    /**
     * 建立另一個代理（agent_domains.domain 為唯一鍵，需先換網域）
     */
    private function createOtherAgent(array $data = []): Agent
    {
        $this->domain = bin2hex(random_bytes(8)) . '.test.local';

        return $this->createAgentAndDomain($data);
    }

    private function createRecord(array $overrides = []): EmailSendRecord
    {
        return EmailSendRecord::create(array_merge([
            'email_gateway_id' => 0,
            'agent_id' => $this->agent->id,
            'member_id' => 0,
            'sender' => 'user@example.invalid',
            'receiver' => 'user@example.invalid',
            'subject' => 'Verification Code',
            'message_content' => 'The PIN is 123456',
            'status' => EmailSendStatusEnum::SUCCESS->value,
        ], $overrides));
    }

    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'agent_group_id' => $this->group->id,
            'email' => 'user@example.invalid',
            'password' => 'app-password',
            'host' => 'example.invalid',
            'port' => 587,
            'scheme' => 'smtp',
            'from_name' => 'Example',
            'sort' => 0,
            'status' => StatusEnum::ENABLE->value,
        ], $overrides);
    }

    // -------------------------------------------------------
    // Tests
    // -------------------------------------------------------

    /**
     * 新增後應寫入資料表，且密碼以加密形式落地（不得明文存放）。
     */
    public function test_create_stores_encrypted_password(): void
    {
        // Act
        $response = $this->act('create', $this->createPayload());

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);

        $gateway = EmailGateway::where('agent_group_id', $this->group->id)->first();
        $this->assertNotNull($gateway);
        $this->assertSame('user@example.invalid', $gateway->email);
        $this->assertSame($this->agent->id, $gateway->admin);
        // 取值解密後為原文，DB 內原始值不是明文
        $this->assertSame('app-password', $gateway->password);
        $this->assertNotSame('app-password', $gateway->getRawOriginal('password'));
    }

    /**
     * 同一組別下不得出現重複的寄件信箱。
     */
    public function test_create_rejects_duplicate_email_in_same_group(): void
    {
        // Arrange
        $this->createGateway(['email' => 'user@example.invalid']);

        // Act
        $response = $this->act('create', $this->createPayload(['email' => 'user@example.invalid']));

        // Assert
        $response->assertJson(['code' => 400]);
        $this->assertSame(1, EmailGateway::where('email', 'user@example.invalid')->count());
    }

    /**
     * 提供商只能掛在電子郵件設置組別（type 7）上。
     */
    public function test_create_rejects_non_email_type_group(): void
    {
        // Arrange — 簡訊設置組別
        $smsGroup = $this->createEmailGroup([
            'agent_group_type' => AgentGroupTypeEnum::SMS_GATEWAY->value,
        ]);

        // Act
        $response = $this->act('create', $this->createPayload(['agent_group_id' => $smsGroup->id]));

        // Assert
        $response->assertJson(['code' => 400]);
        $this->assertSame(0, EmailGateway::where('agent_group_id', $smsGroup->id)->count());
    }

    /**
     * 不同組織的組別不得被操作。
     */
    public function test_create_rejects_group_from_other_organization(): void
    {
        // Arrange
        $otherOrgGroup = $this->createEmailGroup(['organization_id' => $this->agent->organization_id + 999]);

        // Act
        $response = $this->act('create', $this->createPayload(['agent_group_id' => $otherOrgGroup->id]));

        // Assert
        $response->assertJson(['code' => 400]);
        $this->assertSame(0, EmailGateway::where('agent_group_id', $otherOrgGroup->id)->count());
    }

    /**
     * 列表應帶出組別名稱與更新者，且不得回傳密碼欄位。
     */
    public function test_list_returns_group_name_and_never_exposes_password(): void
    {
        // Arrange
        $gateway = $this->createGateway();

        // Act
        $response = $this->act('list', ['page' => 1, 'per_page' => 20]);

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame($gateway->id, $items[0]['id']);
        $this->assertSame($this->group->name, $items[0]['agent_group_name']);
        $this->assertSame($this->agent->username, $items[0]['updated_by']);
        $this->assertArrayNotHasKey('password', $items[0]);
    }

    /**
     * 列表的 email 篩選為前綴比對，狀態篩選需精準命中。
     */
    public function test_list_filters_by_email_prefix_and_status(): void
    {
        // Arrange
        $this->createGateway(['email' => 'user@example.invalid', 'status' => StatusEnum::ENABLE->value]);
        $this->createGateway(['email' => 'user@example.invalid', 'status' => StatusEnum::DISABLE->value]);

        // Act & Assert — email 前綴
        $emails = collect($this->act('list', ['page' => 1, 'per_page' => 20, 'email' => 'alpha'])->json('data.items'))
            ->pluck('email')
            ->toArray();
        $this->assertSame(['user@example.invalid'], $emails);

        // Act & Assert — 狀態
        $disabled = collect($this->act('list', ['page' => 1, 'per_page' => 20, 'status' => StatusEnum::DISABLE->value])->json('data.items'))
            ->pluck('email')
            ->toArray();
        $this->assertSame(['user@example.invalid'], $disabled);
    }

    /**
     * 其他組織的提供商不應出現在列表中。
     */
    public function test_list_excludes_other_organizations(): void
    {
        // Arrange
        $otherOrgGroup = $this->createEmailGroup(['organization_id' => $this->agent->organization_id + 999]);
        $this->createGateway(['agent_group_id' => $otherOrgGroup->id, 'email' => 'user@example.invalid']);

        // Act
        $response = $this->act('list', ['page' => 1, 'per_page' => 20]);

        // Assert
        $this->assertCount(0, $response->json('data.items'));
    }

    /**
     * 組別下拉只回傳電子郵件設置組別（type 7），不得混入其他型別。
     */
    public function test_group_dropdown_returns_only_email_type_groups(): void
    {
        // Arrange
        $smsGroup = $this->createEmailGroup([
            'agent_group_type' => AgentGroupTypeEnum::SMS_GATEWAY->value,
        ]);

        // Act
        $response = $this->act('group/dropdown');

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($this->group->id, $ids);
        $this->assertNotContains($smsGroup->id, $ids);
    }

    /**
     * 發送紀錄應帶出組別／寄件信箱／代理／會員，並可依狀態與收件信箱篩選。
     */
    public function test_record_returns_joined_fields_and_filters(): void
    {
        // Arrange
        $gateway = $this->createGateway();
        $member = $this->createMember($this->agent);

        EmailSendRecord::create([
            'email_gateway_id' => $gateway->id,
            'agent_id' => $this->agent->id,
            'member_id' => $member->id,
            'receiver' => 'user@example.invalid',
            'subject' => 'Verification Code',
            'message_content' => 'The PIN is 123456',
            'status' => EmailSendStatusEnum::SUCCESS->value,
        ]);
        EmailSendRecord::create([
            'email_gateway_id' => $gateway->id,
            'agent_id' => $this->agent->id,
            'member_id' => $member->id,
            'sender' => $gateway->email,
            'receiver' => 'user@example.invalid',
            'subject' => 'Verification Code',
            'message_content' => 'The PIN is 654321',
            'response_message' => 'smtp unreachable',
            'status' => EmailSendStatusEnum::FAILED->value,
        ]);

        // Act
        $response = $this->act('record', ['page' => 1, 'per_page' => 20]);

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);

        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        // 依 id 倒序，最新一筆在前
        $this->assertSame('user@example.invalid', $items[0]['receiver']);
        $this->assertSame($this->group->name, $items[0]['agent_group_name']);
        $this->assertSame($gateway->email, $items[0]['sender']);
        $this->assertSame($this->agent->username, $items[0]['agent_username']);
        $this->assertSame($member->username, $items[0]['member_username']);
        $this->assertSame('smtp unreachable', $items[0]['response_message']);

        // 狀態篩選
        $failed = collect($this->act('record', [
            'page' => 1,
            'per_page' => 20,
            'status' => EmailSendStatusEnum::FAILED->value,
        ])->json('data.items'))->pluck('receiver')->toArray();
        $this->assertSame(['user@example.invalid'], $failed);

        // 收件信箱前綴篩選
        $byReceiver = collect($this->act('record', [
            'page' => 1,
            'per_page' => 20,
            'receiver' => 'ok@',
        ])->json('data.items'))->pluck('receiver')->toArray();
        $this->assertSame(['user@example.invalid'], $byReceiver);
    }

    /**
     * 未指派提供商（email_gateway_id = 0）的紀錄仍要查得到。
     */
    public function test_record_includes_fallback_rows_without_gateway(): void
    {
        // Arrange
        EmailSendRecord::create([
            'email_gateway_id' => 0,
            'agent_id' => $this->agent->id,
            'member_id' => 0,
            'sender' => 'user@example.invalid',
            'receiver' => 'user@example.invalid',
            'subject' => 'Verification Code',
            'message_content' => 'The PIN is 111111',
            'status' => EmailSendStatusEnum::SUCCESS->value,
        ]);

        // Act
        $items = $this->act('record', ['page' => 1, 'per_page' => 20])->json('data.items');

        // Assert
        $this->assertCount(1, $items);
        $this->assertSame('user@example.invalid', $items[0]['receiver']);
        $this->assertNull($items[0]['agent_group_name']);
        // 未指派提供商也要留下當時的寄件信箱
        $this->assertSame('user@example.invalid', $items[0]['sender']);
    }

    /**
     * 詳細不得回傳密碼，避免後台畫面外洩憑證。
     */
    public function test_detail_does_not_return_password(): void
    {
        // Arrange
        $gateway = $this->createGateway();

        // Act
        $response = $this->act('detail', ['id' => $gateway->id]);

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);
        $data = $response->json('data');
        $this->assertSame($gateway->id, $data['id']);
        $this->assertArrayNotHasKey('password', $data);
    }

    /**
     * 編輯時 password 留空應維持原密碼不變。
     */
    public function test_update_without_password_keeps_existing_password(): void
    {
        // Arrange
        $gateway = $this->createGateway(['email' => 'user@example.invalid']);

        // Act
        $response = $this->act('update', [
            'id' => $gateway->id,
            'email' => 'user@example.invalid',
            'host' => 'example.invalid',
            'port' => 465,
            'scheme' => 'smtps',
            'from_name' => 'Changed',
            'sort' => 3,
            'status' => StatusEnum::DISABLE->value,
        ]);

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);

        $gateway->refresh();
        $this->assertSame('example.invalid', $gateway->host);
        $this->assertSame(465, $gateway->port);
        $this->assertSame('smtps', $gateway->scheme);
        $this->assertSame(StatusEnum::DISABLE->value, $gateway->status);
        $this->assertSame('secret', $gateway->password);
    }

    /**
     * 編輯時帶入 password 應覆寫並維持加密儲存。
     */
    public function test_update_with_password_replaces_it(): void
    {
        // Arrange
        $gateway = $this->createGateway(['email' => 'user@example.invalid']);

        // Act
        $this->act('update', [
            'id' => $gateway->id,
            'email' => 'user@example.invalid',
            'password' => 'new-password',
            'host' => 'example.invalid',
            'port' => 587,
            'scheme' => 'smtp',
            'status' => StatusEnum::ENABLE->value,
        ])->assertJson(['code' => 200]);

        // Assert
        $gateway->refresh();
        $this->assertSame('new-password', $gateway->password);
        $this->assertNotSame('new-password', $gateway->getRawOriginal('password'));
    }

    /**
     * 狀態切換應寫入 status 與更新者。
     */
    public function test_update_status_toggles_and_records_operator(): void
    {
        // Arrange
        $gateway = $this->createGateway(['status' => StatusEnum::ENABLE->value, 'admin' => 0]);

        // Act
        $this->act('status', ['id' => $gateway->id, 'status' => StatusEnum::DISABLE->value])
            ->assertJson(['code' => 200]);

        // Assert
        $gateway->refresh();
        $this->assertSame(StatusEnum::DISABLE->value, $gateway->status);
        $this->assertSame($this->agent->id, $gateway->admin);
    }

    /**
     * 刪除後資料表不應留存該筆記錄。
     */
    public function test_delete_removes_record(): void
    {
        // Arrange
        $gateway = $this->createGateway();

        // Act
        $this->act('delete', ['id' => $gateway->id])->assertJson(['code' => 200]);

        // Assert
        $this->assertSame(0, EmailGateway::where('id', $gateway->id)->count());
    }

    /**
     * 不存在的 id 應回錯誤而非 500。
     */
    public function test_detail_returns_error_for_missing_record(): void
    {
        // Act & Assert
        $this->act('detail', ['id' => 0])->assertJson(['code' => 400]);
    }

    /**
     * 其他組織的提供商不得被讀取／刪除。
     */
    public function test_other_organization_gateway_is_not_operable(): void
    {
        // Arrange
        $otherOrgGroup = $this->createEmailGroup(['organization_id' => $this->agent->organization_id + 999]);
        $gateway = $this->createGateway(['agent_group_id' => $otherOrgGroup->id]);

        // Act & Assert
        $this->act('detail', ['id' => $gateway->id])->assertJson(['code' => 400]);
        $this->act('delete', ['id' => $gateway->id])->assertJson(['code' => 400]);
        $this->assertSame(1, EmailGateway::where('id', $gateway->id)->count());
    }

    /**
     * scheme 只接受 smtp / smtps。
     */
    public function test_create_rejects_unknown_scheme(): void
    {
        // Act
        $response = $this->act('create', $this->createPayload(['scheme' => 'tls']));

        // Assert
        $response->assertStatus(400)->assertJson(['code' => 400]);
        $this->assertSame(0, EmailGateway::where('agent_group_id', $this->group->id)->count());
    }

    /**
     * 未帶 Authorization header 時應被 AuthAgent middleware 擋下。
     */
    public function test_requires_authentication(): void
    {
        // Arrange
        unset($this->headers['Authorization']);

        // Act & Assert
        $this->act('list', ['page' => 1, 'per_page' => 20])->assertStatus(319);
    }

    /**
     * 開關關閉時列表不得回傳郵件內容，避免驗證碼從 API 外洩。
     */
    public function test_record_hides_content_when_setting_disabled(): void
    {
        // Arrange
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->createAgentSetting(
            $this->agent,
            AgentCustomizedSettingKeyEnum::EMAIL_RECORD_VERIFICATION_CODE_VISIBLE,
            0
        );
        $this->createRecord();

        // Act
        $items = $this->act('record', ['page' => 1, 'per_page' => 20])->json('data.items');

        // Assert
        $this->assertCount(1, $items);
        $this->assertSame('', $items[0]['message_content']);
        // 其餘欄位照常回傳
        $this->assertSame('user@example.invalid', $items[0]['receiver']);
    }

    /**
     * 未設定開關時維持直接顯示。
     */
    public function test_record_shows_content_by_default(): void
    {
        // Arrange
        $this->createRecord();

        // Act
        $items = $this->act('record', ['page' => 1, 'per_page' => 20])->json('data.items');

        // Assert
        $this->assertSame('The PIN is 123456', $items[0]['message_content']);
    }

    /**
     * 點擊查看應回傳內容並寫入代理操作紀錄。
     */
    public function test_record_content_returns_content_and_writes_operate_log(): void
    {
        // Arrange
        $record = $this->createRecord();

        // Act
        $response = $this->act('record/content', ['send_id' => $record->id]);

        // Assert
        $response->assertStatus(200)->assertJson([
            'code' => 200,
            'data' => ['message_content' => 'The PIN is 123456'],
        ]);
        $this->assertDatabaseHas('agent_operate_logs', [
            'agent_id' => $this->agent->id,
            'type' => 'view_email_content',
            'reference_id' => (string) $record->id,
        ]);
    }

    /**
     * 查看不得跨組織，且不得因此留下操作紀錄。
     */
    public function test_record_content_rejects_other_organization(): void
    {
        // Arrange
        $other = $this->createOtherAgent(['organization_id' => 99]);
        $record = $this->createRecord(['agent_id' => $other->id]);

        // Act
        $response = $this->act('record/content', ['send_id' => $record->id]);

        // Assert
        $response->assertStatus(400)->assertJson(['code' => 400]);
        $this->assertDatabaseMissing('agent_operate_logs', [
            'reference_id' => (string) $record->id,
            'type' => 'view_email_content',
        ]);
    }

    /**
     * 查看不存在的紀錄應回錯誤。
     */
    public function test_record_content_rejects_missing_record(): void
    {
        // Act & Assert
        $this->act('record/content', ['send_id' => 999_999])
            ->assertStatus(400)
            ->assertJson(['code' => 400]);
    }
}
