<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\Vip\RebateRecordData;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\MemberVip;
use App\Models\MemberVipPrize;
use App\Models\NewbieChallengeProgress;
use App\Models\RollingChipLog;
use App\Models\TransactionLog;
use App\Models\VipContentReward;
use App\Models\VipFlowLimit;
use App\Models\VipRebateSummaryRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class VipService
{
    public function __construct(
        protected WalletService $walletService,
    ) {}

    /**
     * vip_rewards.id：每日返水（receive_rebate 專用；與 getThisRebateRecord() 沿用同一寫死值）
     */
    private const int DAILY_REBATE_VIP_REWARD_ID = 7;

    /**
     * vip_rewards.id：達標獎金（receive 領取此類型時，需一併領取其他未領的達標獎金）
     */
    private const int ACHIEVEMENT_VIP_REWARD_ID = 4;

    /**
     * vip_flow_limits - VIP 稽核流水限制列表（分頁）
     *
     * @return array{data: \Illuminate\Support\Collection<int, VipFlowLimit>, total: int}
     */
    public function getFlowLimits(int $memberId, int $page, int $perPage): array
    {
        $query = VipFlowLimit::where('member_id', $memberId)->whereIn('status', [0, 1]);

        $total = $query->count();

        $data = $query->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return ['data' => $data, 'total' => $total];
    }

    /**
     * vip/receive - 領取單筆 VIP 獎勵
     *
     * 遷移自 API_Frontend VipController::receive（tpower-master 與 vw-master 逐行完全相同）。
     */
    public function claimReward(Member $member, int $memberVipPrizeId): void
    {
        // 鎖分模式中禁止領取獎勵
        if ($member->event_mode > 0) {
            throw new ApiException(__('error_message.1934'));
        }

        // V2 活動進行中禁止領取 V1 獎勵
        if ($member->is_event_v2) {
            throw new ApiException(__('error_message.1991'));
        }

        // 新人專享挑戰活動進行中禁止領取 V1 獎勵
        if (NewbieChallengeProgress::where('member_id', $member->id)
            ->whereIn('stage', [
                NewbieChallengeStageEnum::CHALLENGING->value,
                NewbieChallengeStageEnum::BONUS_PENDING->value,
                NewbieChallengeStageEnum::BONUS_WAGERING->value,
            ])
            ->exists()) {
            throw new ApiException(__('error_message.1992'));
        }

        $this->assertRealnameValidated($member);

        DB::transaction(function () use ($member, $memberVipPrizeId) {
            $lockedMember = Member::lockForUpdate()->find($member->id);

            // 舊版無鎖也不重驗狀態，同一筆待領獎勵併發領取時會重複派彩
            $prize = MemberVipPrize::query()
                ->where('member_id', $lockedMember->id)
                ->where('id', $memberVipPrizeId)
                ->where('status', 0)
                ->with('vipContentReward.vipReward')
                ->lockForUpdate()
                ->first();

            if (!$prize) {
                // 未中獎（含已領取，狀態被排除在查詢條件內）
                throw new ApiException(__('error_message.1946'));
            }

            if (!is_null($prize->expired_time) && now()->toDateTimeString() > $prize->expired_time) {
                // 已過期
                throw new ApiException(__('error_message.1953'));
            }

            $contentReward = $prize->vipContentReward;
            if (!$contentReward || $contentReward->status != 1) {
                // 獎勵不存在或已失效
                throw new ApiException(__('error_message.1941'));
            }

            if ($contentReward->point_type != $prize->point_type) {
                // 獎勵類型不符
                throw new ApiException(__('error_message.1944'));
            }

            $this->claimSinglePrize($lockedMember, $prize, $contentReward);

            // 達標獎金：領其中一筆時，一併領取會員其他未領取的達標獎金；其餘獎勵類型維持單筆領取
            if ((int) $contentReward->vip_reward_id === self::ACHIEVEMENT_VIP_REWARD_ID) {
                $this->claimOtherPendingAchievementRewards($lockedMember, (int) $prize->id);
            }
        });
    }

    /**
     * 派發單筆 VIP 獎勵金額並將該筆 member_vip_prizes 標記為已領取
     */
    private function claimSinglePrize(Member $member, MemberVipPrize $prize, VipContentReward $contentReward): void
    {
        $rewardPoint = $this->resolveVipRewardPoint($contentReward, $prize);
        $remark = (string) ($contentReward->vipReward->name ?? '');

        $this->creditVipReward($member, $contentReward, $rewardPoint, $remark, (int) $prize->id);

        $updateData = ['status' => 1];
        if (in_array($contentReward->type, [1, 2])) {
            $updateData['point'] = $rewardPoint;
        }
        $prize->update($updateData);
    }

    /**
     * 達標獎金（vip_content_rewards.vip_reward_id = 4）專屬：
     * 領取其中一筆達標獎金時，一併領取會員其他未領取（且未過期）的達標獎金。
     *
     * 個別獎金資料異常（獎勵已失效、金額或類型對不上）時跳過該筆，不影響
     * 使用者原本明確要領的那筆與其餘正常的達標獎金。
     */
    private function claimOtherPendingAchievementRewards(Member $member, int $excludePrizeId): void
    {
        $achievementContentRewardIds = VipContentReward::where('vip_reward_id', self::ACHIEVEMENT_VIP_REWARD_ID)
            ->where('status', 1)
            ->pluck('id');

        if ($achievementContentRewardIds->isEmpty()) {
            return;
        }

        $otherPrizes = MemberVipPrize::query()
            ->where('member_id', $member->id)
            ->where('id', '!=', $excludePrizeId)
            ->whereIn('vip_content_reward_id', $achievementContentRewardIds)
            ->where('status', 0)
            ->where(function ($query) {
                $query->where('expired_time', '>=', now()->toDateTimeString())
                    ->orWhereNull('expired_time');
            })
            ->with('vipContentReward.vipReward')
            ->lockForUpdate()
            ->get();

        foreach ($otherPrizes as $otherPrize) {
            $otherContentReward = $otherPrize->vipContentReward;
            if (!$otherContentReward || $otherContentReward->status != 1) {
                continue;
            }

            try {
                $this->claimSinglePrize($member, $otherPrize, $otherContentReward);
            } catch (ApiException) {
                // 資料異常（金額或類型對不上）跳過，不影響其餘達標獎金領取
                continue;
            }
        }
    }

    /**
     * vip/receive_rebate - 一次領取所有待領的每日返水獎勵
     *
     * 遷移自 API_Frontend VipController::receive_rebate（tpower-master 與 vw-master 逐行完全相同）。
     */
    public function claimRebateRewards(Member $member): void
    {
        // 鎖分模式中禁止領取獎勵
        if ($member->event_mode > 0) {
            throw new ApiException(__('error_message.1934'));
        }

        // V2 活動進行中禁止領取 V1 獎勵
        if ($member->is_event_v2) {
            throw new ApiException(__('error_message.1991'));
        }

        // 新人專享挑戰活動進行中禁止領取 V1 獎勵
        if (NewbieChallengeProgress::where('member_id', $member->id)
            ->whereIn('stage', [
                NewbieChallengeStageEnum::CHALLENGING->value,
                NewbieChallengeStageEnum::BONUS_PENDING->value,
                NewbieChallengeStageEnum::BONUS_WAGERING->value,
            ])
            ->exists()) {
            throw new ApiException(__('error_message.1992'));
        }

        $this->assertRealnameValidated($member);

        if (!MemberVip::where('member_id', $member->id)->exists()) {
            // 會員VIP不存在
            throw new ApiException(__('error_message.1941'));
        }

        // 查詢VIP返水獎勵，怕有之前等級未領取的，所以找全部
        $rebateContentRewardIds = VipContentReward::where('vip_reward_id', self::DAILY_REBATE_VIP_REWARD_ID)
            ->where('status', 1)
            ->pluck('id');

        if ($rebateContentRewardIds->isEmpty()) {
            // 獎勵不存在
            throw new ApiException(__('error_message.1941'));
        }

        DB::transaction(function () use ($member, $rebateContentRewardIds) {
            $lockedMember = Member::lockForUpdate()->find($member->id);

            $prizes = MemberVipPrize::query()
                ->where('member_id', $lockedMember->id)
                ->whereIn('vip_content_reward_id', $rebateContentRewardIds)
                ->where('status', 0)
                ->where(function ($query) {
                    $query->where('expired_time', '>=', now()->toDateTimeString())
                        ->orWhereNull('expired_time');
                })
                ->with('vipContentReward')
                ->lockForUpdate()
                ->get();

            if ($prizes->isEmpty()) {
                // 未中獎
                throw new ApiException(__('error_message.1946'));
            }

            foreach ($prizes as $prize) {
                $contentReward = $prize->vipContentReward;
                if (!$contentReward || $contentReward->status != 1) {
                    // 獎勵不存在
                    throw new ApiException(__('error_message.1941'));
                }

                $rewardPoint = Common::numAdd('0', (string) $prize->base_amount, 4);
                if (Common::numCompare((string) $contentReward->max_point, '0', 4) > 0
                    && Common::numCompare($rewardPoint, (string) $contentReward->max_point, 4) > 0
                ) {
                    // 如果金額上限大於0，且獎勵金額大於金額上限，則以金額上限為準
                    $rewardPoint = (string) $contentReward->max_point;
                }

                $remark = 'VIP返水獎勵-' . $prize->created_at->toFrontendTime()->toDateString();

                $this->creditVipReward($lockedMember, $contentReward, $rewardPoint, $remark, (int) $prize->id);

                $prize->update(['point' => $rewardPoint, 'status' => 1]);
            }
        });
    }

    /**
     * VIP 獎勵領取的實名認證門檻
     *
     * 與活動獎勵（`isEventRewardRealnameValidated()`）不同：VIP 獎勵**不看代理的
     * EVENT_REWARD_REALNAME_VALIDATION 開關，一律強制檢查**（PM 決定，2026-08-03）。
     * 舊版 API_Frontend VipController 完全沒有此檢查，屬刻意偏離舊版。
     */
    private function assertRealnameValidated(Member $member): void
    {
        if (!RealnameValidationService::isRealnameValidated($member)) {
            throw new ApiException(__('error_message.realname_invalid.event_reward'));
        }
    }

    /**
     * 計算單筆 VIP 獎勵應派發金額（type：0 固定金額／1 %數／2 額外返水），並套用金額上限
     */
    private function resolveVipRewardPoint(VipContentReward $contentReward, MemberVipPrize $prize): string
    {
        if ($contentReward->type == 0) {
            // 固定金額：抽獎當下寫入的 point 須與獎勵設定一致
            if (Common::numCompare((string) $contentReward->point, (string) $prize->point, 4) !== 0) {
                throw new ApiException(__('error_message.1944'));
            }
            $rewardPoint = (string) $contentReward->point;
        } elseif ($contentReward->type == 1) {
            // %數：需先達成 condition 門檻
            if (Common::numCompare((string) $prize->base_amount, (string) $contentReward->condition, 4) < 0) {
                throw new ApiException(__('error_message.1936'));
            }
            $rewardPoint = Common::numMul((string) $contentReward->point_percentage, (string) $prize->base_amount, 4);
        } elseif ($contentReward->type == 2) {
            // 額外返水：直接以 base_amount 為獎勵金額
            $rewardPoint = (string) $prize->base_amount;
        } else {
            throw new ApiException(__('error_message.1944'));
        }

        if (Common::numCompare((string) $contentReward->max_point, '0', 4) > 0
            && Common::numCompare($rewardPoint, (string) $contentReward->max_point, 4) > 0
        ) {
            // 如果金額上限大於0，且獎勵金額大於金額上限，則以金額上限為準
            $rewardPoint = (string) $contentReward->max_point;
        }

        return $rewardPoint;
    }

    /**
     * 依 vip_content_rewards.point_type 派發禮碼或平台點數，並視稽核流水倍數寫入 FlowLimit
     */
    private function creditVipReward(Member $member, VipContentReward $contentReward, string $rewardPoint, string $remark, int $orderId): void
    {
        $logId = $contentReward->point_type == 2
            ? $this->creditRollingChip($member, $rewardPoint, $remark)
            : $this->creditPlatformBalance($member, $rewardPoint, $orderId, $remark);

        if (Common::numCompare((string) $contentReward->flow_limit_multiple, '0', 4) > 0) {
            FlowLimit::create([
                'member_id' => $member->id,
                'type' => TransactionEnum::VIP_REWARD->value,
                'reference_id' => $logId,
                'flow_limit' => Common::numMul($rewardPoint, (string) $contentReward->flow_limit_multiple, 4),
                'reach' => 0,
                'status' => 0,
                'admin' => 0,
            ]);
        }
    }

    /**
     * 派發禮碼；沒有錢包時先建立，稽核流水寫在 RollingChipLog
     */
    private function creditRollingChip(Member $member, string $rewardPoint, string $remark): int
    {
        $wallet = $this->walletService->getLockedWallet($member->id, WalletEnum::CODE_ROLLING_CHIP);

        $beforeBalance = Common::numAdd('0', (string) $wallet->balance, 4);
        $afterBalance = Common::numAdd($beforeBalance, $rewardPoint, 4);
        $wallet->update(['balance' => $afterBalance]);

        $log = RollingChipLog::create([
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'vendor_id' => 0,
            'type' => RollingChipLogTypeEnum::VIP_REWARD->value,
            'amount' => $rewardPoint,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'remark' => $remark,
            'admin' => 0,
        ]);

        return (int) $log->id;
    }

    /**
     * 派發平台點數，稽核流水寫在 TransactionLog
     */
    private function creditPlatformBalance(Member $member, string $rewardPoint, int $orderId, string $remark): int
    {
        $beforeBalance = Common::numAdd('0', (string) $member->balance, 4);
        $afterBalance = Common::numAdd($beforeBalance, $rewardPoint, 4);
        $member->update(['balance' => $afterBalance]);

        $log = TransactionLog::create([
            'transaction_no' => $orderId,
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'order_id' => $orderId,
            'type' => TransactionEnum::VIP_REWARD->value,
            'rate' => 1,
            'point' => $rewardPoint,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'remark' => $remark,
            'operator' => 'system',
            'admin' => 0,
        ]);

        return (int) $log->id;
    }

    public function getLastRebateSummaryRecord(int $memberId, Carbon $thisMonday): VipRebateSummaryRecord
    {
        $lastMonday = $thisMonday->copy()->subWeek();

        $lastRebateRecords = VipRebateSummaryRecord::where('member_id', $memberId)
            ->where('summary_start_time', '=', $lastMonday->format('Y-m-d H:i:s'))
            ->where('summary_end_time', '=', $thisMonday->format('Y-m-d H:i:s'))
            ->select(
                DB::raw('SUM(bet) as total_bet'),
                DB::raw('SUM(rebate_amount) as rebate_amount')
            )
            ->first();
        if (is_null($lastRebateRecords)) {
            $lastLastMonday = $lastMonday->copy()->subWeek();
            $lastRebateRecords = VipRebateSummaryRecord::where('member_id', $memberId)
                ->where('summary_start_time', '=', $lastLastMonday->format('Y-m-d H:i:s'))
                ->where('summary_end_time', '=', $lastMonday->format('Y-m-d H:i:s'))
                ->select(
                    DB::raw('SUM(bet) as total_bet'),
                    DB::raw('SUM(rebate_amount) as rebate_amount')
                )
                ->first();
        }

        return $lastRebateRecords;
    }

    public function getThisRebateRecord(int $memberId, string $startTime, string $endTime): RebateRecordData
    {
        // TODO: refactor
        $subQuery = DB::table('three_hierarchy_bonus_report')
            ->where('summary_time', '>=', $startTime)
            ->where('summary_time', '<', $endTime)
            ->whereColumn('member_id', 'from_member_id')
            ->where('member_id', $memberId)
            ->where('rebate_type', 1)
            ->select(
                'member_id',
                DB::raw('SUM(bet) as bet'),
                'game_type_id'
            )
            ->groupBy('member_id', 'game_type_id');

        $record = DB::table(DB::raw("({$subQuery->toSql()}) as report_summary"))
            ->mergeBindings($subQuery)
            ->join('member_vips as mv', 'mv.member_id', '=', 'report_summary.member_id')
            ->join('vip_content_rewards as vcr', function ($join) {
                $join->on('mv.vip_content_id', '=', 'vcr.vip_content_id')
                    ->where('vcr.vip_reward_id', '=', 7);
            })
            ->join('vip_rebate_proportions as vrp', function ($join) {
                $join->on('vrp.vip_content_id', '=', 'mv.vip_content_id')
                    ->on('vrp.game_type_id', '=', 'report_summary.game_type_id');
            })
            ->select(
                DB::raw('SUM(report_summary.bet) as total_bet'),
                DB::raw('SUM(report_summary.bet * vrp.proportion) as rebate_amount')
            )
            ->first();

        return new RebateRecordData(
            total_bet: $record->total_bet,
            rebate_amount: $record->rebate_amount
        );
    }

    public function checkVipPrizeCount(int $memberId): int
    {
        $now = now();
        $count = 0;
        $memberVip = MemberVip::where('member_id', $memberId)->first();

        if ($memberVip !== null) {
            $count = MemberVipPrize::join('vip_content_rewards as vcr', function ($join) use ($memberVip) {
                $join->on('vcr.id', '=', 'member_vip_prizes.vip_content_reward_id')
                    ->where('vcr.vip_content_id', $memberVip->vip_content_id)
                    ->where('vcr.status', 1);
            })
                ->where(function ($query) use ($now) {
                    $query->where('member_vip_prizes.expired_time', '>=', $now)
                        ->orWhereNull('member_vip_prizes.expired_time');
                })
                ->where('member_vip_prizes.member_id', $memberId)
                ->where('member_vip_prizes.status', 0)
                ->count();
        }

        return $count;
    }
}
