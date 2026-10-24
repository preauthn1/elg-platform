<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\MemberStatistic;
use App\Services\Agent\Concerns\ChecksMemberScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 會員詳情抽屜——統計摘要 16 項（4274，口徑經 PM 三輪確認全數定案，
 * 定案紀錄見口徑提案文件 artifact 98b3162a）。
 *
 * 口徑總則：
 *  - 獎金類「真金＋禮碼共計」：真金記 transaction_logs、禮碼記 rolling_chip_logs，兩邊合計
 *  - VIP 體系取「發放總額」（含未領取，PM 定案）
 *  - 預設全期間；帶 start/end 為期間篩選（三層代改走日報表、最近存提時間降為小時精度）
 *  - 期間以後台時區（GMT+8）牆鐘輸入，服務端轉 UTC 查（4438 第 8 項）：資料表一律存 UTC，
 *    台北凌晨的交易才不會被算到前一天；只有三層代日報是「結算日」整天一筆，照日期標籤對、不換算
 *  - 各查詢皆為單一會員的索引範圍掃描或現成匯總表，TPower 實測整包 548ms（重度會員）
 */
class MemberDetailStatisticsService
{
    use ChecksMemberScope;

    /**
     * 優惠獎金——真金桶（transaction_logs.type）。
     * 定義＝「排除三層代/推薦/VIP 體系後的全部行銷獎金類、含活動 V2」（PM 定案）；
     * 刻意排除：17 全民代理(歸返水/佣金)、26 邀請(歸推薦獎金)、32 VIP、41 每周救援金(VIP 體系)、
     * 7 互發紅包、14/33 補帳調帳、105/108 股東分潤（PM 定案排除）。
     */
    private const EVENT_BONUS_TX_TYPES = [
        TransactionEnum::CHECKIN_REWARD->value,            // 10 打卡
        TransactionEnum::ACTIVITY_AWARD->value,            // 13 活動獎勵
        TransactionEnum::LOGIN_EVENT_REWARDS->value,       // 15 登入活動
        TransactionEnum::V_JACKPOT_REWARDS->value,         // 18 V-Jackpot
        TransactionEnum::FIRST_DEPOSIT_REWARDS->value,     // 19 首充返利
        TransactionEnum::REGISTRATION_GIFT->value,         // 20 註冊送
        TransactionEnum::BIND_MOBILE_PHONE_GIFT->value,    // 21 綁定手機送
        TransactionEnum::DEPOSIT_REWARDS->value,           // 24 充值返利
        TransactionEnum::PICK_UP_ONCE_A_DAY->value,        // 25 每日充值返利
        TransactionEnum::W_JACKPOT_REWARD->value,          // 30 W-Jackpot
        TransactionEnum::W_JACKPOT_6D_REWARD->value,       // 31 W-Jackpot 6D
        TransactionEnum::CYCLE_DEPOSIT_EVENT_REWARD->value, // 39 周期充值
        TransactionEnum::DAILY_CONSECUTIVE_DEPOSIT_REWARD->value, // 40 每日連續儲值
        TransactionEnum::EVENT_V2_BONUS->value,            // 71 活動 V2 彩金（PM 點名要含）
        TransactionEnum::SLOT_BATTLE->value,               // 101 老虎機爭霸
        TransactionEnum::LOSE_REBATE->value,               // 102 輸分返利活動
        TransactionEnum::JACKPOT->value,                   // 103 Jackpot
        TransactionEnum::PROMOTION_WALLET_REWARD->value,   // 104 優惠錢包獎勵
        TransactionEnum::COUPON_CODE->value,               // 107 優惠碼
        TransactionEnum::MISSION_REWARD->value,            // 109 任務獎勵
        TransactionEnum::NEWBIE_CHALLENGE_BONUS_PAYOUT->value, // 114 新人挑戰彩金
    ];

    /** 優惠獎金——真金負向（回收類，計入時扣回避免虛胖） */
    private const EVENT_BONUS_TX_RECOVER_TYPES = [
        TransactionEnum::RECYCLE_LOGIN_ACTIVITY_REWARDS->value, // 16 回收登入活動獎勵
    ];

