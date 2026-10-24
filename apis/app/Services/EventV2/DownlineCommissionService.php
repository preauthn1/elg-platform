<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Enums\EventV2\EventV2FieldEnum;
use App\Enums\EventV2\EventV2FieldParamKindEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\MemberCode;
use App\Models\SummaryBetRecord;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class DownlineCommissionService extends AbstractEventV2
{
    // 參數說明彈窗：條件配置讀 dayOfWeek；獎勵公式自己執行、不經 convertRewardFormula()，所以只有 win_lose 沒有 rand()，也不讀 limit
    protected const array HANDLER_FIELD_PARAMS = [
        EventV2FieldEnum::CONDITION_CONFIG->value => ['kind' => EventV2FieldParamKindEnum::KEYS, 'items' => ['max_join', 'dayOfWeek']],
        EventV2FieldEnum::REWARD_FORMULA->value => ['kind' => EventV2FieldParamKindEnum::VARIABLES, 'items' => ['win_lose']],
        EventV2FieldEnum::REWARD_CONFIG->value => ['kind' => EventV2FieldParamKindEnum::NONE, 'items' => []],
    ];

    private float $totalWinLose = 0;

    public function listValidate(EventV2Detail $eventDetail, Collection $participation, Member $member): bool
    {
        $week = $this->frontendTime->format('o-W');
        if ($participation->where('event_v2_detail_id', $eventDetail->id)->where('week', $week)->isNotEmpty()) {
            return false;
        }

        return true;
    }

    public function getValidate(EventV2Detail $eventDetail, Member $member, Collection $participations): EventV2ValidateResult
    {
        $result = $this->checkWeekly($eventDetail, $participations);
        if ($result->isFailed()) {
            return $result;
        }

        if (isset($eventDetail->condition_config['dayOfWeek']) && $eventDetail->condition_config['dayOfWeek'] > 0) {
            // 從星期一開始算今天第幾天
            $dayOfWeekIso = $this->frontendTime->dayOfWeekIso;
            if ($eventDetail->condition_config['dayOfWeek'] < $dayOfWeekIso) {
                return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::OVER_JOIN_TIME); // 已超過參加時間
            }
        }

        $this->totalWinLose = $this->getWinLose($eventDetail, $member);
        if ($this->totalWinLose == 0) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::WIN_LOSE); // 直屬下級會員結算不為輸分
        }

        return EventV2ValidateResult::success();
    }

    public function getReward(EventV2Detail $eventDetail, Member $member): float
    {
        if ($this->reward === null) {
            if (str_contains($eventDetail->reward_formula, 'win_lose')) {
                $this->executor->setVar('win_lose', $this->totalWinLose * -1);
            }

            $this->reward = (float) $this->executor->execute($eventDetail->reward_formula);
        }

        return $this->reward;
    }

    public function getBalanceLimit(EventV2Detail $eventDetail, Member $member): ?float
    {
        return $this->convertLimitFormula($eventDetail->balance_formula, $eventDetail, $member);
    }

    public function getFlowLimit(EventV2Detail $eventDetail, Member $member): ?float
    {
        return $this->convertLimitFormula($eventDetail->flow_formula, $eventDetail, $member);
    }

    public function getWithdrawLimit(EventV2Detail $eventDetail, Member $member): ?float
    {
        return $this->convertLimitFormula($eventDetail->withdraw_formula, $eventDetail, $member);
    }

    /**
     * 取得直屬下級的總輸分
     */
    private function getWinLose(EventV2Detail $eventDetail, Member $member): float
    {
        $memberCode = MemberCode::select('agent_code')->where('member_id', $member->id)->first();
        if ($memberCode === null) {
            throw new \Exception('member_code not found', 500);
        }

        $invitedMembers = Member::select('id')
            ->where('invitation_code', $memberCode->agent_code)
            ->get();

        [$start, $end] = $this->getTimeRange($eventDetail, $member);

        $winLoseOfWeek = SummaryBetRecord::selectRaw('YEARWEEK(data_time, 1) as yw') // 算出哪一年第幾周 EX: 202501
            ->selectRaw('SUM(win_lose + jackpot) as total_win_lose')
            ->selectRaw('data_time')
            ->whereIn('member_id', $invitedMembers->pluck('id')->toArray())
            ->whereBetween('data_time', [$start, $end])
            ->groupBy('yw')
            ->get();

        $conditionFormula = $eventDetail->condition_formula;

        $validWinLose = 0;
        foreach ($winLoseOfWeek as $item) {
            if (str_contains($conditionFormula, 'win_lose')) {
                $this->executor->setVar('win_lose', (float) $item->total_win_lose);
                $condition = $this->executor->execute($conditionFormula);

                if ($condition === true) {
                    $validWinLose += $item->total_win_lose;
                }
            }
        }

        return $validWinLose;
    }

    /**
     * condition_config['dayOfWeek']設定1~7都是抓上一周
     * condition_config['dayOfWeek']設定0就是累計上一次參加此活動或是活動開始時間
     */
    private function getTimeRange(EventV2Detail $eventDetail, Member $member): array
    {
        $start = $this->frontendTime->copy()->subWeeks(1)->startOfWeek()->toSystemTime()->toDateTimeString();
        $end = $this->frontendTime->copy()->subWeeks(1)->endOfWeek()->toSystemTime()->toDateTimeString();
        if (empty($eventDetail->condition_config)) {
            return [$start, $end];
        }

        // condition_config 非空但沒設 dayOfWeek（例如日後只帶其他 key）時，
        // 與整個 config 為空同義：一律抓上一週，不能直接讀 key
        if (!isset($eventDetail->condition_config['dayOfWeek']) || $eventDetail->condition_config['dayOfWeek'] > 0) {
            return [$start, $end];
        }

        $userJoin = EventV2UserJoin::where('member_id', $member->id)
            ->where('event_v2_detail_id', $eventDetail->id)
            ->orderBy('created_at', 'desc')
            ->first();

        // 如果沒參加過此活動，就以活動開始時間為基準
        $start = Carbon::parse($userJoin->created_at ?? $eventDetail->start_time)->toFrontendTime()->startOfWeek()->toSystemTime()->toDateTimeString();

        return [$start, $end];
    }
}
