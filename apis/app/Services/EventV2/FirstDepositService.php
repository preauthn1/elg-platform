<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Enums\Event\PrizeMemberStatusEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Enums\OrderStatusEnum;
use App\Helpers\Common;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\Order;
use App\Models\PrizeMember;
use Illuminate\Database\Eloquent\Collection;

class FirstDepositService extends AbstractEventV2
{
    protected const bool READS_DEPOSIT = true; // 參數說明彈窗：儲值類，條件公式 deposit、條件配置 main_wallet_deduction

    public function listValidate(EventV2Detail $eventDetail, Collection $participation, Member $member): bool
    {
        if ($participation->where('event_v2_detail_id', $eventDetail->id)->isNotEmpty()) {
            return false;
        }

        return true;
    }

    public function getValidate(EventV2Detail $eventDetail, Member $member, Collection $participations): EventV2ValidateResult
    {
        if ($participations->where('event_v2_detail_id', $eventDetail->id)->isNotEmpty()) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::HAS_JOIN); // 您已經參加過這個活動，無法再次參加
        }

        return $this->checkFirstDeposit($eventDetail, $member);
    }

    /**
     * 「首儲」只認活動開始後的第一筆訂單本身，不找替代——不合格、被 V1 互斥獎品領走、
     * 已被拿去參加別的活動 V2，一律直接判定不符資格，不會退而求其次改看第二筆訂單。
     */
    protected function checkFirstDeposit(EventV2Detail $eventDetail, Member $member): EventV2ValidateResult
    {
        $order = $this->getFirstEventDeposit($eventDetail, $member->id);

        if ($order === null) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::DEPOSIT_NOT_ENOUGH); // 儲值尚未達到標準
        }

        // 此筆訂單已被活動 V1 領取且該獎品設定為「領獎後取消其他同訂單活動獎勵」，視同「一次只能參加一個活動」
        $isAwardedByV1 = PrizeMember::join('prizes', function ($join) {
            $join->on('prizes.id', '=', 'prize_members.prize_id')
                ->where('prizes.cancel_other_reward', 1);
        })
            ->where('prize_members.member_id', $member->id)
            ->where('prize_members.lottery_number', $order->id)
            ->where('prize_members.status', PrizeMemberStatusEnum::AWARD->value)
            ->exists();

        if ($isAwardedByV1) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::DEPOSIT_ALREADY_AWARDED); // 此筆儲值已被其他獎項領取，無法用於本活動
        }

        // 此筆訂單已被拿去參加別的活動 V2
        $isConsumedByOtherEvent = EventV2UserJoin::where('member_id', $member->id)
            ->where('order_id', $order->id)
            ->exists();

        if ($isConsumedByOtherEvent) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::DEPOSIT_ALREADY_USED); // 此筆儲值已用於參加其他活動
        }

        $conditionFormula = $eventDetail->condition_formula;
        if (str_contains($conditionFormula, 'deposit')) {
            $this->executor->setVar('deposit', (float) $order->amount);
            if ($this->executor->execute($conditionFormula) === false) {
                return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::DEPOSIT_NOT_ENOUGH); // 儲值尚未達到標準
            }
        }

        $this->deposit = $order;

        // 領取去向＝積分：不扣主錢包、也不拿主錢包餘額擋人（同 ChecksDepositAndPeriodQualification::checkDeposit）
        if ($this->isScoreWithdraw($eventDetail)) {
            $this->mainWalletDeduction = null;

            return EventV2ValidateResult::success();
        }

        // condition_formula 含 deposit → 計算主錢包扣除金額並做餘額檢查
        $configDeduction = $eventDetail->condition_config['main_wallet_deduction'] ?? null;
        $deductionAmount = $configDeduction !== null
            ? min((float) Common::numAdd('0', (string) $configDeduction, 4), (float) $order->amount)
            : (float) $order->amount;
        // 計算結果為 0（含明確設定 0）視同不扣，mainWalletDeduction 維持 null
        $this->mainWalletDeduction = Common::numCompare($deductionAmount, '0') > 0 ? $deductionAmount : null;

        if ($this->mainWalletDeduction !== null && Common::numCompare($member->balance, $this->mainWalletDeduction) < 0) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::BALANCE_NOT_ENOUGH); // 錢包餘額不足
        }

        return EventV2ValidateResult::success();
    }

    /**
     * 活動開始後 pay_time 最早的一筆已完成儲值訂單。
     */
    private function getFirstEventDeposit(EventV2Detail $eventDetail, int $memberId): ?Order
    {
        return Order::select('id', 'amount')
            ->where('member_id', $memberId)
            ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
            ->where('pay_time', '>=', $eventDetail->start_time)
            ->orderBy('pay_time', 'asc')
            ->first();
    }

    public function getReward(EventV2Detail $eventDetail, Member $member): float
    {
        if ($this->reward === null) {
            $this->reward = $this->convertRewardFormula($eventDetail, $member);
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
}
