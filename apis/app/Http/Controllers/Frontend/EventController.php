<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\Event\LegacyDetailRequest;
use App\Http\Requests\Frontend\Event\LegacyListByDomainRequest;
use App\Http\Requests\Frontend\Event\LegacyValidateRequest;
use App\Http\Requests\Frontend\Event\NewInviteEventRequest;
use App\Http\Requests\Frontend\Event\NewInvitePrizeRequest;
use App\Http\Requests\Frontend\Event\VendorIdRequest;
use App\Http\Requests\Frontend\PaginationRequest;
use App\Http\Resources\Frontend\Event\PrizeRemindResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Models\AgentDomain;
use App\Services\EventNewInviteService;
use App\Services\EventService;
use App\Services\EventV2\EventV2Service;
use App\Services\VipService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

#[Group('Event (活動)')]
class EventController extends Controller
{
    public function __construct(
        protected EventService $eventService,
        protected EventNewInviteService $eventNewInviteService,
        protected EventV2Service $eventV2Service,
        protected VipService $vipService,
    ) {}

    /**
     * 檢查是否有未領取獎勵
     */
    public function remind(Request $request)
    {
        $userData = $this->getUserContext();

        $member = $userData->member;
        $agent = $userData->agent;
        $agentIds = $agent->hierarchy_array;

        $hasDetailIds = $this->eventService->findAvailableEventDetailIds($agentIds);

        if (empty($hasDetailIds)) {
            $data = [
                'has_prize' => false,
                'count' => 0,
            ];

            return ApiResponse::success($data);
        }

        $prizeCount = $this->eventService->getPrizeCount($hasDetailIds, $member->id);

        $data = [
            'has_prize' => $prizeCount > 0,
            'count' => $prizeCount,
        ];

        return ApiResponse::success($data);
    }

    /**
     * 活動列表（V1）
     *
     * - 依會員所屬代理層級取活動，並帶出每位會員的達成進度與兌換按鈕狀態
     * - 排序：可兌換 > 不可兌換 > 已領取 > 已過期 > 已取消
     *
     * OLD: /api/events
     */
    public function list(PaginationRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $result = $this->eventService->getLegacyEventList($member, $agent, $userData->languageId);

        $paginator = new LengthAwarePaginator(
            $result['data'],
            $result['total'],
            (int) $input['per_page'],
            (int) $input['page'],
        );

        return ApiResponse::success([
            'items' => $result['data'],
            'is_reddeem' => $result['is_reddeem'],
            'pagination' => new OnlyPaginateResource($paginator),
        ]);
    }

    /**
     * 活動詳情（V1）
     *
     * - 帶出活動明細、可參與遊戲列表、獎項內容與兌換按鈕狀態
     *
     * OLD: /api/event
     */
    public function view(LegacyDetailRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $data = $this->eventService->getLegacyEventDetail($member, $agent, (int) $input['id'], $userData->languageId);

        return ApiResponse::success($data);
    }

    /**
     * 活動資格驗證（V1）
     *
     * - 目前僅支援 action 60（tpower 邀請玩家活動）：驗證邀請人數達標後預先建立待領獎勵
     *
     * OLD: /api/event/validate
     */
    public function validateEvent(LegacyValidateRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();

        $this->eventService->validateLegacyEvent(
            $userData->member,
            $userData->member->agent ?? $userData->agent,
            (int) $input['event_id'],
        );

        return ApiResponse::success();
    }

    /**
     * 新玩家邀請活動（V1）
     *
     * - 帶出邀請碼、獎項列表與各獎項的達標人數與兌換狀態
     *
     * OLD: /api/event/getNewInviteEvent
     */
    public function newInviteEvent(NewInviteEventRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $data = $this->eventNewInviteService->getEvent($member, $agent, (int) $input['event_id'], $userData->languageId);

        return ApiResponse::success($data);
    }

    /**
     * 領取新玩家邀請活動獎勵（V1）
     *
     * OLD: /api/event/getNewInvitePrize
     */
    public function newInvitePrize(NewInvitePrizeRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $this->eventNewInviteService->receivePrize($member, $agent, (int) $input['prize_content_id']);

        return ApiResponse::success();
    }

    /**
     * 活動列表 V1（免登入，依 domain 判定品牌）
     *
     * - 不帶會員狀態：兌換數、進度、今日達成皆為固定值
     *
     * OLD: /api/eventsByDomain
     */
    public function listByDomain(LegacyListByDomainRequest $request)
    {
        $input = $request->validated();

        $agentDomain = AgentDomain::with('agent')
            ->where('domain', $input['domain'])
            ->first();

        // 查無 domain 時明確回錯；舊系統 API_Frontend 的寫法會直接讀 null 屬性，在 PHP 8.4 下會致命錯誤
        if (!$agentDomain?->agent) {
            throw new ApiException(__('error_message.1005'));
        }

        $userData = $this->getUserContext();
        $languageId = $userData?->languageId ?? 1;

        $result = $this->eventService->getLegacyEventListByDomain($agentDomain->agent, $languageId);

        $paginator = new LengthAwarePaginator(
            $result['data'],
            $result['total'],
            (int) $input['per_page'],
            (int) $input['page'],
        );

        return ApiResponse::success([
            'items' => $result['data'],
            'is_reddeem' => $result['is_reddeem'],
            'pagination' => new OnlyPaginateResource($paginator),
        ]);
    }

    /**
     * 依遊戲商列出目前進行中、可參與的活動與遊戲
     *
     * OLD: /api/event/vendorGames
     */
    public function vendorGames(VendorIdRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        return ApiResponse::success(
            $this->eventService->getEventsByVendor($member, $agent, (int) $input['vendor_id'], $userData->languageId)
        );
    }

    /**
     * 主頁驚嘆號提醒：VIP 達標可領獎、活動 V1 未領取獎勵、活動 V2 可參加但尚未參加
     */
    public function prizeRemind(Request $request)
    {
        $userData = $this->getUserContext();

        $member = $userData->member;
        $agent = $userData->agent;
        $agentIds = $agent->hierarchy_array;

        $hasV1Prize = $this->eventService->checkV1PrizeCount($agentIds, $member->id) > 0;
        $hasV2Joinable = $this->eventV2Service->hasJoinableEventV2($agentIds, $member);
        $hasVipPrize = $this->vipService->checkVipPrizeCount($member->id) > 0;

        $data = [
            'vip' => $hasVipPrize,
            'event' => $hasV1Prize || $hasV2Joinable,
        ];

        return ApiResponse::success(PrizeRemindResource::make($data));
    }
}
