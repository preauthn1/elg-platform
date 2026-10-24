<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\PlatformAnnouncement;

use App\Enums\General\HttpCode;
use App\Models\Agent;
use App\Models\Member;
use App\Models\PlatformAnnouncementRead;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReadAllTest extends TestCase
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

    public function test_read_all_success(): void
    {
        // Arrange
        $originalLastReadTime = $this->getLastReadTime($this->member->id);

        // Act
        $response = $this->act([
            'domain' => $this->domain,
        ]);

        // Assert
        $lastReadTime = $this->getLastReadTime($this->member->id);

        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertTrue(!empty($lastReadTime), '最後讀取時間為空');
        $this->assertTrue($lastReadTime > $originalLastReadTime);
    }

    /**
     * 發送請求
     */
    private function act(array $params): TestResponse
    {
        return $this->json(
            'POST',
            '/frontend/api/platform-announcement/read-all',
            $this->encryptParams($params),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );
    }

    /**
     * 取得最後讀取時間
     */
    private function getLastReadTime($memberId): string
    {
        $lastRecord = PlatformAnnouncementRead::where('member_id', $memberId)->orderBy('created_at', 'desc')->first();

        return $lastRecord->last_read_time ?? '';
    }
}
