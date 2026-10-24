<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\BetRecord\GetAccumulatedBetsRequest;
use App\Http\Resources\Frontend\BetRecord\AccumulatedBetsResource;
use App\Models\SummaryBetRecord;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;

#[Group('Bet Record (押注紀錄)')]
class BetRecordController extends Controller
{
    /**
     * 玩家查詢今日與昨日的累積押注額度
     */
    public function accumulatedBets(GetAccumulatedBetsRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $vendorId = $request->input('vendor_id');

        $todayStartTime = Carbon::now()->toFrontendTime()->startOfDay()->toSystemTime();
        $todayEndTime = $todayStartTime->copy()->addDay();
        $yesterdayStartTime = $todayStartTime->copy()->subDay();
        $yesterdayEndTime = $yesterdayStartTime->copy()->addDay();

        $todayBetSum = SummaryBetRecord::where('data_time', '>=', $todayStartTime)
            ->where('data_time', '<', $todayEndTime)
            ->where('member_id', $member->id)
            ->where('vendor_id', $vendorId)
            ->sum('bet');

        $yesterdayBetSum = SummaryBetRecord::where('data_time', '>=', $yesterdayStartTime)
            ->where('data_time', '<', $yesterdayEndTime)
            ->where('member_id', $member->id)
            ->where('vendor_id', $vendorId)
            ->sum('bet');

        return ApiResponse::success(AccumulatedBetsResource::make([
            'today_bet_sum' => $todayBetSum,
            'yesterday_bet_sum' => $yesterdayBetSum,
        ]));
    }
}
