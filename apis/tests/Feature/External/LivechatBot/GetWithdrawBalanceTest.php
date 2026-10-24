<?php

declare(strict_types=1);

namespace Tests\Feature\External\LivechatBot;

use App\Models\Agent;
use App\Models\LivechatBot;
use Tests\TestCase;

/**
 * Livechat Bot 查詢可提款餘額 Feature 測試（HTTP 層行為）
 *
 * php artisan test tests/Feature/External/LivechatBot/GetWithdrawBalanceTest.php
 */
class GetWithdrawBalanceTest extends TestCase
{
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
            '/external/api/api-callback/livechat/bot/get-withdraw-balance/someuser',
            ['Authorization' => 'Bearer wrong-key'],
        );

        $response->assertStatus(401);
    }

    public function test_missing_bearer_returns_401(): void
    {
        $response = $this->getJson(
            '/external/api/api-callback/livechat/bot/get-withdraw-balance/someuser',
        );

        $response->assertStatus(401);
    }

    public function test_nonexistent_username_returns_404(): void
    {
        $response = $this->getJson(
            '/external/api/api-callback/livechat/bot/get-withdraw-balance/no_such_user_999',
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(404);
    }
}
