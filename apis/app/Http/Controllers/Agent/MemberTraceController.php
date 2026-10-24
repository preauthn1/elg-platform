<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Member\MemberTraceListRequest;
use App\Http\Requests\Agent\Member\MemberTraceSuggestRequest;
use App\Http\Resources\Agent\Member\MemberTraceResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\MemberContactMaskService;
use App\Services\Agent\MemberTraceService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('member (會員追蹤)')]
class MemberTraceController extends Controller
{
    public function __construct(
        private readonly MemberTraceService $service,
    ) {}

    /**
     * 會員追蹤
     *
     * 反查模式（username + trace_fields）或直接模式（ip/password/mobile/email），
     * 找出關聯帳號，回傳每筆命中的維度供前端渲染風控色標（3963）。
     */
    public function list(MemberTraceListRequest $request, MemberContactMaskService $contactMask): JsonResponse
    {
        $agent = $this->getAgentContext()->agent;
        $contactMask->bindRevealFlags($request, $agent);

        $paginator = $this->service->search(
            agent: $agent,
            username: (string) $request->input('username', ''),
            conditions: [
                'ip' => (string) $request->input('ip', ''),
                'mobile' => (string) $request->input('mobile', ''),
                'email' => (string) $request->input('email', ''),
            ],
            traceFields: (array) $request->input('trace_fields', []),
            useAnd: $request->boolean('use_and'),
            page: (int) $request->input('page'),
            perPage: (int) $request->input('per_page'),
        );

        return ApiResponse::successWithPagination(
            data: MemberTraceResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * 帳號快搜建議
     *
     * 供前端帳號輸入框即時下拉：以前綴比對回傳符合的 username 清單（上限 10 筆），
     * 限當前代理可視範疇（3963）。
     */
    public function suggest(MemberTraceSuggestRequest $request): JsonResponse
    {
        $usernames = $this->service->suggestUsernames(
            agent: $this->getAgentContext()->agent,
            keyword: (string) $request->input('keyword', ''),
        );

        return ApiResponse::success($usernames);
    }
}
