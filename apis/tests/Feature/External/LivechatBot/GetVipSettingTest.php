<?php

declare(strict_types=1);

namespace Tests\Feature\External\LivechatBot;

use App\Enums\LivechatBotVipRewardEnum;
use App\Models\Agent;
use App\Models\LivechatBot;
use App\Models\MemberVip;
use App\Models\VipContent;
use App\Models\VipContentReward;
use Tests\TestCase;

/**
 * Livechat Bot 取得 VIP 設定 Feature 測試（HTTP 層行為）
 *
 * php artisan test tests/Feature/External/LivechatBot/GetVipSettingTest.php
 */
class GetVipSettingTest extends TestCase
{
    private const string ENDPOINT = '/external/api/api-callback/livechat/bot/get-vip-setting';

    private const string VIP_URL = 'https://example.invalid/en-my/vip';

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
            'vip_url' => self::VIP_URL,
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

    /**
     * 會員無 member_vips 但 agent 已設定 VipContent → 降級回傳 level 0（對齊舊系統，不再 404）。
     */
    public function test_member_without_member_vip_falls_back_to_lowest_level(): void
    {
        $member = $this->createMember($this->agent);

        VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 0,
            'name' => 'VIP 0',
            'target_amount' => 0,
            'keep_amount' => 0,
        ]);

        $response = $this->getJson(
            self::ENDPOINT . '/' . $member->username,
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(200);
        $payload = $response->json();
        $this->assertTrue($payload['status']);
        $this->assertSame('VIP0', $payload['output']['vipLevel']);
        $this->assertSame('0.0000 / 0.0000', $payload['output']['upgradeProgress']);
    }

    /**
     * 會員無 member_vips 且 agent 階層完全無 VipContent → 仍 404。
     */
    public function test_member_without_member_vip_and_no_vip_content_returns_404(): void
    {
        $member = $this->createMember($this->agent);

        $response = $this->getJson(
            self::ENDPOINT . '/' . $member->username,
            ['Authorization' => 'Bearer ' . $this->apiKey],
        );

        $response->assertStatus(404);
    }

    public function test_existing_member_returns_vip_setting_envelope(): void
    {
        $member = $this->createMember($this->agent);

        $current = VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 0,
            'name' => 'VIP 0',
            'target_amount' => 0,
            'keep_amount' => 0,
        ]);
        VipContentReward::create([
            'vip_content_id' => $current->id,
            'vip_reward_id' => LivechatBotVipRewardEnum::DAILY_REBATE->value,
            'point' => '2.0000',
            'status' => 1,
        ]);
        VipContentReward::create([
            'vip_content_id' => $current->id,
            'vip_reward_id' => LivechatBotVipRewardEnum::BIRTHDAY->value,
            'point' => '1.0000',
            'status' => 1,
        ]);

        $next = VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 1,
            'name' => 'VIP 1',
            'target_amount' => 3,
            'keep_amount' => 100,
        ]);
        VipContentReward::create([
            'vip_content_id' => $next->id,
            'vip_reward_id' => LivechatBotVipRewardEnum::UPGRADE->value,
            'point' => '0.0000',
            'status' => 1,
        ]);

        MemberVip::create([
            'member_id' => $member->id,
            'vip_content_id' => $current->id,
            'deposit_amount' => '0.0000',
            'keep_amount' => '0.0000',
        ]);

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
                'id',
                'vipLevel',
                'name',
                'vipDailyBonus',
                'vipMonthlyBonus',
                'vipWeeklyBonus',
                'vipDailyAidBonus',
                'vipWeeklyAidBonus',
                'vipMonthlyAidBonus',
                'vipBirthdayBonus',
                'upgradePoints',
                'vipUpgradeBonus',
                'maintainPoints',
                'vipHolidayBonus',
                'upgradeProgress',
                'url',
                'message',
            ],
            'error',
        ]);

        $payload = $response->json();
        $this->assertTrue($payload['status']);
        $this->assertSame(200, $payload['code']);
        $this->assertSame('Success', $payload['msg']);
        $this->assertSame([], $payload['error']);
        $this->assertSame('VIP0', $payload['output']['vipLevel']);
        $this->assertSame('VIP 0', $payload['output']['name']);
        $this->assertSame('2.0000', $payload['output']['vipDailyBonus']);
        $this->assertSame('1.0000', $payload['output']['vipBirthdayBonus']);
        $this->assertSame('0.0000 / 3.0000', $payload['output']['upgradeProgress']);
        $this->assertSame(self::VIP_URL, $payload['output']['url']);
    }
}
