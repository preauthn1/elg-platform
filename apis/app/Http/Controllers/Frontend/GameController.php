<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Data\VendorBonusCodeRequestData;
use App\Data\VendorGameUrlParamsData;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\General\CacheTtl;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Enums\Vendor\VendorMaintainTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\BetHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\Game\GetUrlRequest;
use App\Http\Requests\Frontend\Game\ListByTypesRequest;
use App\Http\Requests\Frontend\Game\ListRequest;
use App\Http\Requests\Frontend\Game\SetFavouriteGameRequest;
use App\Http\Requests\Frontend\Game\ShowRequest;
use App\Http\Resources\Frontend\Game\ListResource;
use App\Http\Resources\Frontend\Game\ShowResource;
use App\Http\Resources\Frontend\Vendor\GetBonusCodeResource;
use App\Http\Resources\Frontend\Vendor\GetGameUrlResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Models\AgentDomain;
use App\Models\EventV2UserJoin;
use App\Models\FavouriteGame;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberLaunchLog;
use App\Services\AgentGameService;
use App\Services\AgentService;
use App\Services\EventService;
use App\Services\EventV2\EventV2Service;
use App\Services\FlowLimitService;
use App\Services\GameService;
use App\Services\NewbieChallengeService;
use App\Services\VendorService;
use App\Vendors\Contracts\BonusCodeInterface;
use App\Vendors\Contracts\WidgetGameInterface;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Group('Game (遊戲)')]
class GameController extends Controller
{
    public function __construct(
        private VendorService $vendorService,
        private AgentGameService $agentGameService,
        private EventService $eventService,
        private FlowLimitService $flowLimitService,
        private AgentService $agentService,
        private GameService $gameService,
        private NewbieChallengeService $newbieChallengeService,
        private EventV2Service $eventV2Service,
    ) {}

