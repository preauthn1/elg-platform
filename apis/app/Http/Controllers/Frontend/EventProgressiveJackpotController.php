<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\EventProgressiveJackpot\ReceiveRequest;
use App\Http\Resources\Frontend\EventProgressiveJackpot\EventProgressiveJackpotBetSettingResource;
use App\Http\Resources\Frontend\EventProgressiveJackpot\EventProgressiveJackpotLevelCardResource;
use App\Http\Resources\Frontend\EventProgressiveJackpot\EventProgressiveJackpotSettingResource;
use App\Http\Resources\Frontend\EventProgressiveJackpot\EventProgressiveJackpotSummaryResource;
use App\Services\Frontend\EventProgressiveJackpot\EventProgressiveJackpotService;
use Dedoc\Scramble\Attributes\Group;

#[Group('EventProgressiveJackpot (成長彩金計劃)')]
class EventProgressiveJackpotController extends Controller
{
    public function __construct(
        private EventProgressiveJackpotService $eventProgressiveJackpotService
    ) {}

    /**
     * 查詢目前檔位與解鎖進度
     */
    public function list()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $result = $this->eventProgressiveJackpotService->list($member, $userData->languageId);

        return ApiResponse::success([
            'setting' => EventProgressiveJackpotSettingResource::make($result['setting']),
            'summary' => EventProgressiveJackpotSummaryResource::make($result['summary']),
            'levels' => EventProgressiveJackpotLevelCardResource::collection($result['levels']),
            'bet_settings' => EventProgressiveJackpotBetSettingResource::collection($result['bet_settings']),
        ]);
    }

    /**
     * 領取指定檔位已解鎖彩金
     */
    public function receive(ReceiveRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $result = $this->eventProgressiveJackpotService->receive($member, (int) $request->level);

        return ApiResponse::success([
            'summary' => EventProgressiveJackpotSummaryResource::make($result['summary']),
            'levels' => EventProgressiveJackpotLevelCardResource::collection($result['levels']),
        ]);
    }
}
