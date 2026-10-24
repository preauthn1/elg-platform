<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Enums\Agent\AgentPersonalSettingKeyEnum;
use App\Enums\MemberStatusEnum;
use App\Enums\Vendor\VendorInfoTypeEnum;
use App\Enums\Vendor\VendorMaintainTypeEnum;
use App\Helpers\BetHelper;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\EventV2UserJoin;
use App\Models\Game;
use App\Models\Member;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Models\VendorInfoGame;
use App\Models\VendorMaintenance;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * BetHelper::isMemberAbleToBet 整合測試
 *
 * php artisan test tests/Unit/Helpers/BetHelperIsMemberAbleToBetTest.php
 */
class BetHelperIsMemberAbleToBetTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private Vendor $vendor;

    private ?VendorInfo $vendorInfo;

    private ?Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->agent = $this->createAgentAndDomain();
        $this->vendor = $this->createBasicVendorGames($this->agent);
        $this->member = $this->createMember($this->agent, [
            'status' => MemberStatusEnum::VERIFIED->value,
            'sys_lock' => 0,
            'event_mode' => 0,
        ]);

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->game = $this->vendor->games()->first();
    }

    // ── 正常路徑 ─────────────────────────────────────────────────────────────

    #[TestDox('全部條件滿足時，玩家可以下注')]
    public function test_returns_true_when_all_conditions_are_met(): void
    {
        $caller = new class implements ShouldVerifyGameBettability {};

        $this->assertTrue(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game, $caller)
        );
    }

    // ── 玩家狀態 ─────────────────────────────────────────────────────────────

    #[TestDox('玩家狀態為未認證（UNVERIFIED）時，拒絕下注')]
    public function test_returns_false_when_member_status_is_not_verified(): void
    {
        $this->member->status = MemberStatusEnum::UNVERIFIED->value;

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    #[TestDox('玩家狀態為鎖定（LOCKED）時，拒絕下注')]
    public function test_returns_false_when_member_is_locked(): void
    {
        $this->member->status = MemberStatusEnum::LOCKED->value;

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    #[TestDox('玩家被系統鎖定（sys_lock=1）時，拒絕下注')]
    public function test_returns_false_when_member_sys_lock_is_enabled(): void
    {
        $this->member->sys_lock = 1;

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    // ── 商戶狀態 ─────────────────────────────────────────────────────────────

    #[TestDox('遊戲商商戶（vendor_info）停用時，拒絕下注')]
    public function test_returns_false_when_vendor_info_status_is_disabled(): void
    {
        $this->vendorInfo->status = 0;

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    // ── 遊戲字串查找 ──────────────────────────────────────────────────────────

    #[TestDox('傳入字串 game_type 但 DB 無對應遊戲時，拒絕下注')]
    public function test_returns_false_when_game_string_not_found_in_db(): void
    {
        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, 'NONEXISTENT_GAME_TYPE')
        );
    }

    #[TestDox('傳入字串 game_type 且 DB 有對應遊戲時，正常通過驗證')]
    public function test_returns_true_when_game_passed_as_string_and_found(): void
    {
        $this->assertTrue(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game->game_type)
        );
    }

    // ── 遊戲商狀態 ──────────────────────────────────────────────────────────

    #[TestDox('遊戲商（vendor）停用時，拒絕下注')]
    public function test_returns_false_when_vendor_status_is_disabled(): void
    {
        $this->vendor->update(['status' => 0]);

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    // ── 遊戲商維護 ──────────────────────────────────────────────────────────

    #[TestDox('遊戲商進入 BOTH_CLOSED 維護且代理在維護範圍內時，拒絕下注')]
    public function test_returns_false_when_vendor_is_under_maintenance(): void
    {
        VendorMaintenance::create([
            'vendor_id' => $this->vendor->id,
            'type' => VendorMaintainTypeEnum::BOTH_CLOSED->value,
            'start_time' => now()->subHour(),
            'end_time' => now()->addHour(),
            'agent_id' => $this->agent->id,
            'exclude_agent_id' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    #[TestDox('維護類型為 GAME_CLOSED（關閉遊戲）時，拒絕下注')]
    public function test_returns_false_when_maintenance_type_is_game_closed(): void
    {
        VendorMaintenance::create([
            'vendor_id' => $this->vendor->id,
            'type' => VendorMaintainTypeEnum::GAME_CLOSED->value,
            'start_time' => now()->subHour(),
            'end_time' => now()->addHour(),
            'agent_id' => $this->agent->id,
            'exclude_agent_id' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    #[TestDox('維護類型為 WALLET_CLOSED（關閉錢包）時，拒絕下注')]
    public function test_returns_false_when_maintenance_type_is_wallet_closed(): void
    {
        VendorMaintenance::create([
            'vendor_id' => $this->vendor->id,
            'type' => VendorMaintainTypeEnum::WALLET_CLOSED->value,
            'start_time' => now()->subHour(),
            'end_time' => now()->addHour(),
            'agent_id' => $this->agent->id,
            'exclude_agent_id' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    #[TestDox('維護中但代理在排除名單（exclude_agent_id）時，不影響下注')]
    public function test_returns_true_when_agent_is_excluded_from_maintenance(): void
    {
        VendorMaintenance::create([
            'vendor_id' => $this->vendor->id,
            'type' => VendorMaintainTypeEnum::WALLET_CLOSED->value,
            'start_time' => now()->subHour(),
            'end_time' => now()->addHour(),
            'agent_id' => $this->agent->id,
            'exclude_agent_id' => $this->agent->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $this->assertTrue(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    // ── caller 未實作介面（else 分支）：game.status / vendorInfoGames.status ──

    #[TestDox('caller 未實作介面，遊戲停用 (可能是大廳遊戲) 且非 singleGame 模式時，拒絕下注')]
    public function test_returns_false_when_game_status_is_disabled_and_not_single_game(): void
    {
        $this->game->update(['status' => 0]);

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game->fresh())
        );
    }

    #[TestDox('caller 未實作介面，singleGame=1（大廳類遊戲）時，game.status 不驗證，仍允許下注')]
    public function test_returns_true_when_game_status_is_disabled_but_single_game_mode(): void
    {
        $this->vendorInfo->update(['info' => ['singleGame' => 1]]);
        $this->game->update(['status' => 0]);

        $this->assertTrue(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo->fresh(), $this->game->fresh())
        );
    }

    #[TestDox('caller 未實作介面，商戶遊戲（vendor_info_games）停用時，拒絕下注')]
    public function test_returns_false_when_vendor_info_game_is_disabled(): void
    {
        VendorInfoGame::where('game_id', $this->game->id)->update(['status' => 0]);

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    #[TestDox('caller 未實作介面，is_event_v2=true 但非 singleGame 模式時，vendorInfoGames 仍驗證，停用則拒絕下注')]
    public function test_returns_false_when_event_v2_member_with_vendor_info_game_disabled_and_not_single_game(): void
    {
        $this->member->setRelation('eventV2Joins', collect([new EventV2UserJoin]));
        VendorInfoGame::where('game_id', $this->game->id)->update(['status' => 0]);

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    #[TestDox('caller 未實作介面，is_event_v2=true 且 singleGame=1 時，vendorInfoGames 驗證略過，停用仍允許下注')]
    public function test_returns_true_when_event_v2_and_single_game_skips_vendor_info_game_check(): void
    {
        $this->vendorInfo->update([
            'type' => VendorInfoTypeEnum::EVENT_V2->value,
            'info' => ['singleGame' => 1],
        ]);
        $this->member->setRelation('eventV2Joins', collect([new EventV2UserJoin]));
        VendorInfoGame::where('game_id', $this->game->id)->update(['status' => 0]);

        $this->assertTrue(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo->fresh(), $this->game)
        );
    }

    // ── caller 實作介面（if 分支）：bettable ────────────────────────────────

    #[TestDox('caller 實作介面，bettable=0 時，拒絕下注')]
    public function test_returns_false_when_caller_implements_interface_and_game_is_not_bettable(): void
    {
        VendorInfoGame::where('game_id', $this->game->id)->update(['bettable' => 0]);

        $caller = new class implements ShouldVerifyGameBettability {};

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game, $caller)
        );
    }

    #[TestDox('caller 實作介面，game.status=0 不驗證，bettable=1 仍允許下注')]
    public function test_returns_true_when_caller_implements_interface_and_game_is_disabled(): void
    {
        $this->game->update(['status' => 0]);

        $caller = new class implements ShouldVerifyGameBettability {};

        $this->assertTrue(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game->fresh(), $caller)
        );
    }

    #[TestDox('caller 實作介面，vendorInfoGames.status=0 不驗證，bettable=1 仍允許下注')]
    public function test_returns_true_when_caller_implements_interface_and_vendor_info_game_is_disabled(): void
    {
        VendorInfoGame::where('game_id', $this->game->id)->update(['status' => 0]);

        $caller = new class implements ShouldVerifyGameBettability {};

        $this->assertTrue(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game, $caller)
        );
    }

    #[TestDox('caller 實作介面，singleGame=0 時 vendorInfoGames.status 不驗證，bettable=1 仍允許下注')]
    public function test_returns_true_when_caller_implements_interface_and_single_game_zero_and_vendor_info_game_is_disabled(): void
    {
        $this->vendorInfo->update(['info' => ['singleGame' => 0]]);
        VendorInfoGame::where('game_id', $this->game->id)->update(['status' => 0]);

        $caller = new class implements ShouldVerifyGameBettability {};

        $this->assertTrue(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo->fresh(), $this->game, $caller)
        );
    }

    #[TestDox('caller 未實作介面時，bettable=0 不影響結果')]
    public function test_returns_true_when_caller_does_not_implement_interface_even_if_not_bettable(): void
    {
        VendorInfoGame::where('game_id', $this->game->id)->update(['bettable' => 0]);

        $this->assertTrue(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    // ── 代理維護 ────────────────────────────────────────────────────────────

    #[TestDox('代理維護中時，拒絕下注')]
    public function test_returns_false_when_agent_is_under_maintenance(): void
    {
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG, '1');
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_START_TIME, gmdate('Y-m-d H:i:s', strtotime('-1 hour')));
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_END_TIME, gmdate('Y-m-d H:i:s', strtotime('+1 hour')));

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    #[TestDox('代理維護已結束時，不影響下注')]
    public function test_returns_true_when_agent_maintenance_has_ended(): void
    {
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG, '1');
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_START_TIME, gmdate('Y-m-d H:i:s', strtotime('-2 hours')));
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_END_TIME, gmdate('Y-m-d H:i:s', strtotime('-1 hour')));

        $this->assertTrue(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    // ── VendorInfoIds 設置 ───────────────────────────────────────────────────

    #[TestDox('AgentVendor 停用後 VendorService 不回傳此 vendorInfo 時，拒絕下注')]
    public function test_returns_false_when_vendor_info_not_in_vendor_info_ids(): void
    {
        AgentVendor::where('vendor_info_id', $this->vendorInfo->id)
            ->where('agent_id', $this->agent->id)
            ->update(['status' => 0, 'self_status' => 0]);

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    // ── 代理線狀態 ──────────────────────────────────────────────────────────

    #[TestDox('代理鏈中有代理被鎖定（status≠1）時，拒絕下注')]
    public function test_returns_false_when_agent_in_hierarchy_is_locked(): void
    {
        $this->agent->update(['status' => 2]);

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    // ── AgentGames ───────────────────────────────────────────────────────────

    #[TestDox('代理未配置此遊戲（agent_games 全部停用）時，拒絕下注')]
    public function test_returns_false_when_no_agent_game_configured(): void
    {
        $this->game->agentGames()
            ->where('agent_id', $this->agent->id)
            ->update(['status' => 0]);

        $this->assertFalse(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $this->game)
        );
    }

    #[TestDox('singleGame=1 時 agentGames 驗證被略過，agentGames 停用仍允許下注')]
    public function test_returns_true_when_single_game_mode_skips_agent_games_check(): void
    {
        $this->vendorInfo->update(['info' => ['singleGame' => 1]]);
        $this->game->agentGames()
            ->where('agent_id', $this->agent->id)
            ->update(['status' => 0]);

        $this->assertTrue(
            BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo->fresh(), $this->game)
        );
    }
}