    /**
     * 遊戲列表
     *
     * OLD: /api/myGames
     */
    public function list(ListRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $languageId = $userData->languageId;
        $device = $userData->deviceType;

        $vendorIds = $request->input('vendor_id', []);
        if (!is_array($vendorIds)) {
            $vendorIds = $vendorIds !== null ? [$vendorIds] : [];
        }
        $vendorIds = array_map('intval', $vendorIds);
        $typeIds = $request->input('type_ids', []);
        if (!is_array($typeIds)) {
            $typeIds = [$typeIds];
        }
        $gameName = $request->input('game_name');

        // 工單4126：帶入 member 一併套用會員層級的遊戲商／遊戲限制（永久，無恢復時間）
        $maintainVendors = $this->vendorService->getMaintenanceVendorIds($agent, [VendorMaintainTypeEnum::BOTH_CLOSED, VendorMaintainTypeEnum::GAME_CLOSED], null, $member);
        // 工單4120：遊戲層級維護。與整商維護不同——遊戲**留在列表**只標記為維護中，
        // 前台據此灰化並顯示恢復時間（整商維護則是整批不顯示）
        $maintainGameMap = $this->vendorService->getMaintenanceGameMap($agent, null, $member);
        $vendorInfoIds = $this->vendorService->setIsEventWallet($member?->isEventWallet ?? false)->getVendorInfoIds($agent, $vendorIds);
        $agentGameIds = $this->agentGameService->getGameIdList($agent, !empty($vendorIds) ? $vendorIds : null);

        // TODO: 和 LobbyController::games 很像，或許可以合併，條件略微不同
        $games = Game::query()
            ->when(!empty($typeIds), fn ($q) => $q->whereIn('type_id', $typeIds))
            ->where('status', 1)
            ->whereIn('id', $agentGameIds)
            ->when(!empty($vendorIds), fn ($q) => $q->whereIn('vendor_id', $vendorIds))
            ->when(!empty($maintainVendors), fn ($q) => $q->whereNotIn('vendor_id', $maintainVendors))
            ->when(in_array(38401, $agent->hierarchy_array), fn ($q) => $q
                ->where('game_type', '!=', 'TASSPTA') // 寮國(總代編號38401)不提供AG體育(vendor:1，game_type:TASSPTA)
            )
            ->when(!empty($gameName), fn ($q) => $q->where(function ($q) use ($gameName, $languageId) {
                $q->where('name', 'like', '%' . $gameName . '%')
                    ->orWhereHas('gameNames', fn ($q) => $q
                        ->where('language_id', $languageId)
                        ->where('name', 'like', '%' . $gameName . '%')
                    );
            }))
            ->whereHas('vendorInfoGames', fn ($q) => $q
                ->where('status', 1)
                ->whereIn('vendor_info_id', $vendorInfoIds)
            )
            ->whereHas('vendor', fn ($q) => $q
                ->where('status', '>', 0)
                ->when($device === DeviceTypeEnum::MOBILE, fn ($q) => $q->where('show_on_mobile', 1))
                ->when($device === DeviceTypeEnum::PC, fn ($q) => $q->where('show_on_pc', 1))
            )
            ->with([
                'vendor' => fn ($q) => $q->select(['id', 'status', 'name', 'image']),
                'vendorInfoGames' => fn ($q) => $q
                    ->where('status', 1)
                    ->whereIn('vendor_info_id', $vendorInfoIds)
                    ->select('id', 'game_id', 'is_new', 'is_hot', 'join_bet_event', 'join_win_event'),
                'vendor.vendorCustomizedItems' => fn ($q) => $q
                    ->where('status', 1)
                    ->where('agent_group_id', $agent->vendor_customize_group_id)
                    ->select('id', 'vendor_id', 'name'),
                'gameNames' => fn ($q) => $q->where('language_id', $languageId),
            ])
            ->select(
                'id',
                'vendor_id',
                'type_id',
                'lottery_pool',
                'name',
                'image'
            )
            ->leftJoinSub(
                DB::table('vendor_info_games')
                    ->whereIn('vendor_info_id', $vendorInfoIds)
                    ->where('status', 1)
                    ->select('game_id', 'sort'),
                'vig',
                'games.id',
                '=',
                'vig.game_id'
            )
            ->orderByDesc('vig.sort')
            ->orderBy('games.id', 'desc')
            ->paginate($request->input('per_page', 15));

        // 不檢查 personal 參數：前端沒有在用
        if (!empty($member)) {
            $games->load(['favouriteGames' => fn ($q) => $q
                ->where('member_id', $member->id)
                ->where('status', 1),
            ]);
        }

        foreach ($games as $game) {
            // 新人專享挑戰活動、EventV2 的遊戲商/遊戲類型限制都走各自獨立的判斷（不動 FlowLimitService，
            // 見 app/Services/NewbieChallengeService.php、EventV2Service.php 的 isGameDisabled()）
            $game->disable = (int) (
                $this->flowLimitService->isGameDisabled($game, $member)
                || $this->newbieChallengeService->isGameDisabled($game, $member)
                || $this->eventV2Service->isGameDisabled($game, $member)
            );
            // 工單4120：維護中的遊戲仍回傳，由前台灰化並顯示恢復時間
            $game->is_maintenance = array_key_exists($game->id, $maintainGameMap) ? 1 : 0; // 工單4126:會員限制的 end_time 為 null,isset 會誤判為不存在
            $game->maintenance_end_time = $maintainGameMap[$game->id] ?? null;
        }

        // NPT跳轉用
        $agentIdString = implode(',', $agent->hierarchy_array);
        $agentDomain = Cache::remember(
            key: CacheKeys::gameListRedirectUrl($agent->id),
            ttl: CacheTtl::ONE_DAY->value,
            callback: fn () => AgentDomain::where('status', 1)
                ->where('type', 2) // 2是遊戲商中轉網址跳轉專用
                ->whereIn('agent_id', $agent->hierarchy_array)
                ->orderByDesc('type')
                ->orderByRaw("FIELD(`agent_id`,{$agentIdString})")
                ->orderByDesc('sort')
                ->orderBy('id', 'ASC')
                ->select('id', 'domain', 'type')
                ->first()
        );

        return ApiResponse::success([
            'games' => ListResource::collection($games),
            'redirect_url' => $agentDomain->domain ?? '',
            'pagination' => OnlyPaginateResource::make($games),
        ]);
    }

