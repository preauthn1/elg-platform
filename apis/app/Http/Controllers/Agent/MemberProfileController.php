<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Member\MemberProfileRequest;
use App\Services\Agent\MemberProfileService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 單一會員資料（target/tickets#4438）：新後台開洗分彈窗、補點彈窗、會員編輯頁共用。
 * 不掛選單碼（三個入口各自的權限不同，原接口亦無）；資料防線＝整棵下線範疇檢查。
 */
#[Group('member (會員資料)')]
class MemberProfileController extends Controller
{
    public function __construct(
        private readonly MemberProfileService $profileService,
    ) {}

    /**
     * 讀單一會員資料
     *
     * 欄位同舊接口（不含支付密碼雜湊）；範圍外會員回 member out of scope。
     */
    public function view(MemberProfileRequest $request): JsonResponse
    {
        return ApiResponse::success($this->profileService->view(
            operator: $this->getAgentContext()->agent,
            memberId: (int) $request->input('member_id'),
        ));
    }
}