    /** 優惠獎金——禮碼桶（rolling_chip_logs.type），與真金桶一一對應的禮碼發放型別 */
    private const EVENT_BONUS_RCL_TYPES = [
        RollingChipLogTypeEnum::BET_EVENT_REWARD->value,        // 6 投注活動
        RollingChipLogTypeEnum::LOGIN_EVENT_REWARD->value,      // 7 登入活動
        RollingChipLogTypeEnum::FIRST_DEPOSIT_REWARD->value,    // 9 首充
        RollingChipLogTypeEnum::REGISTER_REWARD->value,         // 10 註冊送
        RollingChipLogTypeEnum::BIND_MOBILE_PHONE_REWARD->value, // 11 綁定手機送
        RollingChipLogTypeEnum::DEPOSIT_ONCE_A_DAY_REWARD->value, // 12 每日充值
        RollingChipLogTypeEnum::W_Jackpot->value,               // 14
        RollingChipLogTypeEnum::W_Jackpot_6D->value,            // 15
        RollingChipLogTypeEnum::SLOT_MACHINE_COMPETITION->value, // 16 老虎機爭霸
        RollingChipLogTypeEnum::SLOT_LOSE_REBATE->value,        // 17 輸分返利
        RollingChipLogTypeEnum::Event_Jackpot->value,           // 18
        RollingChipLogTypeEnum::DEPOSIT_REWARD->value,          // 20 充值返利
        RollingChipLogTypeEnum::REFRESH_RANKING->value,         // 21 刷新排行榜
        RollingChipLogTypeEnum::DAILY_BET->value,               // 22 每日投注
        RollingChipLogTypeEnum::COUPON_CODE->value,             // 23 優惠碼
        RollingChipLogTypeEnum::DEPOSIT_CYCLE_TIME_REWARD->value, // 24 周期充值
        RollingChipLogTypeEnum::MISSION_REWARD->value,          // 25 任務獎勵
    ];

    /** 優惠獎金——禮碼負向（回收類） */
    private const EVENT_BONUS_RCL_RECOVER_TYPES = [
        RollingChipLogTypeEnum::RECOVER_EVENT_REWARD->value, // 8 回收活動獎勵
    ];

    /** vip_rewards.id＝每日返水（master data，dev/TPower 實查一致；返水 vs 其他 VIP 獎金的分界） */
    private const VIP_DAILY_REBATE_REWARD_ID = 7;

    /**
     * 統計摘要 16 項。$startTime/$endTime 為後台時區牆鐘的 Y-m-d H:i:s（含該秒），皆空＝全期間。
     *
     * @return array<string, mixed>
     */
    public function summary(Agent $agent, int $memberId, ?string $startTime = null, ?string $endTime = null): array
    {
        $this->findMemberInScope($agent, $memberId);

        $hasPeriod = $startTime !== null && $endTime !== null;
        // 到秒／到小時的來源表：後台時區牆鐘 → UTC
        $startAt = $hasPeriod ? $this->toUtc($startTime) : null;
        $endAt = $hasPeriod ? $this->toUtc($endTime) : null;
        // 三層代日報：結算日整天一筆（summary_time 固定 00:00:00），用牆鐘的日期標籤對
        $start = $hasPeriod ? substr($startTime, 0, 10) : null;
        $end = $hasPeriod ? substr($endTime, 0, 10) : null;

        $bet = $this->betGroup($memberId, $startAt, $endAt);
        $vipRebate = $this->vipPrizeSum($memberId, true, $startAt, $endAt);
        $vipBonus = $this->vipPrizeSum($memberId, false, $startAt, $endAt);
        [$threeHierarchyRebate, $referralCommission] = $this->threeHierarchyGroup($memberId, $hasPeriod, $start, $end);
        $eventBonus = $this->eventBonusSum($memberId, $startAt, $endAt);
        $referralBonus = $this->referralBonusSum($memberId, $startAt, $endAt);
        $depositWithdrawal = $this->depositWithdrawalGroup($memberId, $hasPeriod, $startAt, $endAt);

        // 返水＝VIP 每日返水＋三層代自身返水（PM 定案）
        $rebate = Common::numAdd($vipRebate, $threeHierarchyRebate, 4);

        return [
            // —— 投注（4 項；原「有效投注/有效流水」經 PM 定案合併為投注金額＝bet）——
            'bet_amount' => $bet['bet_amount'],
            'total_payout' => $bet['total_payout'],
            'member_win_lose' => $bet['member_win_lose'],
            'company_win_lose' => $bet['company_win_lose'],
            // —— 彩金與獎勵（4 項）——
            'rebate' => $rebate,
            'event_bonus' => $eventBonus,
            'vip_bonus' => $vipBonus,
            'rewards_total' => Common::numAdd(Common::numAdd($rebate, $eventBonus, 4), $vipBonus, 4),
            // —— 推薦（2 項）——
            'referral_commission' => $referralCommission,
            'referral_bonus' => $referralBonus,
            // —— 存款／提款（6 項）——
            ...$depositWithdrawal,
        ];
    }

