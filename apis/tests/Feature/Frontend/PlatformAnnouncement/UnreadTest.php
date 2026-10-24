<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\PlatformAnnouncement;

use App\Enums\General\HttpCode;
use App\Models\Agent;
use App\Models\Member;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformAnnouncementIncludeAgent;
use App\Models\PlatformAnnouncementRead;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UnreadTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected string $loginToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->loginToken = [REDACTED];
    }

    public function test_unread_zero_success()
    {
        // Arrange
        $this->updateLastReadTime();

        // Act
        $response = $this->act([
            'domain' => $this->domain,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $data = json_decode($response->getContent(), true)['data'];
        $this->assertEquals(0, $data['unread'], '未讀數量應為0');
    }

    public function test_unread_one_success()
    {
        // Arrange
        $this->updateLastReadTime();
        $this->createPlatformAnnouncement();

        // Act
        $response = $this->act([
            'domain' => $this->domain,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $data = json_decode($response->getContent(), true)['data'];
        $this->assertEquals(1, $data['unread'], '未讀數量應為1');
    }

    /**
     * 發送請求
     */
    private function act(array $params): TestResponse
    {
        return $this->json(
            'POST',
            '/frontend/api/platform-announcement/unread',
            $this->encryptParams($params),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );
    }

    /**
     * 更新或創建最後讀取時間
     */
    private function updateLastReadTime(): void
    {
        $now = Carbon::now()->toDateTimeString();
        PlatformAnnouncementRead::updateOrCreate(
            [
                'member_id' => $this->member->id,
            ],
            [
                'last_read_time' => $now,
            ]
        );
    }

    /**
     * 創建平台公告
     */
    private function createPlatformAnnouncement(): void
    {
        $result = PlatformAnnouncement::factory()->create([
            'agent_id' => $this->agent->id,
            'title' => 'test title',
            'content' => 'test content',
            'platform_announcement_image_id' => 1,
            'status' => 1,
        ]);

        PlatformAnnouncementIncludeAgent::insert([
            'platform_announcement_id' => $result->id,
            'agent_id' => $this->agent->id,
        ]);
    }
}
