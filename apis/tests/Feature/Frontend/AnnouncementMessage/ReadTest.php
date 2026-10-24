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
 * php artisan test tests/Feature/Frontend/AnnouncementMessage/ReadTest.php
 */
class ReadTest extends TestCase
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

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);
    }

    /**
     * 測試登入
     * php artisan test tests/Feature/Frontend/AnnouncementMessage/ReadTest.php --filter=test_login_success
     */
    public function test_login_success(): void
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
            uri: 'frontend/api/announcement-message/read',
            data: [
                'announcement_message_id' => $announcement->id,
            ],
            headers: [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->token,
            ]
        );

        $response->assertStatus(200);
        $read = $announcement->reads()->where('member_id', $this->member->id)->first();
        $this->assertNotNull($read);
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
