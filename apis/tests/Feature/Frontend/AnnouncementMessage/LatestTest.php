<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\AnnouncementMessage;

use App\Enums\AnnouncementMessageTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AnnouncementMessage;
use App\Models\Member;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/AnnouncementMessage/LatestTest.php
 */
class LatestTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected string $token;

    protected array $imageUrlList;

    protected mixed $defaultId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->token = [REDACTED];
        $this->agent->organization_id = 1;
        $this->agent->chat_group_id = 1;
        $this->agent->save();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);
    }

    /**
     * 測試member
     * php artisan test tests/Feature/Frontend/AnnouncementMessage/LatestTest.php --filter=test_member_success
     */
    public function test_member_success(): void
    {
        $announcement = $this->createAnnouncementMessage();
        $announcement->participants()->create([
            'announcement_message_id' => $announcement->id,
            'member_id' => $this->member->id,
            'type' => AnnouncementMessageTypeEnum::MEMBER,
        ]);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/announcement-message/latest',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'announcement_message_id' => $announcement->id,
                    'title' => $announcement->title,
                    'message' => $announcement->message,
                    'created_at' => $announcement->created_at->toDateTimeString(),
                ],
            ]);
    }

    /**
     * 測試agent
     * php artisan test tests/Feature/Frontend/AnnouncementMessage/LatestTest.php --filter=test_agent_success
     */
    public function test_agent_success(): void
    {
        $announcement = $this->createAnnouncementMessage();
        $announcement->participants()->create([
            'announcement_message_id' => $announcement->id,
            'single_agent_id' => $this->agent->id,
            'type' => AnnouncementMessageTypeEnum::AGENT,
        ]);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/announcement-message/latest',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'announcement_message_id' => $announcement->id,
                    'title' => $announcement->title,
                    'message' => $announcement->message,
                    'created_at' => $announcement->created_at->toDateTimeString(),
                ],
            ]);
    }

    /**
     * 測試chat_group
     * php artisan test tests/Feature/Frontend/AnnouncementMessage/LatestTest.php --filter=test_chat_group_success
     */
    public function test_chat_group_success(): void
    {
        $announcement = $this->createAnnouncementMessage();
        $announcement->participants()->create([
            'announcement_message_id' => $announcement->id,
            'chat_group_id' => $this->agent->chat_group_id,
            'type' => AnnouncementMessageTypeEnum::CHAT_GROUP,
        ]);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/announcement-message/latest',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'announcement_message_id' => $announcement->id,
                    'title' => $announcement->title,
                    'message' => $announcement->message,
                    'created_at' => $announcement->created_at->toDateTimeString(),
                ],
            ]);
    }

    /**
     * 測試organization
     * php artisan test tests/Feature/Frontend/AnnouncementMessage/LatestTest.php --filter=test_organization_success
     */
    public function test_organization_success(): void
    {
        $announcement = $this->createAnnouncementMessage();
        $announcement->participants()->create([
            'announcement_message_id' => $announcement->id,
            'organization_id' => $this->agent->organization_id,
            'type' => AnnouncementMessageTypeEnum::ORGANIZATION,
        ]);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/announcement-message/latest',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'announcement_message_id' => $announcement->id,
                    'title' => $announcement->title,
                    'message' => $announcement->message,
                    'created_at' => $announcement->created_at->toDateTimeString(),
                ],
            ]);
    }

    /**
     * 測試agent_tree
     * php artisan test tests/Feature/Frontend/AnnouncementMessage/LatestTest.php --filter=test_agent_tree_success
     */
    public function test_agent_tree_success(): void
    {
        $announcement = $this->createAnnouncementMessage();
        $announcement->participants()->create([
            'announcement_message_id' => $announcement->id,
            'hierarchy_agent_id' => $this->agent->id,
            'type' => AnnouncementMessageTypeEnum::AGENT_TREE,
        ]);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/announcement-message/latest',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'announcement_message_id' => $announcement->id,
                    'title' => $announcement->title,
                    'message' => $announcement->message,
                    'created_at' => $announcement->created_at->toDateTimeString(),
                ],
            ]);
    }

    /**
     * 測試登入無公告
     * php artisan test tests/Feature/Frontend/AnnouncementMessage/LatestTest.php --filter=test_login_no_announcement
     */
    public function test_login_no_announcement(): void
    {
        $announcement = $this->createAnnouncementMessage();
        // $announcement->participants()->create([
        //     'announcement_message_id' => $announcement->id,
        //     'member_id' => $this->member->id+1,
        //     'type' => AnnouncementMessageTypeEnum::MEMBER,
        // ]);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/announcement-message/latest',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEmpty($data);
    }

    /**
     * 測試已讀
     * php artisan test tests/Feature/Frontend/AnnouncementMessage/LatestTest.php --filter=test_login_read_success
     */
    public function test_login_read_success(): void
    {
        $announcement = $this->createAnnouncementMessage();
        $announcement->participants()->create([
            'announcement_message_id' => $announcement->id,
            'member_id' => $this->member->id,
            'type' => AnnouncementMessageTypeEnum::MEMBER,
        ]);
        $announcement->reads()->create([
            'member_id' => $this->member->id,
        ]);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/announcement-message/latest',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEmpty($data);
    }

    /**
     * 測試未登入
     * php artisan test tests/Feature/Frontend/AnnouncementMessage/LatestTest.php --filter=test_unlogin_success
     */
    public function test_unlogin_success(): void
    {
        $announcement = $this->createAnnouncementMessage();
        $announcement->participants()->create([
            'announcement_message_id' => $announcement->id,
            'single_agent_id' => $this->agent->id,
            'type' => AnnouncementMessageTypeEnum::AGENT,
        ]);

        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/announcement-message/latest',
            data: [
                'domain' => $this->domain,
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
            ]
        );

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'announcement_message_id' => $announcement->id,
                    'title' => $announcement->title,
                    'message' => $announcement->message,
                    'created_at' => $announcement->created_at->toDateTimeString(),
                ],
            ]);
    }

    public function createAnnouncementMessage(): AnnouncementMessage
    {
        return AnnouncementMessage::create([
            'title' => '測試公告標題',
            'message' => '測試公告內容',
            'memo' => '測試公告備註',
            'start_time' => now()->subDays(1),
            'end_time' => now()->addDays(1),
            'status' => 1,
            'organization_id' => 0,
            'created_by' => 0,
            'updated_by' => 0,
        ]);
    }
}
