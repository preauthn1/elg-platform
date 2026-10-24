<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Data\Ranking\RankingGameWinnerData;
use App\Data\Ranking\RankingProfitData;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\DomainRequest;
use App\Http\Requests\Frontend\Ranking\DomainTypeRequest;
use App\Http\Resources\Frontend\Ranking\GameWinnersResource;
use App\Http\Resources\Frontend\Ranking\ProfitsResource;
use App\Models\AgentDomain;
use App\Services\RankingService;
use Dedoc\Scramble\Attributes\Group;

#[Group('Ranking (排行榜)')]
class RankingController extends Controller
{
    public function __construct(
        private RankingService $rankingService,
    ) {}

    /**
     * 今日遊戲赢家榜
     *
     * OLD: /api/ranking/game_winner_list
     */
    public function gameWinners(DomainRequest $request)
    {
        $domain = $request->input('domain');
        $agentDomain = AgentDomain::with(['agent'])
            ->where('domain', $domain)
            ->where('status', 1)
            ->first();
        if (!$agentDomain) {
            return ApiResponse::error(__('error_message.1213'));
        }
        $agent = $agentDomain->agent;
        if (!$agent) {
            return ApiResponse::error(__('error_message.1213'));
        }

        $startTime = \Carbon\Carbon::now()
            ->toFrontendTime()
            ->startOfDay()
            ->toSystemTime();
        $endTime = $startTime->copy()->addDay();

        $data = $this->rankingService->getGameWinners(
            agent: $agent,
            startTime: $startTime->format('Y-m-d H:i:s'),
            endTime: $endTime->format('Y-m-d H:i:s'),
        );

        $realCount = $data->count();
        if ($realCount < 30) {
            $fakeNeeded = 30 - $realCount;
            $fakeData = $this->rankingService->getFakeData($agent);

            for ($i = 0; $i < $fakeNeeded; $i++) {
                $item = $fakeData->data[$i];

                $data->push(RankingGameWinnerData::from([
                    'username' => $item->username,
                    'image' => $item->image,
                    'total_win_lose' => $item->game_total_win_lose,
                    'vendor_name' => $item->vendor_name,
                ]));
            }
        }

        $data = $data->sortByDesc('total_win_lose')->values();
        $this->rankingService->setRank($data);

        return ApiResponse::success(GameWinnersResource::collection($data));
    }

    /**
     * 今日盈利榜
     *
     * OLD: /api/ranking/profit_list
     */
    public function profits(DomainRequest $request)
    {
        $domain = $request->input('domain');
        $agentDomain = AgentDomain::with(['agent'])
            ->where('domain', $domain)
            ->where('status', 1)
            ->first();
        if (!$agentDomain) {
            return ApiResponse::error(__('error_message.1213'));
        }
        $agent = $agentDomain->agent;
        if (!$agent) {
            return ApiResponse::error(__('error_message.1213'));
        }

        $startTime = \Carbon\Carbon::now()
            ->toFrontendTime()
            ->startOfDay()
            ->toSystemTime();
        $endTime = $startTime->copy()->addDay();

        $data = $this->rankingService->getProfits(
            agent: $agent,
            startTime: $startTime->format('Y-m-d H:i:s'),
            endTime: $endTime->format('Y-m-d H:i:s'),
        );

        $realCount = $data->count();
        if ($realCount < 30) {
            $fakeNeeded = 30 - $realCount;
            $fakeData = $this->rankingService->getFakeData($agent);

            for ($i = 0; $i < $fakeNeeded; $i++) {
                $item = $fakeData->data[$i];

                $data->push(RankingProfitData::from([
                    'username' => $item->username,
                    'image' => $item->image,
                    'total_win_lose' => Common::numAdd($item->game_total_win_lose, $item->profit_total_win_lose),
                ]));
            }
        }

        $data = $data->sortByDesc('total_win_lose')->values();

        $this->rankingService->setRank($data);

        return ApiResponse::success(ProfitsResource::collection($data));
    }

    /**
     * 中獎排行榜
     *
     * OLD: /api/ranking/winning
     */
    public function winning(DomainTypeRequest $request)
    {
        $domain = $request->input('domain');
        $agentDomain = AgentDomain::with(['agent'])
            ->where('domain', $domain)
            ->where('status', 1)
            ->first();
        if (!$agentDomain) {
            return ApiResponse::error(__('error_message.1213'));
        }
        $agent = $agentDomain->agent;
        if (!$agent) {
            return ApiResponse::error(__('error_message.1213'));
        }

        $userData = $this->getUserContext();

        $data = $this->rankingService->getWinningRanking($agent, (int) $request->input('type'), $userData->languageId);

        return ApiResponse::success($data);
    }

    /**
     * 平台投注排行榜
     *
     * OLD: /api/ranking/platform_betting
     */
    public function platformBetting(DomainTypeRequest $request)
    {
        $domain = $request->input('domain');
        $agentDomain = AgentDomain::with(['agent'])
            ->where('domain', $domain)
            ->where('status', 1)
            ->first();
        if (!$agentDomain) {
            return ApiResponse::error(__('error_message.1213'));
        }
        $agent = $agentDomain->agent;
        if (!$agent) {
            return ApiResponse::error(__('error_message.1213'));
        }

        $data = $this->rankingService->getPlatformBettingRanking($agent, (int) $request->input('type'));

        return ApiResponse::success($data);
    }
}