    /**
     * 按遊戲類型獲取遊戲列表
     * 支援同時獲取多個遊戲類型，每個類型返回指定數量
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * 請求參數範例：
     * {
     *   "domain": "xxx",
     *   "type_counts": {
     *     "1": 10,
     *     "2": 10,
     *     "3": 10
     *   },
     *   "vendor_ids": [1, 2, 3]
     * }
     */
    public function listByTypes(ListByTypesRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $languageId = $userData->languageId;
        $device = $userData->deviceType;

        $typeCountsParam = $request->input('type_counts', []);
        $vendorIds = $request->input('vendor_ids', []);
        if (!is_array($vendorIds)) {
            $vendorIds = $vendorIds ? [$vendorIds] : [];
        }

        $typeCounts = [];
        foreach ($typeCountsParam as $typeId => $count) {
            $typeCounts[$typeId] = (int) $count;
        }

        // 工單4126：帶入 member 一併套用會員層級限制
        $maintainVendors = $this->vendorService->getMaintenanceVendorIds($agent, [VendorMaintainTypeEnum::BOTH_CLOSED, VendorMaintainTypeEnum::GAME_CLOSED], null, $member);
        // 工單4120：遊戲層級維護，遊戲留在列表僅標記（見 list 的說明）
        $maintainGameMap = $this->vendorService->getMaintenanceGameMap($agent, null, $member);
        $vendorInfoIds = $this->vendorService->getVendorInfoIds($agent, !empty($vendorIds) ? reset($vendorIds) : null);
        $agentGameIds = $this->agentGameService->getGameIdList($agent, !empty($vendorIds) ? $vendorIds : null);

        $subQuery = Game::query()
            ->whereIn('type_id', array_keys($typeCounts))
            ->where('status', 1)
            ->whereIn('id', $agentGameIds)
            ->when(!empty($vendorIds), fn ($q) => $q->whereIn('vendor_id', $vendorIds))
            ->when(!empty($maintainVendors), fn ($q) => $q->whereNotIn('vendor_id', $maintainVendors))
            ->when(in_array(38401, $agent->hierarchy_array), fn ($q) => $q
                ->where('game_type', '!=', 'TASSPTA') // 寮國(總代編號38401)不提供AG體育(vendor:1，game_type:TASSPTA)
            )
            ->whereHas('vendorInfoGames', fn ($q) => $q
                ->where('status', 1)
                ->whereIn('vendor_info_id', $vendorInfoIds)
            )
            ->whereHas('vendor', fn ($q) => $q
                ->where('status', '>', 0)
                ->when($device === DeviceTypeEnum::MOBILE, fn ($q) => $q->where('show_on_mobile', 1))
                ->when($device === DeviceTypeEnum::PC, fn ($q) => $q->where('show_on_pc', 1))
            )
            ->selectRaw('*, ROW_NUMBER() OVER (PARTITION BY type_id ORDER BY sort DESC) as rn')
            ->toBase();

        $games = DB::query()
            ->fromSub($subQuery, 'ranked_games');

        $caseConditions = [];
        $bindings = [];
        foreach ($typeCounts as $typeId => $count) {
            $caseConditions[] = 'WHEN type_id = ? THEN rn <= ?';
            $bindings[] = (int) $typeId;
            $bindings[] = (int) $count;
        }

        $games = $games
            ->whereRaw('CASE ' . implode(' ', $caseConditions) . ' ELSE 0 END', $bindings)
            ->select(
                'id',
                'vendor_id',
                'type_id',
                'lottery_pool',
                'name',
                'image'
            )
            ->orderByDesc('sort')
            ->get();

        $gameIds = $games->pluck('id')->toArray();
        $games = Game::query()
            ->whereIn('id', $gameIds)
            ->with([
                'vendor' => fn ($q) => $q->select(['id', 'status', 'name', 'image']),
                'vendorInfoGames' => fn ($q) => $q
                    ->where('status', 1)
                    ->whereIn('vendor_info_id', $vendorInfoIds)
                    ->select('id', 'game_id', 'is_new', 'is_hot', 'join_bet_event', 'join_win_event'),
                'vendor.vendorCustomizedItems' => fn ($q) => $q
                    ->where('status', 1)
                    ->where('agent_group_id', $agent->vendor_customize_group_id)
                    ->select('id', 'vendor_id', 'name'),
                'gameNames' => fn ($q) => $q->where('language_id', $languageId),
            ])
            ->leftJoinSub(
                DB::table('vendor_info_games')
                    ->whereIn('vendor_info_id', $vendorInfoIds)
                    ->where('status', 1)
                    ->select('game_id', 'sort'),
                'vig',
                'games.id',
                '=',
                'vig.game_id'
            )
            ->orderByDesc('vig.sort')
            ->orderBy('games.id', 'desc')
            ->get();

        if (!empty($member)) {
            $games->load(['favouriteGames' => fn ($q) => $q
                ->where('member_id', $member->id)
                ->where('status', 1),
            ]);
        }

        foreach ($games as $game) {
            // 新人專享挑戰活動、EventV2 的遊戲商/遊戲類型限制都走各自獨立的判斷（不動 FlowLimitService，
            // 見 app/Services/NewbieChallengeService.php、EventV2Service.php 的 isGameDisabled()）
            $game->disable = (int) (
                $this->flowLimitService->isGameDisabled($game, $member)
                || $this->newbieChallengeService->isGameDisabled($game, $member)
                || $this->eventV2Service->isGameDisabled($game, $member)
            );
            // 工單4120：維護中的遊戲仍回傳，由前台灰化並顯示恢復時間
            $game->is_maintenance = array_key_exists($game->id, $maintainGameMap) ? 1 : 0; // 工單4126:會員限制的 end_time 為 null,isset 會誤判為不存在
            $game->maintenance_end_time = $maintainGameMap[$game->id] ?? null;
        }

        // NPT跳轉用
        $agentIdString = implode(',', $agent->hierarchy_array);
        $agentDomain = Cache::remember(
            key: CacheKeys::gameListRedirectUrl($agent->id),
            ttl: CacheTtl::ONE_DAY->value,
            callback: fn () => AgentDomain::where('status', 1)
                ->where('type', 2) // 2是遊戲商中轉網址跳轉專用
                ->whereIn('agent_id', $agent->hierarchy_array)
                ->orderByDesc('type')
                ->orderByRaw("FIELD(`agent_id`,{$agentIdString})")
                ->orderByDesc('sort')
                ->orderBy('id', 'ASC')
                ->select('id', 'domain', 'type')
                ->first()
        );

        $allGamesList = collect($games);

        return ApiResponse::success([
            'games' => ListResource::collection($allGamesList),
            'redirect_url' => $agentDomain->domain ?? '',
        ]);
    }

