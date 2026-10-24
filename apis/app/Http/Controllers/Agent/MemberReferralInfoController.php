<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Member\MemberReferralInfoListRequest;
use App\Http\Resources\Agent\Member\MemberReferralInfoResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\MemberReferralInfoService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Carbon;

#[Group('member (直屬會員紀錄)')]
class MemberReferralInfoController extends Controller
{
    public function __construct(
        private MemberReferralInfoService $service,
    ) {}

    /**
     * 直屬會員紀錄列表
     *
     * 範疇內會員的直屬下線統計（直屬人數/首存/存提/投注/獎勵金）與本人推薦獎金。
     */
    public function list(MemberReferralInfoListRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $input = $request->validated();

        $startAt = Carbon::parse($input['start_time'])->toSystemTime()->format('Y-m-d H:i:s');
        $endAt = Carbon::parse($input['end_time'])->toSystemTime()->format('Y-m-d H:i:s');

        $paginator = $this->service->list(
            agent: $agent,
            filters: $request->only(['agent_username', 'username']),
            startAt: $startAt,
            endAt: $endAt,
            page: (int) $request->input('page'),
            perPage: (int) $request->input('per_page'),
        );

        return ApiResponse::successWithPagination(
            data: MemberReferralInfoResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }
}
