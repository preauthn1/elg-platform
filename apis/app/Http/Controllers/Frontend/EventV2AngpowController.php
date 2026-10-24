<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\EventV2\DetailRequest;
use App\Http\Resources\Frontend\EventV2\AngpowInfoResource;
use App\Models\EventV2Detail;
use App\Services\EventV2\AngpowService;
use App\Services\EventV2\EventV2Service;
use Dedoc\Scramble\Attributes\Group;

/**
 * 紅包（AngpowService）前台專用接口（target/tickets#4234）。
 *
 * 比照 jackpot／邀請好友「一個活動一個 Controller」的慣例：畫面要的數字從這裡拿；
 * 「抽」仍走通用的 event-v2/join（同一條 validate → join 管線，不另寫一套扣分／上限邏輯），
 * join 回應多帶 reward 就是抽到的金額。活動 id 一律由前端從 event/list 拿 event_v2_id 帶來，與 detail／join 相同。
 */
#[Group('EventV2 (活動 V2)')]
class EventV2AngpowController extends Controller
{
    public function __construct(
        protected EventV2Service $eventV2Service,
    ) {}

    /**
     * 紅包畫面資訊
     *
     * 回活動是否開啟中（活動期間內且在可抽時段內，前端據此決定要不要展示紅包）＋畫面要的數字：每抽扣幾分、可用積分、
     * 個人／全站次數的上限與剩餘、全站金額的上限與剩餘、領取間隔秒數與倒數、可抽時段（前台時區）。
     * 不做 validate：能不能抽、為什麼不能抽由 event-v2/join 失敗時的 message 回，這支純查詢、不寫拒絕紀錄。
     */
    public function info(DetailRequest $request)
    {
        $member = $this->getUserContext()->member;
        $eventDetailId = (int) $request->event_detail_id;

        $eventDetail = EventV2Detail::query()->find($eventDetailId);
        if ($eventDetail === null) {
            return ApiResponse::error(__('event_v2.validation.not_angpow'));
        }

        $eventClass = $this->eventV2Service->getEventClass($eventDetailId);
        if (!$eventClass instanceof AngpowService) {
            return ApiResponse::error(__('event_v2.validation.not_angpow'));
        }

        return ApiResponse::success(AngpowInfoResource::make($eventClass->frontendInfo($eventDetail, $member)));
    }
}