    /**
     * 遊戲詳細資訊
     *
     * OLD: /api/game
     */
    public function show(ShowRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $languageId = $userData->languageId;

        $gameId = $request->input('game_id');

        $vendorInfoIds = $this->vendorService->setIsEventWallet($member->isEventWallet)->getVendorInfoIds($agent);

        $game = Game::where('id', $gameId)
            ->where('status', 1)
            ->whereHas('vendor', fn ($q) => $q
                ->where('status', '>', 0)
            )
            ->whereHas('vendorInfoGames', fn ($q) => $q
                ->where('status', 1)
                ->whereIn('vendor_info_id', $vendorInfoIds)
            )
            ->whereHas('vendor.vendorCustomizedItems', fn ($q) => $q
                ->where('status', 1)
                ->where('agent_group_id', $agent->vendor_customize_group_id)
            )
            ->with([
                'vendorInfoGames' => fn ($q) => $q
                    ->where('status', 1)
                    ->whereIn('vendor_info_id', $vendorInfoIds)
                    ->select('id', 'game_id', 'is_new', 'is_hot', 'join_bet_event', 'join_win_event'),
                'vendor.vendorCustomizedItems' => fn ($q) => $q
                    ->where('status', 1)
                    ->where('agent_group_id', $agent->vendor_customize_group_id)
                    ->select(['id', 'vendor_id', 'type_id', 'name', 'status']),
                'vendor.vendorCustomizedItems.vendorMaintenances' => fn ($q) => $q
                    ->where('status', 1)
                    ->where('start_time', '<=', now())
                    ->where('end_time', '>=', now())
                    ->whereIn('agent_id', $agent->hierarchy_array)
                    ->whereNotIn('exclude_agent_id', $agent->hierarchy_array),
                'gameNames' => fn ($q) => $q->where('language_id', $languageId),
            ])
            ->select(
                'id',
                'vendor_id',
                'type_id',
                'lottery_pool',
                'name',
                'image'
            )
            ->first();

        if (empty($game)) {
            return ApiResponse::error(__('error_message.1406'));
        }

        $game->status = $this->flowLimitService->checkVendorStatus($game->vendor->vendorCustomizedItems->first(), $member);

        return ApiResponse::success(ShowResource::make($game));
    }

