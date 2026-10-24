<?php

declare(strict_types=1);

namespace Tests\Feature\External\LivechatBot;

use App\Models\LivechatBot;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * 多 bot 鑑權與跨 agent 隔離 Feature 測試
 *
 * 驗證：
 * - 兩個 bot 的 Bearer token 各自能命中自己綁定的 agent
 * - bot A 的 token 無法查詢 agent B 旗下 member（回 404，不洩漏資訊）
 * - inactive bot / 未知 token / 缺 token 全數視為 401
 *
 * php artisan test tests/Feature/External/LivechatBot/MultiBotAuthTest.php
 */
class MultiBotAuthTest extends TestCase
{
    private const string ENDPOINT_BASE = '/external/api/api-callback/livechat/bot/get-balance';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
    }

    public function test_each_bot_can_query_member_under_its_own_agent(): void
    {
        $agentA = $this->createAgentAndDomain();
        $apiKeyA = bin2hex(random_bytes(16));
        LivechatBot::create([
            'name' => 'brand_a_' . bin2hex(random_bytes(4)),
            'agent_id' => $agentA->id,
            'api_key' => $apiKeyA,
            'vip_url' => null,
            'is_active' => true,
        ]);

        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $agentB = $this->createAgentAndDomain();
        $apiKeyB = bin2hex(random_bytes(16));
        LivechatBot::create([
            'name' => 'brand_b_' . bin2hex(random_bytes(4)),
            'agent_id' => $agentB->id,
            'api_key' => $apiKeyB,
            'vip_url' => null,
            'is_active' => true,
        ]);

        $memberA = $this->createMember($agentA, ['username' => 'mema_' . bin2hex(random_bytes(4))]);
        $memberB = $this->createMember($agentB, ['username' => 'memb_' . bin2hex(random_bytes(4))]);

        // bot A 查 agent A 的 member → 200
        $this->getJson(self::ENDPOINT_BASE . '/' . $memberA->username, ['Authorization' => 'Bearer ' . $apiKeyA])
            ->assertStatus(200);

        // bot B 查 agent B 的 member → 200
        $this->getJson(self::ENDPOINT_BASE . '/' . $memberB->username, ['Authorization' => 'Bearer ' . $apiKeyB])
            ->assertStatus(200);
    }

    public function test_bot_cannot_query_member_under_another_agent(): void
    {
        $agentA = $this->createAgentAndDomain();
        $apiKeyA = bin2hex(random_bytes(16));
        LivechatBot::create([
            'name' => 'brand_a_' . bin2hex(random_bytes(4)),
            'agent_id' => $agentA->id,
            'api_key' => $apiKeyA,
            'vip_url' => null,
            'is_active' => true,
        ]);

        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $agentB = $this->createAgentAndDomain();
        $memberB = $this->createMember($agentB, ['username' => 'crossagent_' . bin2hex(random_bytes(4))]);

        // bot A 查 agent B 的 member → 404（避免洩漏跨 tenant 資訊）
        $this->getJson(self::ENDPOINT_BASE . '/' . $memberB->username, ['Authorization' => 'Bearer ' . $apiKeyA])
            ->assertStatus(404);
    }

    public function test_inactive_bot_is_unauthorized(): void
    {
        $agent = $this->createAgentAndDomain();
        $apiKey = [REDACTED];
        LivechatBot::create([
            'name' => 'inactive_' . bin2hex(random_bytes(4)),
            'agent_id' => $agent->id,
            'api_key' => $apiKey,
            'vip_url' => null,
            'is_active' => false,
        ]);

        $member = $this->createMember($agent);

        $this->getJson(self::ENDPOINT_BASE . '/' . $member->username, ['Authorization' => 'Bearer ' . $apiKey])
            ->assertStatus(401);
    }

    public function test_unknown_bearer_returns_401(): void
    {
        $this->getJson(self::ENDPOINT_BASE . '/anyuser', ['Authorization' => 'Bearer [REDACTED]'])
            ->assertStatus(401);
    }

    public function test_missing_bearer_returns_401(): void
    {
        $this->getJson(self::ENDPOINT_BASE . '/anyuser')
            ->assertStatus(401);
    }
}
