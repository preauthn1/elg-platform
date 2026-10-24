<?php

declare(strict_types=1);

namespace Tests\Feature\External\LivechatBot;

use App\Models\Agent;
use App\Models\LivechatBot;
use Tests\TestCase;

/**
 * Livechat Bot 活動列表 Feature 測試（HTTP 層行為）
 *
 * php artisan test tests/Feature/External/LivechatBot/PromotionsTest.php
 */
class PromotionsTest extends TestCase
{
    private const string ENDPOINT = '/external/api/api-callback/livechat/bot/promotions';

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
            self::ENDPOINT . '/someuser',
            ['Authorization' => 'Bearer wrong-key'],
        );

        $response->assertStatus(401);
    }

    public function test_missing_bearer_returns_401(): void
    {
        $response = $this->getJson(self::ENDPOINT . '/someuser');

        $response->assertStatus(401);
    }

    public function test_nonexistent_username_returns_404(): void
    {
        $response = $this->getJson(
            self::ENDPOINT . '/no_such_user_999',
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(404);
    }

    public function test_existing_member_returns_livechat_bot_envelope_with_three_categories(): void
    {
        $member = $this->createMember($this->agent);

        $response = $this->getJson(
            self::ENDPOINT . '/' . $member->username,
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'code',
            'msg',
            'output' => [
                'activePromotions',
                'claimablePromotions',
                'conditionedPromotions',
            ],
            'error',
        ]);

        $payload = $response->json();
        $this->assertTrue($payload['status']);
        $this->assertSame(200, $payload['code']);
        $this->assertSame('Success', $payload['msg']);
        $this->assertSame([], $payload['error']);
        $this->assertIsArray($payload['output']['activePromotions']);
        $this->assertIsArray($payload['output']['claimablePromotions']);
        $this->assertIsArray($payload['output']['conditionedPromotions']);
    }
}
