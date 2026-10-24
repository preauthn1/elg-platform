<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\MemberScore\MemberScoreDeductRequest;
use App\Http\Requests\Agent\MemberScore\MemberScoreGrantRequest;
use App\Services\Agent\MemberScoreAgentService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 會員積分（target/tickets#4233）。
 *
 * 入口在帳號管理 › 功能 › 開洗分彈窗的「積分」頁籤（agent-backend transaction-dialog）；
 * 路由掛 CheckAgentPermission:member_add_score，無權限前端不顯示該選項、後端亦擋。
 *
 * ⚠️ CheckAgentPermission 查無選單碼時是 fail-open 放行：白標升級若 SyncAgentMenuSeeder 沒跑，
 * 這兩支接口在那個窗內對任何代理都不設防。升級順序固定「先 seeder（結尾會清 code 快取）再切流量」。
 */
#[Group('member (會員積分)')]
class MemberScoreController extends Controller
{
    public function __construct(
        private readonly MemberScoreAgentService $scoreAgentService,
    ) {}

    /**
     * 人工發放積分
     *
     * 只做增加。有效期單位小時、不給＝無限期（與活動端 reward_config.expire 同語意）；
     * 備註必填、寫進積分異動紀錄。範疇＝整棵下線，非線下會員回 member out of scope。
     */
    public function grant(MemberScoreGrantRequest $request): JsonResponse
    {
        return ApiResponse::success($this->scoreAgentService->grant(
            agent: $this->getAgentContext()->agent,
            memberId: (int) $request->input('member_id'),
            score: (string) $request->input('score'),
            hours: $request->filled('hours') ? (int) $request->input('hours') : null,
            remark: (string) $request->input('remark'),
        ));
    }

    /**
     * 人工扣除積分
     *
     * 填總扣除量，系統依到期日由近到遠自動拆批（與活動消耗同一套 FIFO），跨幾批回幾筆 deductions。
     * 餘額不足整筆擋下（400，message 帶可用／要求數）。備註必填、寫進每筆異動紀錄。
     */
    public function deduct(MemberScoreDeductRequest $request): JsonResponse
    {
        return ApiResponse::success($this->scoreAgentService->deduct(
            agent: $this->getAgentContext()->agent,
            memberId: (int) $request->input('member_id'),
            score: (string) $request->input('score'),
            remark: (string) $request->input('remark'),
        ));
    }
}
