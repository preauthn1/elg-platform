<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Member\MemberFlowLimitCancelRequest;
use App\Http\Requests\Agent\Member\MemberFlowLimitListRequest;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\MemberFlowLimitService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 會員稽核流水（target/tickets#4438）：會員列表、會員詳情抽屜、提款審核彈窗共用同一個稽核流水彈窗。
 * 不掛選單碼（三個入口各自的權限不同，原接口亦無）；資料防線＝整棵下線範疇檢查。
 */
#[Group('member (會員資料)')]
class MemberFlowLimitController extends Controller
{
    private const int DEFAULT_PER_PAGE = 20;

    public function __construct(
        private readonly MemberFlowLimitService $service,
    ) {}

    /**
     * 會員稽核流水列表
     *
     * 新→舊；不含優惠錢包獎勵。status_zero_exists＝是否還有未通過的流水（前端據此顯示「全部刪除」）。
     */
    public function list(MemberFlowLimitListRequest $request): JsonResponse
    {
        $context = $this->getAgentContext();

        $result = $this->service->list(
            operator: $context->agent,
            memberId: (int) $request->input('member_id'),
            languageId: $context->languageId,
            page: (int) $request->input('page', 1),
            perPage: (int) $request->input('per_page', self::DEFAULT_PER_PAGE),
        );

        return ApiResponse::success([
            'items' => $result['items'],
            'pagination' => OnlyPaginateResource::make($result['paginator']),
            'status_zero_exists' => $result['status_zero_exists'],
        ]);
    }

    /**
     * 取消會員稽核流水
     *
     * 帶 id 取消單筆未通過；帶 member_id 取消該會員全部未通過。沒有可取消的回「記錄不存在」。
     */
    public function cancel(MemberFlowLimitCancelRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;
        $remark = $request->input('remark');

        if ($request->filled('id')) {
            $this->service->cancelOne($operator, (int) $request->input('id'), $remark);
        } else {
            $this->service->cancelAll($operator, (int) $request->input('member_id'), $remark);
        }

        return ApiResponse::success();
    }
}
