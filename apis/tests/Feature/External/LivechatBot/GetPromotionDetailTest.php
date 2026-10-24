<?php

declare(strict_types=1);

namespace Tests\Feature\External\LivechatBot;

use App\Models\Agent;
use App\Models\LivechatBot;
use Tests\TestCase;

/**
 * Livechat Bot 活動明細查詢 Feature 測試（HTTP 層行為）
 *
 * php artisan test tests/Feature/External/LivechatBot/GetPromotionDetailTest.php
 */
class GetPromotionDetailTest extends TestCase
{
    private const ENDPOINT = '/external/api/api-callback/livechat/bot/get-promotion-detail';

    private string $apiKey;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->apiKey = [REDACTED];
        LivechatBot::create([
            'name' => 'test_bot_' . bin2hex(random_bytes(4)),
            'agent_id' => $this->agent->id,
            'api_key' => $this->apiKey,
            'vip_url' => null,
            'is_active' => true,
        ]);
    }

    public function test_invalid_bearer_returns_401(): void
    {
        $response = $this->getJson(
            self::ENDPOINT . '/someuser/FREE',
            ['Authorization' => 'Bearer wrong-key'],
        );

        $response->assertStatus(401);
    }

    public function test_missing_bearer_returns_401(): void
    {
        $response = $this->getJson(self::ENDPOINT . '/someuser/FREE');

        $response->assertStatus(401);
    }

    /**
     * 缺少 promotionName segment → URL 結構不完整 → route 404。
     */
    public function test_missing_promotion_name_returns_404(): void
    {
        $response = $this->getJson(
            self::ENDPOINT . '/someuser',
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(404);
    }

    public function test_nonexistent_username_returns_404(): void
    {
        $response = $this->getJson(
            self::ENDPOINT . '/no_such_user_999/FREE',
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(404);
    }

    public function test_existing_member_with_no_match_returns_empty_output(): void
    {
        $member = $this->createMember($this->agent);

        $response = $this->getJson(
            self::ENDPOINT . '/' . $member->username . '/NONEXISTENT_KEYWORD_XYZ',
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'code',
            'msg',
            'output',
            'error',
        ]);

        $payload = $response->json();
        $this->assertTrue($payload['status']);
        $this->assertSame(200, $payload['code']);
        $this->assertSame('Success', $payload['msg']);
        $this->assertSame([], $payload['output']);
        $this->assertSame([], $payload['error']);
    }
}
