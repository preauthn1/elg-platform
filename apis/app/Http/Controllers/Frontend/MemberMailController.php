<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\MemberMail\ListRequest;
use App\Http\Requests\Frontend\MemberMail\ReadRequest;
use App\Http\Resources\Frontend\MemberMail\MemberMailResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\MemberMailService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 站內信（4152）：前台鈴鐺的第三個分頁。
 *
 * 未讀數為獨立接口——前台鈴鐺目前是把各分頁的未讀數字自行加總，
 * 本接口加入加總式即可，既有的最新消息／系統通知兩支不動。
 */
#[Group('MemberMail (站內信)')]
class MemberMailController extends Controller
{
    public function __construct(
        private readonly MemberMailService $service,
    ) {}

    /**
     * 站內信列表
     *
     * 內容取當前語系（Accept-Language），未填 fallback 英文。
     */
    public function list(ListRequest $request): JsonResponse
    {
        $userData = $this->getUserContext();

        $paginator = $this->service->list(
            member: $userData->member,
            languageId: $userData->languageId,
            perPage: (int) $request->input('per_page', 15),
        );

        return ApiResponse::successWithPagination(
            data: MemberMailResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * 站內信未讀數
     *
     * 供前台鈴鐺加總顯示。
     */
    public function unread(): JsonResponse
    {
        $userData = $this->getUserContext();

        return ApiResponse::success([
            'unread' => $this->service->unreadCount($userData->member),
        ]);
    }

    /**
     * 標記單封已讀
     *
     * 逐封記錄：點開哪封標哪封，不影響其他信的未讀狀態。重複標記視為成功。
     */
    public function read(ReadRequest $request): JsonResponse
    {
        $userData = $this->getUserContext();

        $this->service->markRead($userData->member, (int) $request->input('id'));

        return ApiResponse::success();
    }

    /**
     * 全部標記已讀
     *
     * 進入站內信頁時呼叫（與系統通知的 read-all 行為一致）。
     * 仍逐封寫入已讀紀錄，觸達率照常累計。
     */
    public function readAll(): JsonResponse
    {
        $userData = $this->getUserContext();

        $this->service->markAllRead($userData->member);

        return ApiResponse::success();
    }
}
