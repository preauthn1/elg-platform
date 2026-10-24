<?php

declare(strict_types=1);

namespace App\SP;

use App\Enums\WalletEnum;
use App\Models\EventProcess;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Models\RollingChipLog;
use App\Models\TransactionLog;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Throwable;

class SP_payout_event_invite
{
    /**
     * @throws Throwable
     */
    public function handle($memberId, $eventId, $eventDetailId, $prizeId)
    {
        return DB::transaction(function () use ($memberId, $eventId, $eventDetailId, $prizeId) {
            // 初始設定
            $member = Member::findOrFail($memberId);
            $agentId = $member->parent;
            $transactionLogType = 26;
            $rollingChipLogType = 13;
            $transactionNo = now()->format('YmdHis') . rand(1000, 9999);

            // 查詢獎項內容
            $prizeContent = PrizeContent::where('prize_id', $prizeId)->first();
            if (!$prizeContent) {
                return false;
            }

            $pointType = $prizeContent->point_type;
            $rewardPoint = $prizeContent->point;
            $remark = $prizeContent->name;
            $flowLimitMultiple = Prize::find($prizeId)->flow_limit_multiple;
            if ($rewardPoint <= 0) {
                return false;
            }

            // 寫入 event_processes
            $eventProcess = EventProcess::firstOrCreate(
                ['detail_id' => $eventDetailId, 'member_id' => $memberId],
                ['point' => 1, 'received' => 1, 'status' => 0, 'admin' => 0]
            );

            if (!$eventProcess->wasRecentlyCreated) {
                $eventProcess->increment('point');
                $eventProcess->increment('received');
            }

            // 寫入獎勵紀錄與餘額更新
            if ($pointType == 1) {
                $beforeBalance = $member->balance;
                $member->increment('balance', $rewardPoint);

                $transaction = TransactionLog::create([
                    'transaction_no' => $transactionNo,
                    'agent_id' => $agentId,
                    'member_id' => $memberId,
                    'vendor_id' => 0,
                    'order_id' => 0,
                    'type' => $transactionLogType,
                    'point' => $rewardPoint,
                    'vendor_point' => 0,
                    'rate' => 1,
                    'before_balance' => $beforeBalance,
                    'balance' => $beforeBalance + $rewardPoint,
                    'vendor_balance' => 0,
                    'status' => 1000,
                    'remark' => $remark,
                    'result' => 0,
                    'operator' => 'system',
                    'platform' => 0,
                    'admin' => 51,
                ]);
                $referenceId = $transaction->id;
            } elseif ($pointType == 2) {
                $wallet = (new WalletService)->getOrCreateWallet($memberId, WalletEnum::CODE_ROLLING_CHIP);

                $beforeRollingChip = $wallet->balance;
                $wallet->increment('balance', $rewardPoint);

                $log = RollingChipLog::create([
                    'agent_id' => $agentId,
                    'member_id' => $memberId,
                    'vendor_id' => 0,
                    'type' => $rollingChipLogType,
                    'amount' => $rewardPoint,
                    'before_balance' => $beforeRollingChip,
                    'balance' => $beforeRollingChip + $rewardPoint,
                    'status' => 1000,
                    'remark' => $remark,
                    'admin' => 51,
                ]);
                $referenceId = $log->id;
            }

            // 寫入 prize_members
            PrizeMember::create([
                'member_id' => $memberId,
                'prize_id' => $prizeId,
                'content_id' => $prizeContent->id,
                'event_detail_id' => $eventDetailId,
                'lottery_point' => $rewardPoint,
                'status' => 1,
                'admin' => 51,
            ]);

            // 寫入 flow_limits
            if ($flowLimitMultiple > 0) {
                FlowLimit::create([
                    'member_id' => $memberId,
                    'type' => $transactionLogType,
                    'event_id' => $eventId,
                    'reference_id' => $transactionNo,
                    'flow_limit' => $rewardPoint * $flowLimitMultiple,
                    'reach' => 0,
                    'admin' => 0,
                ]);
            }

            return true;
        });
    }
}
