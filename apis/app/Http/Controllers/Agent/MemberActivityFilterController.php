<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Member\MemberActivityFilterRequest;
use App\Http\Resources\Agent\Member\MemberActivityFilterResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\MemberActivityFilterService;
use App\Services\Agent\MemberContactMaskService;
use Dedoc\Scramble\Attributes\Group;

#[Group('member (會員活動篩選器)')]
class MemberActivityFilterController extends Controller
{
    public function __construct(
        private MemberActivityFilterService $service,
    ) {}

    /**
     * 會員活動篩選器
     *
     * 依代收/代付總額·次數與最後活動時間篩選會員（3967）。
     */
    public function list(MemberActivityFilterRequest $request, MemberContactMaskService $contactMask)
    {
        $agent = $this->getAgentContext()->agent;
        $contactMask->bindRevealFlags($request, $agent);

        $paginator = $this->service->filter(
            agent: $agent,
            filters: (array) $request->input('filters', []),
            match: (string) $request->input('match', 'and'),
            page: (int) $request->input('page'),
            perPage: (int) $request->input('per_page'),
        );

        return ApiResponse::successWithPagination(
            data: MemberActivityFilterResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }
}
