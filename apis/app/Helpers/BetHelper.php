<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\MemberStatusEnum;
use App\Enums\Vendor\VendorMaintainTypeEnum;
use App\Exceptions\BetException;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\PrizeContentFlowLimit;
use App\Models\VendorCustomizedItem;
use App\Models\VendorInfo;
use App\Services\AgentGameService;
use App\Services\AgentService;
use App\Services\EventService;
use App\Services\VendorService;
use App\Vendors\Models\AbstractAccount;
use Illuminate\Support\Facades\Log;

class BetHelper
{
    /**
     * 檢查玩家是否可以下注，盡量僅用在單錢包遊戲投注時
     */
    public static function isMemberAbleToBet(Member $member, VendorInfo $vendorInfo, Game|string $game, ?object $caller = null, ?AbstractAccount $account = null): bool
    {
        try {
            self::assertMemberAbleToBet($member, $vendorInfo, $game, $caller, $account);

            return true;
        } catch (BetException $e) {
            Log::warning('[BetHelper][isMemberAbleToBet] ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 驗證玩家是否可以下注，驗證失敗時拋出 BetException
     *
     * 有傳 $account 時連遊戲商帳號的 status 一起驗：status 0 代表帳號被活動系統注銷，不得再下注。
     * 這道只擋下注，不放在帳號解析層：停用後仍要能結算、取消、查餘額，否則已扣的注金結不了。
     *
     * @throws BetException
     */
    public static function assertMemberAbleToBet(Member $member, VendorInfo $vendorInfo, Game|string $game, ?object $caller = null, ?AbstractAccount $account = null): void
    {
        $singleGame = $vendorInfo->info['singleGame'] ?? 0;

        // 遊戲商帳號被注銷
        if ($account !== null && (int) $account->status === 0) {
            throw new BetException('Account is disabled');
        }

        // 玩家被鎖定
        if ($member->status !== MemberStatusEnum::VERIFIED->value || $member->sys_lock === 1) {
            throw new BetException('Member is locked or inactive');
        }

        // 商戶不可用
        if ($vendorInfo->status !== 1) {
            throw new BetException('Vendor is not available');
        }

        // 遊戲不可用
        if (is_string($game)) {
            $game = Game::where('vendor_id', $vendorInfo->vendor_id)
                ->where('game_type', $game)
                ->first();
            if (!is_object($game)) {
                throw new BetException('Game not found');
            }
        }

        // 遊戲商不可用
        if ($vendorInfo->vendor->status !== 1) {
            throw new BetException('Vendor is not available');
        }

        // 遊戲商維護中，不論關閉遊戲或關閉錢包，一律禁止投注
        $isVendorMaintain = $vendorInfo->vendor->vendorLastMaintenance()
            ->whereIn('type', [
                VendorMaintainTypeEnum::BOTH_CLOSED->value,
                VendorMaintainTypeEnum::GAME_CLOSED->value,
                VendorMaintainTypeEnum::WALLET_CLOSED->value,
            ])
            ->whereIn('agent_id', $member->agent->hierarchy_array)
            ->whereNotIn('exclude_agent_id', $member->agent->hierarchy_array)
            ->exists();
        if ($isVendorMaintain) {
            throw new BetException('Vendor is under maintenance');
        }

        // 代理鏈上任一個代理維護中，拒絕下注
        if (app(AgentService::class)->getMaintainAgents()->whereIn('agent_id', $member->agent->hierarchy_array)->isNotEmpty()) {
            throw new BetException('Agent is under maintenance');
        }

        // 遊戲可投注性：僅當 caller 實作 ShouldVerifyGameBettability 時才驗證
        if ($caller instanceof ShouldVerifyGameBettability) {
            $isGameBettable = $vendorInfo
                ->vendorInfoGames()
                ->where('game_id', $game->id)
                ->where('bettable', 1)
                ->exists();

            if (!$isGameBettable) {
                throw new BetException('Game is not bettable');
            }
        } else {
            self::assertRegularGameAvailability($member, $vendorInfo, $game, $singleGame);
        }

        // 當前代理遊戲商在代理鏈是否允許使用
        $vendorInfoIds = (new VendorService)->setIsEventWallet($member->is_event_wallet)->getVendorInfoIds(
            agent: $member->agent,
            vendorId: $vendorInfo->vendor_id,
        );
        if (!in_array($vendorInfo->id, $vendorInfoIds)) {
            throw new BetException('Vendor info is not available');
        }

        // 代理線上有人被鎖住
        $agentIds = $member->agent->hierarchy_array;
        $lockAgentsExists = Agent::whereIn('id', $agentIds)->where('status', '!=', 1)->exists();
        if ($lockAgentsExists) {
            throw new BetException('Agent is locked');
        }

        // 最後檢查鎖分模式
        if (!self::isMemberFlowLimitAllowed($member, $vendorInfo->vendor_id, $game)) {
            throw new BetException('Member is not allowed by flow limit');
        }

        // 有未領取的活動獎勵，禁止下注
        if (self::hasUnclaimedPrize($member)) {
            throw new BetException('Member has unclaimed prize');
        }
    }

    /**
     * @throws BetException
     */
    private static function assertRegularGameAvailability(Member $member, VendorInfo $vendorInfo, Game $game, int $singleGame): void
    {
        // 大廳類遊戲，status會設定為0，所以不驗證
        if ($game->status !== 1 && $singleGame !== 1) {
            throw new BetException('Game not available');
        }

        // 商戶遊戲設置不對，event_v2商戶不會配置遊戲跳過此驗證，大廳類遊戲不會配置遊戲跳過此驗證
        if ($member->is_event_wallet === false || $singleGame !== 1) {
            $isVendorInfoGameAvailable = $vendorInfo
                ->vendorInfoGames()
                ->where('game_id', $game->id)
                ->where('status', 1)
                ->exists();
            if (!$isVendorInfoGameAvailable) {
                throw new BetException('Vendor info game is not available');
            }
        }

        // 大廳類遊戲不會配置遊戲跳過此驗證
        if ($singleGame !== 1) {
            // 代理遊戲設置不對
            $agentGames = (new AgentGameService)->getValidAgentGames(
                agent: $member->agent,
                gameId: $game->id,
            );
            if ($agentGames->count() === 0) {
                throw new BetException('Agent game is not available');
            }
        }
    }

    /**
     * 檢查玩家是否有未領取的活動獎勵（V1 事件系統）
     * 僅在代理群組開啟 PRIZE_REMIND 設置時才進行檢查
     */
    public static function hasUnclaimedPrize(Member $member): bool
    {
        $agentSettings = app(AgentService::class)->getAgentSettings($member->agent);
        if (($agentSettings[AgentCustomizedSettingKeyEnum::PRIZE_REMIND] ?? 0) != 1) {
            return false;
        }

        $agentIds = $member->agent->hierarchy_array;
        $eventService = app(EventService::class);

        $detailIds = $eventService->findAvailableEventDetailIds($agentIds);
        if (empty($detailIds)) {
            return false;
        }

        return $eventService->getPrizeCount($detailIds, $member->id) > 0;
    }

    /**
     * 檢查玩家是否在鎖分模式內，且是否可以下注
     */
    public static function isMemberFlowLimitAllowed(Member $member, int $vendorId, ?Game $game = null): bool
    {
        if ($member->status != MemberStatusEnum::VERIFIED->value) {
            return false;
        }

        if ($member->event_mode <= 0) {
            return true;
        }

        $prizeContentFlowLimit = PrizeContentFlowLimit::query()
            ->where('prize_content_id', $member->event_mode)
            ->get();

        if ($prizeContentFlowLimit->count() === 0) {
            return true;
        }

        $checkMap = [];
        foreach ($prizeContentFlowLimit as $item) {
            $checkMap[$item->vendor_id][$item->game_type_id] = true;
        }

        if (is_null($game)) {
            // 如果game為null, 則表示是轉帳使用，直接查詢遊戲商群組的設置來判斷這個遊戲商屬於哪種類別
            $vendorCustomizedItems = VendorCustomizedItem::where('vendor_id', $vendorId)
                ->where('agent_group_id', $member->agent->vendor_customize_group_id)
                ->orderBy('type_id', 'asc')
                ->get();

            $game = $vendorCustomizedItems->first();
            if ((isset($checkMap[$vendorId]) || isset($checkMap[0])) && $game->type_id == 0) {
                // 如果遊戲商群組的設置的類別為0，直接讓他轉入
                return true;
            }

            if ($vendorCustomizedItems->count() > 1) {
                foreach ($vendorCustomizedItems as $item) {
                    if (
                        isset($checkMap[$vendorId][$item->type_id])
                        || isset($checkMap[0][$item->type_id])
                    ) {
                        return true;
                    }
                }
            }
        }

        if (
            isset($checkMap[$vendorId][$game->type_id])
            || isset($checkMap[0][$game->type_id])
            || isset($checkMap[$vendorId][0])
            || isset($checkMap[0][0]) // 照理說不該存在這種情況, 但為了避免出錯, 還是加上
        ) {
            return true;
        }

        return false;
    }
}
