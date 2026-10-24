<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\LanguageEnum;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\RefreshRanking\EventIdRequest;
use App\Http\Requests\Frontend\RefreshRanking\GameUrlRequest;
use App\Http\Requests\Frontend\RefreshRanking\LeaderBoardRequest;
use App\Http\Requests\Frontend\RefreshRanking\VendorIdRequest;
use App\Http\Resources\Frontend\Vendor\GetGameUrlResource;
use App\Services\RefreshRankingService;
use Dedoc\Scramble\Attributes\Group;

#[Group('Refresh Ranking (刷分排行榜，tpower 獨有)')]
class RefreshRankingController extends Controller
{
    public function __construct(
        protected RefreshRankingService $refreshRankingService,
    ) {}

    /**
     * 期數列表
     *
     * OLD: /api/event/refresh_ranking/list
     */
    public function list()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        return ApiResponse::success($this->refreshRankingService->getIssueList($member, $agent));
    }

    /**
     * 排行榜
     *
     * - 自己未在名次內時，於列表尾端補上自己的紀錄，名次顯示 `--`
     *
     * OLD: /api/event/refresh_ranking/leader_board
     */
    public function leaderBoard(LeaderBoardRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;

        $data = $this->refreshRankingService->getLeaderBoard(
            member: $member,
            agent: $member->agent ?? $userData->agent,
            eventId: (int) $input['event_id'],
            no: (int) $input['no'],
            page: isset($input['page']) ? (int) $input['page'] : null,
            perPage: isset($input['per_page']) ? (int) $input['per_page'] : null,
        );

        return ApiResponse::success($data);
    }

    /**
     * 刷分排行榜錢包餘額
     *
     * OLD: /api/event/refresh_ranking/wallet_balance
     */
    public function walletBalance()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $balance = $this->refreshRankingService->getWalletBalance($member, $agent);

        // 舊系統 API_Frontend 的 data 直接是裸字串金額；這裡的 ApiResponse::success() 要求 data 為 array|object，所以包一層
        return ApiResponse::success(['balance' => $balance]);
    }

    /**
     * 可玩的遊戲商列表
     *
     * OLD: /api/event/refresh_ranking/vendor
     */
    public function vendor()
    {
        $userData = $this->getUserContext();
        $agent = $userData->member->agent ?? $userData->agent;

        return ApiResponse::success($this->refreshRankingService->getVendorList($agent));
    }

    /**
     * 指定遊戲商的遊戲列表
     *
     * OLD: /api/event/refresh_ranking/game
     */
    public function game(VendorIdRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $agent = $userData->member->agent ?? $userData->agent;

        return ApiResponse::success($this->refreshRankingService->getGameList($agent, (int) $input['vendor_id']));
    }

    /**
     * 獎項列表
     *
     * OLD: /api/event/refresh_ranking/awards
     */
    public function awards(EventIdRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $agent = $userData->member->agent ?? $userData->agent;

        return ApiResponse::success(
            $this->refreshRankingService->getAwards($agent, (int) $input['event_id'], $userData->languageId)
        );
    }

    /**
     * 依遊戲商啟動遊戲
     *
     * OLD: /api/event/refresh_ranking/game_url
     */
    public function gameUrl(GameUrlRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $result = $this->refreshRankingService->getGameUrl(
            member: $member,
            agent: $agent,
            gameId: (int) $input['game_id'],
            language: LanguageEnum::from($userData->languageId),
            deviceType: $userData->deviceType,
            exitUrl: $input['exit_url'],
            ip: $userData->ip,
            orientation: $userData->orientation,
        );

        return ApiResponse::success(GetGameUrlResource::make($result));
    }
}