    /** 後台時區牆鐘 → UTC 的 Y-m-d H:i:s（DB 一律存 UTC） */
    private function toUtc(string $wallClock): string
    {
        return Carbon::parse($wallClock, config('custom.backend.timezone'))->toSystemTime()->format('Y-m-d H:i:s');
    }

    /**
     * 投注組：summary_bet_records（會員×日×遊戲匯總；重度會員僅數百列）。
     * 對帳關係式：總中獎 − 投注金額 ＝ 會員輸贏 ＝ −公司輸贏。
     *
     * @return array<string, string>
     */
    private function betGroup(int $memberId, ?string $startAt, ?string $endAt): array
    {
        $row = DB::table('summary_bet_records')
            ->where('member_id', $memberId)
            ->when($startAt !== null, fn ($q) => $q->whereBetween('summary_time', [$startAt, $endAt]))
            ->selectRaw('IFNULL(SUM(bet), 0) as bet, IFNULL(SUM(win_lose), 0) as win_lose')
            ->first();

        $betAmount = Common::numAdd('0', (string) $row->bet, 4);
        $winLose = Common::numAdd('0', (string) $row->win_lose, 4);

        return [
            'bet_amount' => $betAmount,
            'total_payout' => Common::numAdd($betAmount, $winLose, 4),
            'member_win_lose' => $winLose,
            'company_win_lose' => Common::numSub('0', $winLose, 4),
        ];
    }

    /**
     * VIP 體系獎項（member_vip_prizes，發放總額含未領取）。
     * $dailyRebate true＝每日返水（歸「返水」）、false＝其餘名目（歸「VIP 獎金」）。
     */
    private function vipPrizeSum(int $memberId, bool $dailyRebate, ?string $startAt, ?string $endAt): string
    {
        $sum = DB::table('member_vip_prizes as mvp')
            ->join('vip_content_rewards as vcr', 'vcr.id', '=', 'mvp.vip_content_reward_id')
            ->where('mvp.member_id', $memberId)
            ->where('vcr.vip_reward_id', $dailyRebate ? '=' : '!=', self::VIP_DAILY_REBATE_REWARD_ID)
            ->when($startAt !== null, fn ($q) => $q->whereBetween('mvp.created_at', [$startAt, $endAt]))
            ->sum('mvp.point');

        return Common::numAdd('0', (string) $sum, 4);
    }

