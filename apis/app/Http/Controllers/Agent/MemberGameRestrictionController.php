<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\MemberGameRestriction\IndexRequest;
use App\Http\Requests\Agent\MemberGameRestriction\MemberListRequest;
use App\Http\Requests\Agent\MemberGameRestriction\SaveRequest;
use App\Http\Requests\Agent\MemberGameRestriction\ViewRequest;
use App\Http\Resources\Agent\MemberGameRestriction\MemberGameRestrictionResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\MemberGameRestrictionService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 會員遊戲限制（4126）：下線會員管理 → 會員遊戲限制。
 *
 * 與遊戲維護（4120，代理層級、有時間區間、到點自動恢復）平行的另一套：
 * 限制對象是會員或會員群組，永久生效，解除即停用或刪除該筆。
 *
 * 前台效果與維護一致（遊戲商整批灰化或單一遊戲灰化），判定併入
 * VendorService 既有查詢，前端六個皮膚零改動；差別是不回傳恢復時間。
 */
#[Group('member-game-restriction (會員遊戲限制)')]
class MemberGameRestrictionController extends Controller
{
    public function __construct(
        private readonly MemberGameRestrictionService $service,
    ) {}

    /**
     * 會員遊戲限制列表
     */
    public function list(IndexRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        $paginator = $this->service->list($operator, $request->validated());

        return ApiResponse::successWithPagination(
            data: MemberGameRestrictionResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * 會員遊戲限制詳情
     *
     * 回傳受限標的與對象清單供編輯表單回填；會員一併帶 username，
     * 因下拉為遠端搜尋、不重查就只剩 id。
     */
    public function view(ViewRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        $restriction = $this->service->view($operator, (int) $request->input('id'));

        if (!$restriction) {
            throw new ApiException(__('error_message.1010'));
        }

        return ApiResponse::success(MemberGameRestrictionResource::make($restriction));
    }

    /**
     * 新增／編輯會員遊戲限制
     *
     * 帶 id 為編輯，不帶為新增。標的與對象整批覆寫，主表與兩張子表在同一交易內完成。
     */
    public function save(SaveRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;
        $input = $request->validated();

        $id = $this->service->save(
            operator: $operator,
            id: isset($input['id']) ? (int) $input['id'] : null,
            payload: $input,
            targetIds: $input['target_ids'],
            memberIds: $input['member_ids'] ?? [],
            memberGroupIds: $input['member_group_ids'] ?? [],
        );

        return ApiResponse::success(['id' => $id]);
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
