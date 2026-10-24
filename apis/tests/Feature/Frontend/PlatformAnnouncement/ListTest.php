<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\PlatformAnnouncement;

use App\Enums\General\HttpCode;
use App\Models\Agent;
use App\Models\Member;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformAnnouncementImage;
use App\Models\PlatformAnnouncementIncludeAgent;
use App\Models\PlatformAnnouncementLanguage;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ListTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected string $loginToken;

    protected array $imageUrlList;

    protected mixed $defaultId;

    protected array $testData = [
        'en' => [
            'title' => 'default title en',
            'content' => 'default content en',
            'image_url' => 'http://example.invalid',
        ],
        'cn' => [
            'language_id' => 3,
            'title' => '标题 cn',
            'content' => '内容 cn',
            'image_url' => 'http://example.invalid',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->loginToken = [REDACTED];

        $this->imageUrlList = $this->createImageUrl();

        $this->defaultId = $this->createData([
            'agent_id' => $this->agent->id,
            'title' => $this->testData['en']['title'],
            'content' => $this->testData['en']['content'],
            'platform_announcement_image_id' => $this->imageUrlList['en']['id'],
            'status' => 1,
        ]);
    }

    /**
     * 英文為預設語系 測試資料塞入跟取出
     */
    public function test_en_language_list_success(): void
    {
        // Arrange
        $expected = $this->testData['en'];

        // Act
        $response = $this->act([
            'domain' => $this->domain,
            'page' => 1,
            'per_page' => 10,
        ], 'en');

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);

        $response->assertJsonFragment([
            'id' => $this->defaultId,
            'title' => $expected['title'],
            'content' => $expected['content'],
            'image_url' => $expected['image_url'],
        ]);
    }

    /**
     * 簡中非預設語系 測試資料塞入跟取出
     */
    public function test_cn_language_list_success(): void
    {
        // Arrange
        $expected = $this->testData['cn'];
        $cnId = $this->createLanguageData([
            'platform_announcement_id' => $this->defaultId,
            'language_id' => $expected['language_id'],
            'title' => $expected['title'],
            'content' => $expected['content'],
            'platform_announcement_image_id' => $this->imageUrlList['cn']['id'],
        ]);

        // Act
        $response = $this->act([
            'domain' => $this->domain,
            'page' => 1,
            'per_page' => 10,
        ], 'zh-cn');

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);

        $response->assertJsonFragment([
            'pal_id' => $cnId,
            'title' => $expected['title'],
            'content' => $expected['content'],
            'image_url' => $expected['image_url'],
        ]);
    }

    /**
     * 繁中無數據 抓取預設語系(英文) 測試取出
     */
    public function test_default_language_list_success(): void
    {
        // Arrange
        $expected = $this->testData['en'];

        // Act
        $response = $this->act([
            'domain' => $this->domain,
            'page' => 1,
            'per_page' => 10,
        ], 'zh-tw');

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);

        $response->assertJsonFragment([
            'id' => $this->defaultId,
            'title' => $expected['title'],
            'content' => $expected['content'],
            'image_url' => $expected['image_url'],
        ]);
    }

    /**
     * 發送請求
     */
    private function act(array $params, string $language): TestResponse
    {
        return $this->json(
            'POST',
            '/frontend/api/platform-announcement/list',
            $this->encryptParams($params),
            [
                'Accept-Language' => $language,
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );
    }

    /**
     * 創建平台公告圖片
     */
    private function createImageUrl(): array
    {
        $imageEn = PlatformAnnouncementImage::factory()->create([
            'image_url' => 'http://example.invalid',
        ]);

        $imageCn = PlatformAnnouncementImage::factory()->create([
            'image_url' => 'http://example.invalid',
        ]);

        return [
            'en' => ['id' => $imageEn->id, 'image_url' => $imageEn->image_url],
            'cn' => ['id' => $imageCn->id, 'image_url' => $imageCn->image_url],
        ];
    }

    /**
     * 創建平台公告預設語系
     */
    private function createData(array $data)
    {
        $result = PlatformAnnouncement::factory()->create($data);

        PlatformAnnouncementIncludeAgent::insert([
            'platform_announcement_id' => $result->id,
            'agent_id' => $this->agent->id,
        ]);

        return $result->id;
    }

    /**
     * 創建平台公告其他語系
     */
    private function createLanguageData(array $data)
    {
        $result = PlatformAnnouncementLanguage::factory()->create($data);

        return $result->id;
    }
}
