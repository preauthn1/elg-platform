<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Data\Lobby\LobbyVendorListData;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\Vendor\VendorMaintainTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\Lobby\GamesByTagRequest;
use App\Http\Requests\Frontend\Lobby\LobbyVendorsRequest;
use App\Http\Resources\Frontend\Lobby\GamesByTagResource;
use App\Http\Resources\Frontend\Lobby\VendorListResource;
use App\Models\AgentGroupTag;
use App\Models\Game;
use App\Models\VendorCustomizedItem;
use App\Services;
use App\Services\EventV2\EventV2Service;
use Dedoc\Scramble\Attributes\Group;

#[Group('Lobby (首頁相關)')]
class LobbyController extends Controller
{
    public function __construct(
        private Services\VendorService $vendorService,
        private Services\AgentGameService $agentGameService,
        private Services\EventService $eventService,
        private Services\FlowLimitService $flowLimitService,
        private Services\NewbieChallengeService $newbieChallengeService,
        private EventV2Service $eventV2Service,
    ) {}

    /**
     * 全部遊戲商
     *
     * OLD: /api/newVendors
     */
    public function vendors(LobbyVendorsRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $device = $userData->deviceType;

        $vendorIds = ($member !== null && $member->is_event_wallet === true) ?
            $this->vendorService->getEventWalletVendorIds($agent) :
            $this->vendorService->getVendorIds($agent);

        // 新人挑戰活動第二階段（挑戰流水）/第四階段（彩金流水）期間，大廳只顯示該階段計算範圍內的遊戲商，
        // 導流玩家玩到有算流水的遊戲
        if ($member !== null) {
            $newbieChallengeVendorIds = $this->newbieChallengeService->getLobbyVendorRestriction($member);
            if ($newbieChallengeVendorIds !== null) {
                $vendorIds = array_intersect($vendorIds, $newbieChallengeVendorIds);
            }
        }

        if (empty($vendorIds)) {
            return ApiResponse::success(VendorListResource::collection([]));
        }

        // 指定的 vendor_ids 必須要 available.
        if (!empty($request->input('vendor_ids'))) {
            $vendorIds = array_intersect($vendorIds, $request->input('vendor_ids'));
        }

        // 遊戲類型
        $typeId = $request->has('type_id') ? $request->input('type_id') : null;

        $agent_game_lists = $this->agentGameService->getGameIdList($agent, $vendorIds);
        $agent_game_lists[] = 0; // ALL game.
        // ! ONLY_FULL_GROUP_BY
        $vendorCustomizedItems = VendorCustomizedItem::query()
            ->with([
                'vendor',
                'vendorMaintenances' => function ($query) use ($agent) {
                    $query->where('status', 1)
                        ->where('start_time', '<=', now())
                        ->where('end_time', '>=', now())
                        ->whereIn('agent_id', $agent->hierarchy_array)
                        ->whereNotIn('exclude_agent_id', $agent->hierarchy_array);
                },
            ])
            ->where('status', 1)
            ->when($typeId !== null, function ($query) use ($typeId) {
                $query->where('type_id', $typeId);
            })
            ->whereIn('vendor_id', $vendorIds)
            ->whereIn('game_id', $agent_game_lists)
            ->where('agent_group_id', $agent->vendor_customize_group_id)
            ->where(function ($q) use ($device) {
                $q->where('vendor_id', 0);
                $q->orWhereHas('vendor', function ($query) use ($device) {
                    // 判斷用戶使用的裝置
                    if ($device == DeviceTypeEnum::PAD) {
                        $query->where('show_on_app', 1);
                    } elseif ($device == DeviceTypeEnum::MOBILE) {
                        $query->where('show_on_mobile', 1);
                    } elseif ($device == DeviceTypeEnum::PC) {
                        $query->where('show_on_pc', 1);
                    }
                });
            })
            ->groupBy(['item_type', 'vendor_id', 'game_id', 'type_id', 'path'])
            ->orderByDesc('sorts')
            ->get();

        $availableVendorInfos = $this->vendorService->getVendorInfos($agent);
        $vendors = $vendorCustomizedItems->map(function (VendorCustomizedItem $item) use ($member, $availableVendorInfos) {
            $vendor = $item->vendor;
            $vendorMaintenance = $item->vendorMaintenances->first();
            $vendorInfo = $availableVendorInfos->where('vendor_id', $item->vendor_id)->first();

            return LobbyVendorListData::from([
                'item_type' => $item->item_type,
                'id' => $item->vendor_id,
                'game_id' => $item->game_id,
                'type_id' => $item->type_id,
                'path' => $item->path,
                'name' => $item->name ?? $vendor?->name,
                'image' => $item->image ?? $vendor?->image,
                'front_page_image' => $item->front_page_image ?? $vendor?->image,
                'app_icon' => $item->app_icon ?? $vendor?->app_icon,
                'is_new' => $item->is_new ?? $vendor?->is_new,
                'is_hot' => $item->is_hot ?? $vendor?->is_hot,
                'is_event' => $item->is_event ?? $vendor?->is_event,
                'only_one_game' => $vendor?->only_one_game,
                'need_login' => $vendor?->need_login,
                'status' => $this->flowLimitService->checkVendorStatus($item, $member),
                'maintenance_end_time' => $vendorMaintenance?->end_time,
                'sorts' => $item->sorts,
                'giftcode' => $vendor?->is_single_wallet && $vendorInfo?->rolling_chip_status ? 1 : 0,
                'currency_name' => $vendorInfo?->currency ?? '',
                'rate' => $vendorInfo?->rate ?? 1.0,
            ]);
        });

        return ApiResponse::success(VendorListResource::collection($vendors));
    }

