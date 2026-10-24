<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\MemberScore\MemberScoreRecordListRequest;
use App\Http\Requests\Agent\MemberScore\MemberScoreRecordLogsRequest;
use App\Http\Resources\Agent\MemberScore\MemberScoreLogResource;
use App\Http\Resources\Agent\MemberScore\MemberScoreRecordResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\MemberScoreRecordService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 專屬優惠 › 積分紀錄（target/tickets#4232）。純讀；權限節點 score_record。
 */
#[Group('score (積分紀錄)')]
class MemberScoreRecordController extends Controller
{
    public function __construct(
        private readonly MemberScoreRecordService $service,
    ) {}

    /**
     * 積分紀錄主表
     *
     * 每會員一列，只列有積分錢包列的會員；範圍＝整棵下線。可依會員帳號、所屬代理（整條線）篩選。
     * 匯出由前端以大 per_page 取回後產生。
     */
    public function list(MemberScoreRecordListRequest $request): JsonResponse
    {
        $paginator = $this->service->list(
            agent: $this->getAgentContext()->agent,
            filters: $request->only(['username', 'agent_username']),
            page: (int) $request->input('page'),
            perPage: (int) $request->input('per_page'),
        );

        return ApiResponse::successWithPagination(
            data: MemberScoreRecordResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * 積分異動明細
     *
     * 某會員某期間的異動（新→舊）；日期以後台時區輸入。非線下會員回 member out of scope。
     */
    public function logs(MemberScoreRecordLogsRequest $request): JsonResponse
    {
        $context = $this->getAgentContext();

        $paginator = $this->service->logs(
            agent: $context->agent,
            memberId: (int) $request->input('member_id'),
            startTime: $request->input('start_time'),
            endTime: $request->input('end_time'),
            languageId: $context->languageId,
            page: (int) $request->input('page'),
            perPage: (int) $request->input('per_page'),
            batchId: $request->filled('batch_id') ? (int) $request->input('batch_id') : null,
            type: $request->filled('type') ? (int) $request->input('type') : null,
            direction: $request->filled('direction') ? (int) $request->input('direction') : null,
        );

        return ApiResponse::successWithPagination(
            data: MemberScoreLogResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }
}
