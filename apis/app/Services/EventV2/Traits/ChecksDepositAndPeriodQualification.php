<?php

declare(strict_types=1);

namespace App\Services\EventV2\Traits;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Event\PrizeMemberStatusEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Helpers\Common;
use App\Models\EventV2Detail;
use App\Models\Member;
use App\Models\Order;
use App\Models\PrizeMember;
use App\Models\TransactionLog;
use App\Services\EventV2\EventV2ValidateResult;
use Illuminate\Database\Eloquent\Collection;

/**
 * 各活動類型 getValidate() 共用的建構塊：每日/每週/每月參加去重、儲值條件驗證。
 *
 * 自 {@see \App\Services\EventV2\AbstractEventV2} 抽出，純粹依 EventV2Detail 設定與
 * 現有參加紀錄／訂單比對，不涉及子類別多型行為。
 */
trait ChecksDepositAndPeriodQualification
{
    protected function checkDaily(EventV2Detail $eventDetail, Collection $participations): EventV2ValidateResult
    {
        $date = $this->frontendTime->toDateString();
        if ($participations->where('event_v2_detail_id', $eventDetail->id)->where('date', $date)->isNotEmpty()) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::DAILY); // 您今天已經參加過這個活動，無法再次參加
        }

        return EventV2ValidateResult::success();
    }

    protected function checkWeekly(EventV2Detail $eventDetail, Collection $participations): EventV2ValidateResult
    {
        $week = $this->frontendTime->format('o-W');
        if ($participations->where('event_v2_detail_id', $eventDetail->id)->where('week', $week)->isNotEmpty()) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::WEEKLY); // 您這個週已經參加過這個活動，無法再次參加
        }

        return EventV2ValidateResult::success();
    }

    protected function checkMonthly(EventV2Detail $eventDetail, Collection $participations): EventV2ValidateResult
    {
        $month = $this->frontendTime->format('Y-m');
        if ($participations->where('event_v2_detail_id', $eventDetail->id)->where('month', $month)->isNotEmpty()) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::MONTHLY); // 您這個月已經參加過這個活動，無法再次參加
        }

        return EventV2ValidateResult::success();
    }

    protected function checkDeposit(EventV2Detail $eventDetail, Member $member): EventV2ValidateResult
    {
        $conditionFormula = $eventDetail->condition_formula;
        $todayDeposits = $this->getTodayDeposits($member->id);
        // target/tickets#4352：代理組別設置開關開啟時，後台手動開分也視為一筆候選儲值；
        // 只在真實儲值訂單全部不合格時才退而求其次。開關做在代理組別（非個別活動），
        // 因為要不要把後台開分算成儲值是站台整體的營運政策，不是單一活動的設定。
        $todayBackendDeposits = $member->agent->getSettingValue(AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT)
            ? $this->getTodayBackendDeposits($member->id)
            : new Collection;

        if ($todayDeposits->isEmpty() && $todayBackendDeposits->isEmpty()) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::DEPOSIT_NOT_ENOUGH); // 儲值尚未達到標準
        }

        // 批次查詢今日訂單中已被 V1 互斥獎品領取的 order id，避免逐筆查詢
        $awardedOrderIds = PrizeMember::join('prizes', function ($join) {
            $join->on('prizes.id', '=', 'prize_members.prize_id')
                ->where('prizes.cancel_other_reward', 1);
        })
            ->where('prize_members.member_id', $member->id)
            ->whereIn('prize_members.lottery_number', $todayDeposits->pluck('id'))
            ->where('prize_members.status', PrizeMemberStatusEnum::AWARD->value)
            ->pluck('prize_members.lottery_number');

        $qualifyingDeposit = null;
        $lastFailCode = EventV2ValidateCodeEnum::DEPOSIT_NOT_ENOUGH;

        foreach ($todayDeposits as $order) {
            // 此筆儲值訂單已被活動 V1 領取且該獎品設定為「領獎後取消其他同訂單活動獎勵」，視同「一次只能參加一個活動」
            if ($awardedOrderIds->contains($order->id)) {
                $lastFailCode = EventV2ValidateCodeEnum::ONLY_ONE_EVENT;

                continue;
            }

            if (str_contains($conditionFormula, 'deposit')) {
                $this->executor->setVar('deposit', (float) $order->amount);
                if ($this->executor->execute($conditionFormula) === false) {
                    $lastFailCode = EventV2ValidateCodeEnum::DEPOSIT_NOT_ENOUGH;

                    continue;
                }
            }

            $qualifyingDeposit = $order;
            break;
        }

        // 真實儲值訂單沒有一筆合格時，才退而求其次看後台開分（沒有 V1 互斥獎品的問題，後台開分不會被 V1 領走）
        if ($qualifyingDeposit === null) {
            foreach ($todayBackendDeposits as $backendDeposit) {
                if (str_contains($conditionFormula, 'deposit')) {
                    $this->executor->setVar('deposit', (float) $backendDeposit->amount);
                    if ($this->executor->execute($conditionFormula) === false) {
                        $lastFailCode = EventV2ValidateCodeEnum::DEPOSIT_NOT_ENOUGH;

                        continue;
                    }
                }

                $this->backendDeposit = $backendDeposit;
                $qualifyingDeposit = $backendDeposit;
                break;
            }
        }

        if ($qualifyingDeposit === null) {
            return EventV2ValidateResult::fail($lastFailCode);
        }

        if ($this->backendDeposit === null) {
            $this->deposit = $qualifyingDeposit;
        }

        // 領取去向＝積分：獎金領取時會整包換成積分，主錢包扣款若照做，玩家自己的儲值也會被換掉
        // ——不扣主錢包、也不拿主錢包餘額擋人
        if ($this->isScoreWithdraw($eventDetail)) {
            $this->mainWalletDeduction = null;

            return EventV2ValidateResult::success();
        }

        // condition_formula 含 deposit → 計算主錢包扣除金額並做餘額檢查
        $configDeduction = $eventDetail->condition_config['main_wallet_deduction'] ?? null;
        $deductionAmount = $configDeduction !== null
            ? min((float) Common::numAdd('0', (string) $configDeduction, 4), (float) $qualifyingDeposit->amount)
            : (float) $qualifyingDeposit->amount;
        // 計算結果為 0（含明確設定 0）視同不扣，mainWalletDeduction 維持 null
        $this->mainWalletDeduction = Common::numCompare($deductionAmount, '0') > 0 ? $deductionAmount : null;

        if ($this->mainWalletDeduction !== null && Common::numCompare($member->balance, $this->mainWalletDeduction) < 0) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::BALANCE_NOT_ENOUGH); // 錢包餘額不足
        }

        return EventV2ValidateResult::success();
    }

    /** @return Collection<int, Order> */
    private function getTodayDeposits(int $memberId): Collection
    {
        $timeOffset = $this->frontendTime->offsetHours;

        return Order::select('id', 'amount')
            ->where('member_id', $memberId)
            ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
            ->whereRaw('DATE_FORMAT(DATE_ADD(pay_time, INTERVAL ' . $timeOffset . ' HOUR), "%Y-%m-%d") = DATE_FORMAT(DATE_ADD(NOW(), INTERVAL ' . $timeOffset . ' HOUR), "%Y-%m-%d")')
            ->whereRaw('id NOT IN (SELECT order_id FROM event_v2_user_joins WHERE member_id = ' . $memberId . ' AND order_id != 0)')
            ->orderBy('pay_time', 'desc')
            ->get();
    }

    /**
     * target/tickets#4352：後台手動開分（`TransactionEnum::DEPOSIT`，CS 後台 newTransaction 建立、無對應 orders 列）
     * 視同儲值的候選清單。只在代理組別設置 `AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT`
     * 開啟時才會被呼叫。
     *
     * @return Collection<int, TransactionLog>
     */
    private function getTodayBackendDeposits(int $memberId): Collection
    {
        $timeOffset = $this->frontendTime->offsetHours;

        return TransactionLog::select('id', 'point as amount')
            ->where('member_id', $memberId)
            ->where('type', TransactionEnum::DEPOSIT->value)
            ->whereRaw('DATE_FORMAT(DATE_ADD(created_at, INTERVAL ' . $timeOffset . ' HOUR), "%Y-%m-%d") = DATE_FORMAT(DATE_ADD(NOW(), INTERVAL ' . $timeOffset . ' HOUR), "%Y-%m-%d")')
            ->whereRaw('id NOT IN (SELECT deposit_transaction_log_id FROM event_v2_user_joins WHERE member_id = ' . $memberId . ' AND deposit_transaction_log_id IS NOT NULL)')
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
