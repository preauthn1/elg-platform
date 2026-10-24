<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LivechatBot;

use App\Enums\LivechatBotVipRewardEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\LivechatBot;
use App\Models\MemberVip;
use App\Models\VipContent;
use App\Models\VipContentReward;
use App\Services\LivechatBot\GetVipSettingService;
use App\Services\LivechatBot\LivechatBotContext;
use App\Services\LivechatBot\MemberAgentGuard;
use Tests\TestCase;

/**
 * Livechat Bot 取得 VIP 設定測試
 *
 * php artisan test tests/Unit/Services/LivechatBot/GetVipSettingTest.php
 */
class GetVipSettingTest extends TestCase
{
    private const string VIP_URL = 'https://example.invalid/en-my/vip';

    private ?Agent $agent = null;

    private ?LivechatBotContext $context = null;

    private ?MemberAgentGuard $guard = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->context = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($this->agent->id, self::VIP_URL)));
        $this->guard = new MemberAgentGuard;
    }

    private function makeBot(int $agentId, ?string $vipUrl = null): LivechatBot
    {
        return LivechatBot::create([
            'name' => 'unit_bot_' . bin2hex(random_bytes(4)),
            'agent_id' => $agentId,
            'api_key' => bin2hex(random_bytes(16)),
            'vip_url' => $vipUrl,
            'is_active' => true,
        ]);
    }

    private function makeService(?LivechatBotContext $context = null): GetVipSettingService
    {
        return new GetVipSettingService(
            context: $context ?? $this->context,
            guard: $this->guard,
        );
    }

    /**
     * 會員不存在 → 404
     */
    public function test_member_not_found_throws_404(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        $this->makeService()->getVipSetting('nonexistent_user_999');
    }

    /**
     * 會員無 MemberVip 但 agent 有 level 0 VipContent → 降級回傳 level 0 結果（不再 404）。
     *
     * 對齊舊系統 api/vip/info：未首充會員 deposit_amount 起點為 0。
     */
    public function test_member_without_member_vip_falls_back_to_lowest_level(): void
    {
        $member = $this->createMember($this->agent);

        $level0 = VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 0,
            'name' => 'VIP 0',
            'target_amount' => 0,
            'keep_amount' => 0,
        ]);
        $this->createReward($level0, LivechatBotVipRewardEnum::DAILY_REBATE, '2.0000');

        $next = VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 1,
            'name' => 'VIP 1',
            'target_amount' => 3,
            'keep_amount' => 100,
        ]);
        $this->createReward($next, LivechatBotVipRewardEnum::UPGRADE, '50.0000');

        $result = $this->makeService()->getVipSetting($member->username);

        $this->assertSame($level0->id, $result['id']);
        $this->assertSame('VIP0', $result['vipLevel']);
        $this->assertSame('VIP 0', $result['name']);
        $this->assertSame('2.0000', $result['vipDailyBonus']);
        // 降級起點：deposit_amount 視為 0
        $this->assertSame('50.0000', $result['vipUpgradeBonus']);
        $this->assertSame('0.0000 / 3.0000', $result['upgradeProgress']);
        $this->assertSame(self::VIP_URL, $result['url']);
    }

    /**
     * 會員無 MemberVip 且整個 agent 階層皆無 VipContent → 仍拋 404。
     */
    public function test_member_without_member_vip_and_no_vip_content_throws_404(): void
    {
        $member = $this->createMember($this->agent);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member VIP not found');
        $this->expectExceptionCode(404);

        $this->makeService()->getVipSetting($member->username);
    }

    /**
     * 降級時 VipContent 掛在祖先 agent（非 member 直屬 parent）→ 仍能正確解析。
     *
     * 約 20% 案例 VIP 階梯掛在 hierarchy_path 上的祖先 agent，必須以整條階層為查詢範圍。
     */
    public function test_fallback_resolves_vip_content_on_ancestor_agent(): void
    {
        // 祖先 agent（持有 VIP 階梯）
        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $ancestorAgent = $this->createAgentAndDomain();

        // 直屬 parent agent，hierarchy_path 包含祖先（自身無任何 VipContent）
        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $parentAgent = $this->createAgentAndDomain();
        $parentAgent->hierarchy_path = "/{$ancestorAgent->id}/{$parentAgent->id}";
        $parentAgent->save();

        $member = $this->createMember($parentAgent);

        // VIP 階梯掛在祖先 agent
        $level0 = VipContent::create([
            'agent_id' => $ancestorAgent->id,
            'level' => 0,
            'name' => 'ANCESTOR VIP 0',
            'target_amount' => 0,
            'keep_amount' => 0,
        ]);
        $this->createReward($level0, LivechatBotVipRewardEnum::DAILY_REBATE, '3.0000');

        $next = VipContent::create([
            'agent_id' => $ancestorAgent->id,
            'level' => 1,
            'name' => 'ANCESTOR VIP 1',
            'target_amount' => 10,
            'keep_amount' => 5,
        ]);
        $this->createReward($next, LivechatBotVipRewardEnum::UPGRADE, '88.0000');

        // bot 綁在祖先 agent，才能查到 parent 線下的 member
        $context = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($ancestorAgent->id, self::VIP_URL)));

        $result = $this->makeService($context)->getVipSetting($member->username);

        $this->assertSame($level0->id, $result['id']);
        $this->assertSame('VIP0', $result['vipLevel']);
        $this->assertSame('ANCESTOR VIP 0', $result['name']);
        $this->assertSame('3.0000', $result['vipDailyBonus']);
        $this->assertSame('88.0000', $result['vipUpgradeBonus']);
        $this->assertSame('0.0000 / 10.0000', $result['upgradeProgress']);
    }

    /**
     * Happy path：目前等級 + 下一級存在 + 部分 reward 已配置
     */
    public function test_get_vip_setting_with_next_level_and_partial_rewards(): void
    {
        $member = $this->createMember($this->agent);

        $current = VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 0,
            'name' => 'VIP 0',
            'target_amount' => 0,
            'keep_amount' => 0,
        ]);

        // 部分 rewards：DAILY_REBATE 與 BIRTHDAY 有設定，其他缺
        $this->createReward($current, LivechatBotVipRewardEnum::DAILY_REBATE, '2.0000');
        $this->createReward($current, LivechatBotVipRewardEnum::BIRTHDAY, '1.0000');

        $next = VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 1,
            'name' => 'VIP 1',
            'target_amount' => 3,
            'keep_amount' => 100,
        ]);

        // 下一級的升級獎金
        $this->createReward($next, LivechatBotVipRewardEnum::UPGRADE, '50.0000');

        MemberVip::create([
            'member_id' => $member->id,
            'vip_content_id' => $current->id,
            'deposit_amount' => 0,
            'keep_amount' => 0,
        ]);

        $result = $this->makeService()->getVipSetting($member->username);

        $this->assertSame($current->id, $result['id']);
        $this->assertSame('VIP0', $result['vipLevel']);
        $this->assertSame('VIP 0', $result['name']);
        $this->assertSame('2.0000', $result['vipDailyBonus']);
        $this->assertSame('1.0000', $result['vipBirthdayBonus']);

        // 缺失欄位回 "0.0000"
        $this->assertSame('0.0000', $result['vipMonthlyBonus']);
        $this->assertSame('0.0000', $result['vipWeeklyBonus']);
        $this->assertSame('0.0000', $result['vipHolidayBonus']);
        $this->assertSame('0.0000', $result['vipWeeklyAidBonus']);

        // 規格未提供對應 vip_reward_id，恆為 "0.0000"
        $this->assertSame('0.0000', $result['vipDailyAidBonus']);
        $this->assertSame('0.0000', $result['vipMonthlyAidBonus']);

        $this->assertSame('0.0000', $result['upgradePoints']);
        $this->assertSame('0.0000', $result['maintainPoints']);
        $this->assertSame('50.0000', $result['vipUpgradeBonus']);
        $this->assertSame('0.0000 / 3.0000', $result['upgradeProgress']);
        $this->assertSame(self::VIP_URL, $result['url']);

        $this->assertIsArray($result['message']);
        $this->assertContains('Vip Level: VIP0', $result['message']);
        $this->assertContains('Vip Name: VIP 0', $result['message']);
        $this->assertContains('Vip Daily Bonus: 2.00', $result['message']);
        $this->assertContains('Vip Weekly Bonus: 0.00', $result['message']);
        $this->assertContains('Vip Birthday Bonus: 1.00', $result['message']);
        $this->assertContains('Vip Holiday Bonus: 0.00', $result['message']);
        $this->assertContains('Vip Maintain Points: 0.00', $result['message']);
        $this->assertContains('Next Level Vip Upgrade Bonus: 50.00', $result['message']);
        $this->assertContains('Vip Upgrade Progress: 0.0000 / 3.0000', $result['message']);
        $this->assertContains('Vip Setting Refer URL: ' . self::VIP_URL, $result['message']);
    }

    /**
     * 已最高等級（無下一級）→ vipUpgradeBonus="0.0000"、upgradeProgress="0.0000 / 0.0000"
     */
    public function test_highest_level_has_no_next_level(): void
    {
        $member = $this->createMember($this->agent);

        $current = VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 9,
            'name' => 'VIP 9',
            'target_amount' => 1000000,
            'keep_amount' => 500000,
        ]);

        MemberVip::create([
            'member_id' => $member->id,
            'vip_content_id' => $current->id,
            'deposit_amount' => '999999.5000',
            'keep_amount' => '500000.0000',
        ]);

        $result = $this->makeService()->getVipSetting($member->username);

        $this->assertSame('VIP9', $result['vipLevel']);
        $this->assertSame('0.0000', $result['vipUpgradeBonus']);
        $this->assertSame('0.0000 / 0.0000', $result['upgradeProgress']);
        $this->assertSame('1000000.0000', $result['upgradePoints']);
        $this->assertSame('500000.0000', $result['maintainPoints']);
        $this->assertContains('Next Level Vip Upgrade Bonus: 0.00', $result['message']);
        $this->assertContains('Vip Upgrade Progress: 0.0000 / 0.0000', $result['message']);
    }

    /**
     * 8 種 reward 全配置：所有欄位正確映射
     */
    public function test_all_rewards_configured_returns_all_string_values(): void
    {
        $member = $this->createMember($this->agent);

        $current = VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 3,
            'name' => 'VIP 3',
            'target_amount' => 100,
            'keep_amount' => 50,
        ]);

        $this->createReward($current, LivechatBotVipRewardEnum::DAILY_REBATE, '2.0000');
        $this->createReward($current, LivechatBotVipRewardEnum::WEEKLY_GIFT, '5.0000');
        $this->createReward($current, LivechatBotVipRewardEnum::MONTHLY_GIFT, '10.0000');
        $this->createReward($current, LivechatBotVipRewardEnum::BIRTHDAY, '20.0000');
        $this->createReward($current, LivechatBotVipRewardEnum::COMMEMORATE, '15.0000');
        $this->createReward($current, LivechatBotVipRewardEnum::WEEKLY_RESCUE, '8.0000');

        $next = VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 4,
            'name' => 'VIP 4',
            'target_amount' => 500,
            'keep_amount' => 250,
        ]);
        $this->createReward($next, LivechatBotVipRewardEnum::UPGRADE, '100.0000');

        MemberVip::create([
            'member_id' => $member->id,
            'vip_content_id' => $current->id,
            'deposit_amount' => '120.0000',
            'keep_amount' => '60.0000',
        ]);

        $result = $this->makeService()->getVipSetting($member->username);

        $this->assertSame('2.0000', $result['vipDailyBonus']);
        $this->assertSame('5.0000', $result['vipWeeklyBonus']);
        $this->assertSame('10.0000', $result['vipMonthlyBonus']);
        $this->assertSame('20.0000', $result['vipBirthdayBonus']);
        $this->assertSame('15.0000', $result['vipHolidayBonus']);
        $this->assertSame('8.0000', $result['vipWeeklyAidBonus']);
        $this->assertSame('100.0000', $result['vipUpgradeBonus']);
        $this->assertSame('120.0000 / 500.0000', $result['upgradeProgress']);
        $this->assertSame('100.0000', $result['upgradePoints']);
        $this->assertSame('50.0000', $result['maintainPoints']);

        // 規格未提供對應 vip_reward_id 的兩欄仍為 "0.0000"
        $this->assertSame('0.0000', $result['vipDailyAidBonus']);
        $this->assertSame('0.0000', $result['vipMonthlyAidBonus']);

        // message 以 2 位小數顯示
        $this->assertContains('Vip Daily Bonus: 2.00', $result['message']);
        $this->assertContains('Vip Weekly Bonus: 5.00', $result['message']);
        $this->assertContains('Vip Monthly Bonus: 10.00', $result['message']);
        $this->assertContains('Vip Birthday Bonus: 20.00', $result['message']);
        $this->assertContains('Vip Holiday Bonus: 15.00', $result['message']);
        $this->assertContains('Vip Weekly Aid Bonus: 8.00', $result['message']);
        $this->assertContains('Vip Daily Aid Bonus: 0.00', $result['message']);
        $this->assertContains('Vip Monthly Aid Bonus: 0.00', $result['message']);
    }

    /**
     * 找下一級時必須限制在同 agent_id 範圍。
     *
     * 其他 agent 即使 level 較大，也不應被選為下一級。
     */
    public function test_next_level_is_scoped_by_agent_id(): void
    {
        // 換一組 domain 避免 agent_domains.domain unique 衝突
        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();
        $member = $this->createMember($this->agent);

        $current = VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 1,
            'name' => 'VIP 1',
            'target_amount' => 100,
            'keep_amount' => 50,
        ]);

        // 別家代理的 VIP 不應被當成下一級
        $otherAgentNext = VipContent::create([
            'agent_id' => $otherAgent->id,
            'level' => 2,
            'name' => 'OTHER VIP 2',
            'target_amount' => 999,
            'keep_amount' => 500,
        ]);
        $this->createReward($otherAgentNext, LivechatBotVipRewardEnum::UPGRADE, '999.0000');

        MemberVip::create([
            'member_id' => $member->id,
            'vip_content_id' => $current->id,
            'deposit_amount' => '0.0000',
            'keep_amount' => '0.0000',
        ]);

        $result = $this->makeService()->getVipSetting($member->username);

        // 同 agent 沒有更高 level，因此視為已最高
        $this->assertSame('0.0000', $result['vipUpgradeBonus']);
        $this->assertSame('0.0000 / 0.0000', $result['upgradeProgress']);
    }

    /**
     * 多個更高 level 時取 level ASC 第一筆。
     */
    public function test_next_level_picks_smallest_higher_level(): void
    {
        $member = $this->createMember($this->agent);

        $current = VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 1,
            'name' => 'VIP 1',
            'target_amount' => 100,
            'keep_amount' => 50,
        ]);

        $level3 = VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 3,
            'name' => 'VIP 3',
            'target_amount' => 9999,
            'keep_amount' => 0,
        ]);
        $this->createReward($level3, LivechatBotVipRewardEnum::UPGRADE, '9999.0000');

        $level2 = VipContent::create([
            'agent_id' => $this->agent->id,
            'level' => 2,
            'name' => 'VIP 2',
            'target_amount' => 200,
            'keep_amount' => 0,
        ]);
        $this->createReward($level2, LivechatBotVipRewardEnum::UPGRADE, '50.0000');

        MemberVip::create([
            'member_id' => $member->id,
            'vip_content_id' => $current->id,
            'deposit_amount' => '120.0000',
            'keep_amount' => '0.0000',
        ]);

        $result = $this->makeService()->getVipSetting($member->username);

        // 應取 level=2，而非 level=3
        $this->assertSame('50.0000', $result['vipUpgradeBonus']);
        $this->assertSame('120.0000 / 200.0000', $result['upgradeProgress']);
    }

    /**
     * 跨 agent 存取（bot 綁不同 agent）→ 404
     */
    public function test_member_not_in_bot_agent_hierarchy_throws_404(): void
    {
        $member = $this->createMember($this->agent);

        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();
        $otherContext = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($otherAgent->id, self::VIP_URL)));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        $this->makeService($otherContext)->getVipSetting($member->username);
    }

    private function createReward(VipContent $vipContent, LivechatBotVipRewardEnum $reward, string $point): VipContentReward
    {
        return VipContentReward::create([
            'vip_content_id' => $vipContent->id,
            'vip_reward_id' => $reward->value,
            'point' => $point,
            'status' => 1,
        ]);
    }
}