    /**
     * 取得遊戲網址
     *
     * OLD: /api/getGameUrl
     */
    public function getUrl(GetUrlRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $vendorId = $request->filled('vendor_id') ? (int) $request->input('vendor_id') : null;
        $gameId = $request->filled('game_id') ? (int) $request->input('game_id') : null;

        $game = $this->checkGameAvailable($member, $vendorId, $gameId);

        $vendorDriver = $this->vendorService->getDriver($vendorId ?? $game->vendor_id, $member);
        $vendor = $vendorDriver->vendor;

        // 依玩家設備判斷是否顯示
        if ($userData->deviceType === DeviceTypeEnum::MOBILE && !$vendor->show_on_mobile) {
            return ApiResponse::error(__('error_message.1437'));
        } elseif (!$vendor->show_on_pc) {
            return ApiResponse::error(__('error_message.1437'));
        }

        // 有未領取的活動獎勵，禁止進入遊戲
        if (BetHelper::hasUnclaimedPrize($member)) {
            return ApiResponse::error(__('error_message.1989'));
        }

        $params = VendorGameUrlParamsData::from([
            'gameCode' => $game->game_type,
            'deviceType' => $userData->deviceType,
            'orientation' => $userData->orientation,
            'language' => LanguageEnum::from($userData->languageId),
            'exitUrl' => $request->input('exit_url'),
            'ip' => $userData->ip,
            'remark' => $game->remark ?? '',
            'info' => $game->info ?? null,
        ]);

        $result = $vendorDriver->getGameUrl($params);

        if (empty($result->pc) && empty($result->mobile) && empty($result->app_infos)) {
            return ApiResponse::error('All response datas are empty');
        }

        // 非原子操作, 不過此資料的精確度要求不高, 如果要改成原子操作, 需要加 unique key.
        MemberLaunchLog::updateOrCreate(
            [
                'launch_date' => now()->format('Y-m-d H:00:00'),
                'member_id' => $member->id,
                'game_id' => $game->id,
            ],
            [
                'count' => DB::raw('count + 1'),
            ]
        );

        // TODO: 本來有判斷遊戲是否可參加活動，但原 code 在這裡拿到 event 後完全沒有使用，所以直接刪掉，留 TODO 在這裡以後再檢查

        return ApiResponse::success(GetGameUrlResource::make([
            'result' => $result,
            'open_window' => $vendor->open_window,
        ]));
    }

