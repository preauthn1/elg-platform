<?php

declare(strict_types=1);

namespace Tests\Feature\External\LivechatBot;

use App\Models\Agent;
use App\Models\LivechatBot;
use Tests\TestCase;

/**
 * Livechat Bot 查詢儲值/提款紀錄 Feature 測試（HTTP 層行為）
 *
 * php artisan test tests/Feature/External/LivechatBot/InquiryDepositWithdrawalTest.php
 */
class InquiryDepositWithdrawalTest extends TestCase
{
    private const string ENDPOINT = '/external/api/api-callback/livechat/bot/inquiry-deposit-withdrawal';

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
            self::ENDPOINT . '/someuser/deposit',
            ['Authorization' => 'Bearer wrong-key'],
        );

        $response->assertStatus(401);
    }

    public function test_missing_bearer_returns_401(): void
    {
        $response = $this->getJson(self::ENDPOINT . '/someuser/deposit');

        $response->assertStatus(401);
    }

    public function test_nonexistent_username_returns_404(): void
    {
        $response = $this->getJson(
            self::ENDPOINT . '/no_such_user_999/deposit',
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(404);
    }

    /**
     * transaction_type 限制為 deposit|withdraw（route where 約束）。
     * 不合法值 → route 不匹配 → 404，不會進到 FormRequest validation。
     */
    public function test_invalid_transaction_type_returns_404(): void
    {
        $member = $this->createMember($this->agent);

        $response = $this->getJson(
            self::ENDPOINT . '/' . $member->username . '/invalid',
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(404);
    }

    /**
     * 缺少 transaction_type segment → URL 結構不完整 → route 404。
     */
    public function test_missing_transaction_type_returns_404(): void
    {
        $member = $this->createMember($this->agent);

        $response = $this->getJson(
            self::ENDPOINT . '/' . $member->username,
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(404);
    }
}
