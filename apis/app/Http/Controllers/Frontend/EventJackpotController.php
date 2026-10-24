<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\Jackpot\ConfirmNotifyRequest;
use App\Http\Requests\Frontend\Jackpot\ReceiveRequest;
use App\Services\EventJackpotService;
use Dedoc\Scramble\Attributes\Group;

#[Group('Event Jackpot (積寶活動)')]
class EventJackpotController extends Controller
{
    public function __construct(
        protected EventJackpotService $eventJackpotService,
    ) {}

    /**
     * 活動資訊與自己的獎項
     *
     * - 查無進行中的積寶活動時回傳 `null`
     *
     * OLD: /api/event/jackpot/show
     */
    public function show()
    {
        $userData = $this->getUserContext();

        return ApiResponse::success(
            $this->eventJackpotService->getEvent(
                $userData->member,
                $userData->member->agent ?? $userData->agent,
                $userData->languageId,
            )
        );
    }

    /**
     * 當個營業日的中獎名單
     *
     * - 營業日為 07:00 至隔日 07:00
     * - 帳號中間兩字以 `**` 遮蔽
     *
     * OLD: /api/event/jackpot/win_list
     */
    public function winList()
    {
        $userData = $this->getUserContext();

        return ApiResponse::success(
            $this->eventJackpotService->getWinList($userData->member->agent ?? $userData->agent)
        );
    }

    /**
     * 領取積寶獎勵
     *
     * OLD: /api/event/jackpot/receive
     */
    public function receive(ReceiveRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;

        $this->eventJackpotService->receive(
            $member,
            $member->agent ?? $userData->agent,
            (int) $input['id'],
        );

        return ApiResponse::success();
    }

    /**
     * 取得尚未通知的中獎彈窗
     *
     * OLD: /api/event/jackpot/check_notify
     */
    public function checkNotify()
    {
        $userData = $this->getUserContext();

        return ApiResponse::success(
            $this->eventJackpotService->getNotifications($userData->member, $userData->languageId)
        );
    }

    /**
     * 確認已看過中獎彈窗
     *
     * - 查無紀錄時靜默回成功，與舊系統 API_Frontend 相同
     *
     * OLD: /api/event/jackpot/confirm_notify
     */
    public function confirmNotify(ConfirmNotifyRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();

        $this->eventJackpotService->confirmNotification($userData->member, (int) $input['id']);

        return ApiResponse::success();
    }
}