    /**
     * 取得快捷投注元件網址
     * 目前只有 SABA 支援快捷投注元件
     */
    public function getWidgetUrl(GetUrlRequest $request)
    {
        $userData = $this->getUserContext();
        $agent = $userData->agent;
        $member = $userData->member;
        $vendorId = $request->filled('vendor_id') ? (int) $request->input('vendor_id') : null;
        $gameId = $request->filled('game_id') ? (int) $request->input('game_id') : null;

        $vendorDriver = null;

        $game = $this->checkGameAvailable($member, $vendorId, $gameId);

        $vendorDriver = $this->vendorService->getDriver($vendorId ?? $game->vendor_id, $member);
        $vendor = $vendorDriver->vendor;
        if (!$vendorDriver instanceof WidgetGameInterface) {
            return ApiResponse::error('vendor not support widget game');
        }

        if (in_array($vendorId, [VendorIdEnum::SABA->value, VendorIdEnum::TTHSW_IBC->value], true)) {
            $agentSetting = $this->agentService->getAgentSettings($agent);
            if (($agentSetting[AgentCustomizedSettingKeyEnum::SABA_WIDGET_VISIBLE] ?? 0) == 0) {
                return ApiResponse::error('saba widget is not enabled');
            }
        }

        $params = VendorGameUrlParamsData::from([
            'gameCode' => $game->game_type,
            'deviceType' => $userData->deviceType,
            'orientation' => $userData->orientation,
            'language' => LanguageEnum::from($userData->languageId),
            'exitUrl' => $request->input('exit_url'),
            'ip' => $userData->ip,
            'remark' => $game->remark ?? '',
            'info' => $game->info ?? null,
        ]);

        $result = $vendorDriver->getWidgetUrl($params);

        if (empty($result->pc) && empty($result->mobile) && empty($result->app_infos)) {
            return ApiResponse::error('All response datas are empty');
        }

        return ApiResponse::success(GetGameUrlResource::make([
            'result' => $result,
            'open_window' => $vendor->open_window,
        ]));

    }

    /**
     * 取得遊戲商提供的優惠碼
     * 目前只有 clotplay 支援優惠碼
     */
    public function getBonusCode(GetUrlRequest $request)
    {
        $userData = $this->getUserContext();
        $agent = $userData->agent;
        $member = $userData->member;
        $vendorId = (int) $request->input('vendor_id', null);
        $gameId = (int) $request->input('game_id', null);

        $vendorDriver = null;

        $game = $this->checkGameAvailable($member, $vendorId, $gameId);

        $vendorDriver = $this->vendorService->getDriver($vendorId ?? $game->vendor_id, $member);

        if (!$vendorDriver instanceof BonusCodeInterface) {
            return ApiResponse::error('vendor not support bonus code');
        }

        $result = $vendorDriver->getBonusCode(VendorBonusCodeRequestData::from([
            'startAt' => null,
            'endAt' => null,
        ]));

        if (empty($result->bonus_code)) {
            return ApiResponse::error('bonus code is empty');
        }

        return ApiResponse::success(GetBonusCodeResource::make($result));
    }

    private function checkGameAvailable(Member $member, ?int $vendorId = null, ?int $gameId = null): Game
    {
        // 檢查遊戲是否存在
        $game = Game::query()
            ->when(!empty($gameId), function ($q) use ($gameId) {
                $q->where('id', $gameId);
            })
            ->when(!empty($vendorId), function ($q) use ($vendorId) {
                $q->where('vendor_id', $vendorId);
            })
            ->where('status', 1)
            ->whereHas('vendorInfoGames', fn ($q) => $q->where('status', 1))
            ->with('vendorInfoGames:id,game_id,vendor_info_id')
            ->select('id', 'type_id', 'game_type', 'vendor_id', 'remark', 'info')
            ->first();
        if (!is_object($game)) {
            throw new ApiException(__('error_message.1406'));
        }

        // 檢查是否正確配置vendor info
        $vendorInfoIds = $this->vendorService->setIsEventWallet($member->is_event_wallet)->getVendorInfoIds($member->agent, $game->vendor_id);

        if (empty($vendorInfoIds)) {
            throw new ApiException(__('error_message.vendor infos empty'));
        }

        $available = $game->vendorInfoGames
            ->pluck('vendor_info_id')
            ->intersect($vendorInfoIds)
            ->isNotEmpty();
        if (!$available) {
            throw new ApiException(__('error_message.no available vendor infos'));
        }

        // 檢查活動v2遊戲類型是否符合
        if ($member->is_event_v2) {
            $availableGameType = EventV2UserJoin::query()
                ->leftJoin('event_v2_details', 'event_v2_details.id', '=', 'event_v2_user_joins.event_v2_detail_id')
                ->leftJoin('event_v2_detail_game_types', 'event_v2_detail_game_types.event_v2_detail_id', '=', 'event_v2_details.id')
                ->where('event_v2_user_joins.member_id', $member->id)
                ->where(function ($query) use ($game) {
                    return $query->where('event_v2_detail_game_types.game_type_id', $game->type_id)
                        ->orWhereNull('event_v2_detail_game_types.game_type_id');
                })
                ->where('event_v2_user_joins.status', EventV2UserJoinStatusEnum::JOIN->value)
                ->exists();

            if ($availableGameType === false) {
                throw new ApiException(__('event_v2.validation.game_type')); // 遊戲類型不符
            }
        }

        // 檢查新人專享挑戰活動的遊戲商/遊戲類型限制是否符合（第二階段挑戰流水/第四階段彩金流水期間），
        // 避免繞過大廳灰化直接打這支API進到限定範圍外的遊戲
        if ($this->newbieChallengeService->isGameDisabled($game, $member)) {
            throw new ApiException(__('newbie_challenge.validation.game_not_allowed'));
        }

        // 檢查維護（整商）＋會員層級的遊戲商限制（工單4126）
        $maintainVendorIds = $this->vendorService->getMaintenanceVendorIds(
            agent: $member->agent,
            status: [VendorMaintainTypeEnum::BOTH_CLOSED, VendorMaintainTypeEnum::GAME_CLOSED],
            vendorIds: [$game->vendor_id],
            member: $member
        );
        if (in_array($game->vendor_id, $maintainVendorIds)) {
            throw new ApiException(__('error_message.1938'));
        }

        // 檢查維護（單一遊戲，工單4120）＋會員層級的遊戲限制（工單4126）：
        // 整商未維護時該遊戲仍可能被個別關閉或對該會員限制
        $maintainGameIds = $this->vendorService->getMaintenanceGameIds(
            agent: $member->agent,
            gameIds: [$game->id],
            member: $member
        );
        if (in_array($game->id, $maintainGameIds)) {
            throw new ApiException(__('error_message.1938'));
        }

        // 檢查鎖分模式
        if (!BetHelper::isMemberFlowLimitAllowed($member, $game->vendor_id, $game)) {
            throw new ApiException(__('error_message.event_mode.vendor_not_allowed_to_transfer_out'));
        }

        return $game;
    }