    /**
     * 最新遊戲/熱門遊戲
     *
     * OLD: /api/getGamesByTagByDomain
     *
     * OLD: /api/getGamesByTag
     */
    public function games(GamesByTagRequest $request)
    {
        $userData = $this->getUserContext();
        $tagName = $request->tag;
        $agent = $userData->agent;
        $member = $userData->member;
        $languageId = $userData->languageId;
        $device = $userData->deviceType;

        $vendorInfoIds = $this->vendorService->getVendorInfoIds($agent);
        $agentGameList = $this->agentGameService->getGameIdList($agent);

        // 工單4126：帶入 member 一併套用會員層級的遊戲商限制
        $maintainVendorIds = $this->vendorService->getMaintenanceVendorIds(
            $agent,
            [VendorMaintainTypeEnum::BOTH_CLOSED, VendorMaintainTypeEnum::GAME_CLOSED],
            null,
            $member
        );
        // 工單4120：遊戲層級維護，遊戲留在列表僅標記為維護中（不像整商維護整批不顯示）
        $maintainGameMap = $this->vendorService->getMaintenanceGameMap($agent, null, $member);

        $agentGroupTagId = AgentGroupTag::where('tag_name', $tagName)->select('id')->first();
        if (!is_object($agentGroupTagId)) {
            throw new ApiException("tag not found, tag: {$tagName}");
        }
        $agentGroupTagId = $agentGroupTagId->id;

        $games = Game::query()
            ->with(
                [
                    'vendor.vendorCustomizedItems' => fn ($q) => $q
                        ->where('status', 1)
                        ->where('agent_group_id', $agent->vendor_customize_group_id),
                    'vendorInfoGames' => fn ($q) => $q
                        ->where('status', 1)
                        ->whereIn('vendor_info_id', $vendorInfoIds)
                        ->select('id', 'game_id', 'is_new', 'is_hot', 'join_bet_event', 'join_win_event'),
                    'gameNames' => fn ($q) => $q->where('language_id', $languageId),
                    'agentGroupGameTags' => fn ($q) => $q
                        ->where('agent_group_id', $agent->vendor_customize_group_id)
                        ->where('agent_group_tag_id', $agentGroupTagId)
                        ->select('id', 'game_id', 'sort'),
                ])
            ->join('agent_group_game_tags', function ($join) use ($agent, $agentGroupTagId) {
                $join->on('games.id', '=', 'agent_group_game_tags.game_id')
                    ->where('agent_group_game_tags.agent_group_id', $agent->vendor_customize_group_id)
                    ->where('agent_group_game_tags.agent_group_tag_id', $agentGroupTagId);
            })
            ->where('games.status', 1)
            ->whereIn('games.id', $agentGameList)
            ->whereNotIn('games.vendor_id', $maintainVendorIds)
            ->whereHas('vendorInfoGames', fn ($q) => $q
                ->where('status', 1)
                ->whereIn('vendor_info_id', $vendorInfoIds)
            )
            ->whereHas('vendor', function ($query) use ($device) {
                $query->where('status', 1);

                if ($device == DeviceTypeEnum::MOBILE) {
                    $query->where('show_on_mobile', 1);
                } else {
                    $query->where('show_on_pc', 1);
                }
            })
            ->orderByDesc('agent_group_game_tags.sort')
            ->orderByDesc('games.id')
            ->select([
                'games.id',
                'games.vendor_id',
                'games.type_id',
                'games.rtp',
                'games.name',
                'games.lottery_pool',
                'games.image',
                'agent_group_game_tags.sort',
            ])
            ->get();

        // 這裡不檢查各遊戲是否在活動（event）中：它依賴的設定都沒有開，暫不實作，是否需要補上仍待確認。
        // 新人專享挑戰與 EventV2 的遊戲限制在下方另外判斷。

        foreach ($games as $game) {
            // 新人專享挑戰活動、EventV2 的遊戲商/遊戲類型限制都走各自獨立的判斷（各自service的
            // isGameDisabled()），不動 FlowLimitService，三邊都可能讓遊戲灰化，OR 起來合併
            $game->disable = (int) (
                $this->flowLimitService->isGameDisabled($game, $member)
                || $this->newbieChallengeService->isGameDisabled($game, $member)
                || $this->eventV2Service->isGameDisabled($game, $member)
            );
            // 工單4120：維護中的遊戲仍回傳，由前台灰化並顯示恢復時間
            $game->is_maintenance = array_key_exists($game->id, $maintainGameMap) ? 1 : 0; // 工單4126:會員限制的 end_time 為 null,isset 會誤判為不存在
            $game->maintenance_end_time = $maintainGameMap[$game->id] ?? null;
        }

        return ApiResponse::success(GamesByTagResource::collection($games));
    }
}
