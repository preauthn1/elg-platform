<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\PaginationRequest;
use App\Http\Requests\Frontend\Prize\ChoosePrizeRequest;
use App\Http\Requests\Frontend\Prize\DrawByDetailRequest;
use App\Http\Requests\Frontend\Prize\DrawPrizeRequest;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\PrizeService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Pagination\LengthAwarePaginator;

#[Group('Prize (活動獎項)')]
class PrizeController extends Controller
{
    public function __construct(
        protected PrizeService $prizeService,
    ) {}

    /**
     * 已領取的獎項紀錄（V1）
     *
     * OLD: /api/prizes
     */
    public function list(PaginationRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();

        $page = (int) $input['page'];
        $perPage = (int) $input['per_page'];

        $result = $this->prizeService->getClaimedList($userData->member, $userData->languageId, $page, $perPage);

        $paginator = new LengthAwarePaginator($result['data'], $result['total'], $perPage, $page);

        return ApiResponse::success([
            'items' => $result['data'],
            'pagination' => new OnlyPaginateResource($paginator),
        ]);
    }

    /**
     * 依活動明細抽出並領取獎項（V1）
     *
     * OLD: /api/getPrizeByDetailId
     */
    public function drawByDetail(DrawByDetailRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $data = $this->prizeService->drawByDetail($member, $agent, (int) $input['event_id'], (int) $input['detail_id']);

        return ApiResponse::success($data);
    }

    /**
     * 抽出並領取活動獎項（V1）
     *
     * OLD: /api/getPrize
     */
    public function draw(DrawPrizeRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $data = $this->prizeService->drawPrize($member, $agent, (int) $input['event_id'], $userData->languageId);

        return ApiResponse::success($data);
    }

    /**
     * 一次領取該活動所有未兌換的獎勵（V1）
     *
     * OLD: /api/getAllPrize
     */
    public function drawAll(DrawPrizeRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $result = $this->prizeService->drawAllPrizes($member, $agent, (int) $input['event_id']);

        return ApiResponse::success($result);
    }

    /**
     * 玩家自主取消未領取的儲值類活動獎勵（V1）
     *
     * OLD: /api/cancelPrize
     */
    public function cancel()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $this->prizeService->cancelPrize($member, $agent);

        return ApiResponse::success();
    }

    /**
     * 玩家自選獎項後領取（V1）
     *
     * OLD: /api/choosePrize
     */
    public function choose(ChoosePrizeRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $this->prizeService->choosePrize($member, $agent, (int) $input['event_id'], (int) $input['prize_content_id']);

        return ApiResponse::success();
    }

    /**
     * 設定中獎項目為已領取（V1）
     *
     * 獎項在抽獎當下就已派發。此端點只為維持前端相容而保留。呼叫後直接回成功。
     * 舊系統 API_Frontend 的同名端點也是空殼（開頭直接回成功，之後皆為不可達的死碼）。
     *
     * OLD: /api/setPrize
     */
    public function setPrize()
    {
        return ApiResponse::success();
    }
}
