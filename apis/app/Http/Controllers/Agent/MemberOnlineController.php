<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Member\MemberKickRequest;
use App\Http\Requests\Agent\Member\MemberOnlineListRequest;
use App\Http\Resources\Agent\Member\MemberOnlineResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\MemberOnlineService;
use Dedoc\Scramble\Attributes\Group;

#[Group('member (在線會員)')]
class MemberOnlineController extends Controller
{
    public function __construct(
        private MemberOnlineService $service,
    ) {}

    /**
     * 在線會員列表
     *
     * 列出持有未過期 access token 的下線會員。
     */
    public function list(MemberOnlineListRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $paginator = $this->service->list(
            agent: $agent,
            filters: $request->only(['username', 'name', 'ip']),
            page: (int) $request->input('page'),
            perPage: (int) $request->input('per_page'),
        );

        return ApiResponse::successWithPagination(
            data: MemberOnlineResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * 強制登出會員
     *
     * 作廢會員全部有效 access token，前台下一個請求即被登出。
     */
    public function kick(MemberKickRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $this->service->kick($agent, (int) $request->input('member_id'));

        return ApiResponse::success();
    }
}