    /**
     * 加入/取消我的最愛遊戲
     *
     * OLD: /api/favouriteGame
     */
    public function setFavourite(SetFavouriteGameRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $gameId = $request->input('game_id');
        $status = $request->input('status');

        try {
            DB::beginTransaction();
            // No unique key, should not use upsert.
            if ($status) {
                FavouriteGame::updateOrCreate(
                    [
                        'member_id' => $member->id,
                        'game_id' => $gameId,
                    ],
                    ['status' => 1, 'admin' => $member->id]
                );
            } else {
                FavouriteGame::where('member_id', $member->id)
                    ->where('game_id', $gameId)
                    ->update(['status' => 0]);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success();
    }

    public function recentlyGameIds()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $languageId = $userData->languageId;
        $device = $userData->deviceType;

        $now = Carbon::now();
        $startTime = $now->copy()->subDays(7)->toSystemTime();
        $endTime = $now->copy()->toSystemTime();

        $recentGameIds = MemberLaunchLog::where('member_id', $member->id)
            ->whereBetween('launch_date', [$startTime, $endTime])
            ->groupBy('game_id')
            ->orderByDesc('launch_date')
            ->pluck('game_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        if (empty($recentGameIds)) {
            return ApiResponse::success([
                'games' => [],
                'redirect_url' => '',
            ]);
        }

        // 工單4126：帶入 member 一併套用會員層級限制
        $maintainVendors = $this->vendorService->getMaintenanceVendorIds($agent, [VendorMaintainTypeEnum::BOTH_CLOSED, VendorMaintainTypeEnum::GAME_CLOSED], null, $member);
        // 工單4120：遊戲層級維護，遊戲留在列表僅標記。已知遊戲範圍，限縮比對省去全表掃描
        $maintainGameMap = $this->vendorService->getMaintenanceGameMap($agent, $recentGameIds, $member);
        $vendorInfoIds = $this->vendorService->setIsEventWallet($member?->isEventWallet ?? false)->getVendorInfoIds($agent);
        $agentGameIds = $this->agentGameService->getGameIdList($agent);

        $fieldOrder = implode(',', $recentGameIds);

        $games = Game::query()
            ->where('status', 1)
            ->whereIn('id', $recentGameIds)
            ->whereIn('id', $agentGameIds)
            ->when(!empty($maintainVendors), fn ($q) => $q->whereNotIn('vendor_id', $maintainVendors))
            ->when(in_array(38401, $agent->hierarchy_array), fn ($q) => $q
                ->where('game_type', '!=', 'TASSPTA')
            )
            ->whereHas('vendorInfoGames', fn ($q) => $q
                ->where('status', 1)
                ->whereIn('vendor_info_id', $vendorInfoIds)
            )
            ->whereHas('vendor', fn ($q) => $q
                ->where('status', '>', 0)
                ->when($device === DeviceTypeEnum::MOBILE, fn ($q) => $q->where('show_on_mobile', 1))
                ->when($device === DeviceTypeEnum::PC, fn ($q) => $q->where('show_on_pc', 1))
            )
            ->with([
                'vendor' => fn ($q) => $q->select(['id', 'status', 'name', 'image']),
                'vendorInfoGames' => fn ($q) => $q
                    ->where('status', 1)
                    ->whereIn('vendor_info_id', $vendorInfoIds)
                    ->select('id', 'game_id', 'is_new', 'is_hot', 'join_bet_event', 'join_win_event'),
                'vendor.vendorCustomizedItems' => fn ($q) => $q
                    ->where('status', 1)
                    ->where('agent_group_id', $agent->vendor_customize_group_id)
                    ->select('id', 'vendor_id', 'name'),
                'gameNames' => fn ($q) => $q->where('language_id', $languageId),
            ])
            ->select('id', 'vendor_id', 'type_id', 'lottery_pool', 'name', 'image')
            ->orderByRaw("FIELD(games.id, {$fieldOrder})")
            ->get();

        $games->load(['favouriteGames' => fn ($q) => $q
            ->where('member_id', $member->id)
            ->where('status', 1),
        ]);

        foreach ($games as $game) {
            // 新人專享挑戰活動、EventV2 的遊戲商/遊戲類型限制都走各自獨立的判斷（不動 FlowLimitService，
            // 見 app/Services/NewbieChallengeService.php、EventV2Service.php 的 isGameDisabled()）
            $game->disable = (int) (
                $this->flowLimitService->isGameDisabled($game, $member)
                || $this->newbieChallengeService->isGameDisabled($game, $member)
                || $this->eventV2Service->isGameDisabled($game, $member)
            );
            // 工單4120：維護中的遊戲仍回傳，由前台灰化並顯示恢復時間
            $game->is_maintenance = array_key_exists($game->id, $maintainGameMap) ? 1 : 0; // 工單4126:會員限制的 end_time 為 null,isset 會誤判為不存在
            $game->maintenance_end_time = $maintainGameMap[$game->id] ?? null;
        }

        $agentHierarchyStr = implode(',', $agent->hierarchy_array);
        $agentDomain = Cache::remember(
            key: CacheKeys::gameListRedirectUrl($agent->id),
            ttl: CacheTtl::ONE_DAY->value,
            callback: fn () => AgentDomain::where('status', 1)
                ->where('type', 2)
                ->whereIn('agent_id', $agent->hierarchy_array)
                ->orderByDesc('type')
                ->orderByRaw("FIELD(`agent_id`,{$agentHierarchyStr})")
                ->orderByDesc('sort')
                ->orderBy('id', 'ASC')
                ->select('id', 'domain', 'type')
                ->first()
        );

        return ApiResponse::success([
            'games' => ListResource::collection($games),
            'redirect_url' => $agentDomain->domain ?? '',
        ]);
    }

    /**
     * 優惠遊戲：近一個月投注過的遊戲、今日熱門遊戲
     *
     * OLD: /api/getPromotionGames（vw-master 獨有，tpower-master 無此端點）
     */
    public function promotionGames()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $languageId = $userData->languageId;

        return ApiResponse::success([
            'recent_games' => $this->gameService->getRecentPromotionGames($member, $languageId),
            'recommend_games' => $this->gameService->getRecommendPromotionGames($member, $languageId),
        ]);
    }
}
