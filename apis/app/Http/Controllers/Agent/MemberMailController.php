<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\MemberMail\DeleteRequest;
use App\Http\Requests\Agent\MemberMail\IndexRequest;
use App\Http\Requests\Agent\MemberMail\MemberListRequest;
use App\Http\Requests\Agent\MemberMail\SaveRequest;
use App\Http\Requests\Agent\MemberMail\ViewRequest;
use App\Http\Resources\Agent\MemberMail\MemberMailResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\MemberMailService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 站內信管理（4152）：營運商管理 → 站內信管理。
 *
 * 對會員發信的管理端。四種發送類型（指定會員／會員群組／全平臺／代理及子代理），
 * 內容多語系（英文必填當 fallback 底線）。發布時間到點後整封信鎖定，
 * 僅能查看——信寄出去就不能收回或竄改。
 */
#[Group('member-mail (站內信)')]
class MemberMailController extends Controller
{
    public function __construct(
        private readonly MemberMailService $service,
    ) {}

    /**
     * 站內信列表
     */
    public function list(IndexRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        $paginator = $this->service->list($operator, $request->validated());

        return ApiResponse::successWithPagination(
            data: MemberMailResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * 站內信詳情
     *
     * 回傳多語系內容與對象清單供編輯回填；指定會員類型一併帶收件人帳號。
     */
    public function view(ViewRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        $mail = $this->service->view($operator, (int) $request->input('id'));

        if (!$mail) {
            throw new ApiException(__('error_message.1010'));
        }

        return ApiResponse::success(MemberMailResource::make($mail));
    }

    /**
     * 新增／編輯站內信
     *
     * 帶 id 為編輯，不帶為新增。已到發布時間的信一律擋下（回 published_locked）。
     * 指定會員／會員群組於本次存檔即展開收件人快照。
     */
    public function save(SaveRequest $request): JsonResponse
    {
        $context = $this->getAgentContext();

        $id = $this->service->save(
            operator: $context->agent,
            adminId: (int) $context->agent->id,
            id: $request->filled('id') ? (int) $request->input('id') : null,
            input: $request->validated(),
        );

        return ApiResponse::success(['id' => $id]);
    }

    /**
     * 刪除站內信
     *
     * 僅發布前可刪；已到發布時間一律擋下。
     */
    public function delete(DeleteRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        $this->service->delete($operator, (int) $request->input('id'));

        return ApiResponse::success();
    }

    /**
     * 表單用的會員遠端搜尋
     *
     * 下線動輒數萬筆，未輸入關鍵字不查、且限量回傳，避免整批灌進下拉凍死彈窗。
     */
    public function memberList(MemberListRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        $members = $this->service->searchMembers(
            operator: $operator,
            keyword: (string) $request->input('keyword'),
            limit: (int) $request->input('limit', 50),
        );

        return ApiResponse::success(
            $members->map(fn ($member) => [
                'id' => (int) $member->id,
                'username' => (string) $member->username,
            ])->all()
        );
    }
}