    /**
     * 三層代：[自身返水, 下線抽成（推薦佣金）]。
     * 全期間走累計桶（一會員一列，零成本）；期間篩選走日報表（member_id 索引，
     * 以 summary_time 結算日為期間基準）。兩者皆為真金/禮碼分軌前合計。
     *
     * @return array{0: string, 1: string}
     */
    private function threeHierarchyGroup(int $memberId, bool $hasPeriod, ?string $start, ?string $end): array
    {
        if (!$hasPeriod) {
            $bucket = DB::table('three_hierarchy_summary_bonus')
                ->where('member_id', $memberId)
                ->selectRaw('rebate_amount, down_rebate_amount + down2_rebate_amount as commission')
                ->first();

            return [
                Common::numAdd('0', (string) ($bucket->rebate_amount ?? 0), 4),
                Common::numAdd('0', (string) ($bucket->commission ?? 0), 4),
            ];
        }

        $row = DB::table('three_hierarchy_bonus_report')
            ->where('member_id', $memberId)
            ->whereBetween('summary_time', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->selectRaw('IFNULL(SUM(rebate), 0) as rebate, IFNULL(SUM(down_rebate + down2_rebate), 0) as commission')
            ->first();

        return [
            Common::numAdd('0', (string) $row->rebate, 4),
            Common::numAdd('0', (string) $row->commission, 4),
        ];
    }

    /**
     * 優惠獎金：真金桶＋禮碼桶合計，回收類（登入活動回收）以負向扣回。
     * 兩邊皆走 (member_id, type, status) 系列索引，單一會員獎勵類紀錄僅數百筆。
     */
    private function eventBonusSum(int $memberId, ?string $startAt, ?string $endAt): string
    {
        $txTypes = array_merge(self::EVENT_BONUS_TX_TYPES, self::EVENT_BONUS_TX_RECOVER_TYPES);
        $txRecover = implode(',', self::EVENT_BONUS_TX_RECOVER_TYPES);
        $cash = DB::table('transaction_logs')
            ->where('member_id', $memberId)
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->whereIn('type', $txTypes)
            ->when($startAt !== null, fn ($q) => $q->whereBetween('created_at', [$startAt, $endAt]))
            ->selectRaw("IFNULL(SUM(IF(type IN ({$txRecover}), -point, point)), 0) as total")
            ->value('total');

        $rclTypes = array_merge(self::EVENT_BONUS_RCL_TYPES, self::EVENT_BONUS_RCL_RECOVER_TYPES);
        $rclRecover = implode(',', self::EVENT_BONUS_RCL_RECOVER_TYPES);
        $chip = DB::table('rolling_chip_logs')
            ->where('member_id', $memberId)
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->whereIn('type', $rclTypes)
            ->when($startAt !== null, fn ($q) => $q->whereBetween('created_at', [$startAt, $endAt]))
            ->selectRaw("IFNULL(SUM(IF(type IN ({$rclRecover}), -amount, amount)), 0) as total")
            ->value('total');

        return Common::numAdd(
            Common::numAdd('0', (string) $cash, 4),
            Common::numAdd('0', (string) $chip, 4),
            4,
        );
    }

    /** 推薦獎金：邀請好友活動（真金 type 26＋禮碼 type 13） */
    private function referralBonusSum(int $memberId, ?string $startAt, ?string $endAt): string
    {
        $cash = DB::table('transaction_logs')
            ->where('member_id', $memberId)
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->where('type', TransactionEnum::INVITE_PLAYER_OFFERS->value)
            ->when($startAt !== null, fn ($q) => $q->whereBetween('created_at', [$startAt, $endAt]))
            ->sum('point');

        $chip = DB::table('rolling_chip_logs')
            ->where('member_id', $memberId)
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->where('type', RollingChipLogTypeEnum::INVITE_PLAYER_REWARD->value)
            ->when($startAt !== null, fn ($q) => $q->whereBetween('created_at', [$startAt, $endAt]))
            ->sum('amount');

        return Common::numAdd(
            Common::numAdd('0', (string) $cash, 4),
            Common::numAdd('0', (string) $chip, 4),
            4,
        );
    }

    /**
     * 存提 6 項，皆走 member_hourly_statistics（存款以 orders.pay_time、提款以
     * withdrawal_orders.payment_time 分桶，即「成功時間」口徑，與儀表板一致）。
     * 全期間四欄合計吃 idx_mhs_member_id_sum_covering（index-only）；期間篩選走
     * unique_index(member_id, summary_hour) 範圍掃描。次數與金額跟著期間走。
     * 最近存款／最後提款時間**一律取 member_statistics 的全期間最後一筆**（秒級），不受查詢期間影響，
     * 輸出轉後台時區（target/tickets#4426：原帶期間時取小時桶變整點、兩條路徑皆未轉 GMT+8，與帳號管理列表對不上）。
     *
     * 提款曾因歷史代付單 point=0（2026-07-20 前約九成）使 hourly 表金額缺漏而改直查
     * withdrawal_orders；各白標已於 2026-09-07 以 patch:backfill-member-hour-summaries
     * 用 amount × rate 回填（Me77 抽驗 681 筆／124,732.027 與源頭全等），故切回匯總表。
     *
     * @return array<string, mixed>
     */
    private function depositWithdrawalGroup(int $memberId, bool $hasPeriod, ?string $startAt, ?string $endAt): array
    {
        $sums = 'IFNULL(SUM(deposit_count), 0) as dc, IFNULL(SUM(deposit_amount), 0) as da, '
            . 'IFNULL(SUM(withdrawal_count), 0) as wc, IFNULL(SUM(withdrawal_amount), 0) as wa';

        if ($hasPeriod) {
            $row = DB::table('member_hourly_statistics')
                ->where('member_id', $memberId)
                ->whereBetween('summary_hour', [$startAt, $endAt])
                ->selectRaw($sums)
                ->first();
        } else {
            $row = DB::table('member_hourly_statistics')
                ->where('member_id', $memberId)
                ->selectRaw($sums)
                ->first();
        }

        $stat = MemberStatistic::query()->where('member_id', $memberId)->first();
        $lastDeposit = $stat?->last_deposit_at?->toBackendTime()->toDateTimeString();
        $lastWithdraw = $stat?->last_withdraw_at?->toBackendTime()->toDateTimeString();

        return [
            'deposit_count' => (int) $row->dc,
            'deposit_amount' => Common::numAdd('0', (string) $row->da, 4),
            'last_deposit_at' => (string) ($lastDeposit ?? ''),
            'withdrawal_count' => (int) $row->wc,
            'withdrawal_amount' => Common::numAdd('0', (string) $row->wa, 4),
            'last_withdraw_at' => (string) ($lastWithdraw ?? ''),
        ];
    }
}
