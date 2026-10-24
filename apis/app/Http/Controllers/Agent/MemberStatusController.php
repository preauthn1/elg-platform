<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\MemberStatusEnum;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Member\MemberStatusUpdateRequest;
use App\Services\Agent\MemberStatusService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 會員狀態切換（target/tickets#4438）：帳號管理「啟用／停用」與會員詳情抽屜的狀態開關共用。
 * 路由掛 CheckAgentPermission:member_disabled_permissiom，與舊後台按鈕顯示條件同一個權限。
 */
#[Group('member (會員狀態)')]
class MemberStatusController extends Controller
{
    public function __construct(
        private readonly MemberStatusService $statusService,
    ) {}

    /**
     * 切換會員狀態
     *
     * 範疇＝整棵下線；與現值相同回 400。寫狀態紀錄、會員狀態紀錄、操作紀錄各一筆（同舊後台）。
     */
    public function update(MemberStatusUpdateRequest $request): JsonResponse
    {
        $this->statusService->change(
            operator: $this->getAgentContext()->agent,
            memberId: (int) $request->input('member_id'),
            status: MemberStatusEnum::from((int) $request->input('status')),
            remark: $request->input('remark'),
        );

        return ApiResponse::success();
    }
}
