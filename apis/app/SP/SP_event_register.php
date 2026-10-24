<?php

declare(strict_types=1);

namespace App\SP;

use App\Models\Agent;
use App\Models\EventDetail;
use App\Models\Member;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Models\TransactionLog;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SP_event_register
 * 用於處理註冊活動的獎勵分發邏輯
 *
 * 活動條件檢查：確保活動和代理符合條件。
 * 獎品條件檢查：確保獎品數量足夠，並避免重複領取。
 * 獎品發放：根據不同類型的獎品進行正確的發放和記錄。
 * 稽核流水：正確計算和記錄稽核流水。
 * 異常處理：捕獲異常並發送通知。
 */
class SP_event_register
{
    /**
     * 註冊活動處理邏輯
     *
     *
     * @throws Exception
     * @throws Throwable
     */
    public function handle(int $memberId, int $agentId, string $type = 'regi'): void
    {
        DB::beginTransaction();

        try {
            // ===========================
            // 1. 初始化變數
            // ===========================
            $transactionLogType = 19;
            $rollingChipType = 9;
            $remarkText = '註冊活動獎勵';
            $rewardPoint = 0;
            $flowLimitRate = 0;
            $operator = 'system';
            $operatorId = 51;
            $done = false;

            // 查詢會員基本信息
            $member = Member::with('agent')->findOrFail($memberId);
            $agent = $member->agent;
            $beforeBalance = $member->balance;
            $registerIp = $member->register_ip;
            $registerMobile = $member->mobile;
            $registerFirstName = $member->first_name;
            $hierarchyPath = $agent->hierarchy_path;

            // 獲取當前活動
            $eventDetails = EventDetail::with(['event', 'prizes'])
                ->where('action_id', 11)
                ->where('status', 1)
                ->where('start_time', '<=', now())
                ->where('end_time', '>=', now())
                ->get();

            foreach ($eventDetails as $eventDetail) {
                $event = $eventDetail->event;

                // ===========================
                // 2. 檢查是否符合活動條件
                // ===========================
                if (!$this->isEligibleForEvent($type, $eventDetail, $registerFirstName)) {
                    continue;
                }

                // 檢查是否為此代理線
                if (!$this->isAgentEligible($agentId, $event->agent_id, $hierarchyPath)) {
                    continue;
                }

                // 判斷是否為排除代理
                if ($this->isExcludedAgent($event->id, $hierarchyPath)) {
                    continue;
                }

                // ===========================
                // 3. 計算獎品數量和百分比
                // ===========================
                $prize = $this->getAvailablePrize($eventDetail->prizes);
                if (!$prize) {
                    continue;
                }

                // ===========================
                // 4. 檢查IP/mobile/account是否重覆
                // ===========================
                if (!$this->checkDuplicateConditions($member, $prize, $eventDetail)) {
                    continue;
                }

                // ===========================
                // 5. 處理獎品分發
                // ===========================
                $this->processPrizeDistribution($member, $prize, $eventDetail, $beforeBalance, $operatorId);
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Event Register Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 檢查是否符合活動條件
     */
    private function isEligibleForEvent(string $type, EventDetail $eventDetail, ?string $registerFirstName): bool
    {
        if (($type != 'account' && $eventDetail->account_block == 0) ||
            ($type != 'account' && $registerFirstName !== null) ||
            ($type == 'account' && $eventDetail->account_block == 1)) {
            return true;
        }

        return false;
    }

    /**
     * 檢查是否為此代理線
     */
    private function isAgentEligible(int $agentId, int $platformAgentId, string $hierarchyPath): bool
    {
        return Agent::where('id', $agentId)
            ->where('hierarchy', 'LIKE', "%,$platformAgentId,%")
            ->exists();
    }

    /**
     * 判斷是否為排除代理
     */
    private function isExcludedAgent(int $eventId, string $hierarchyPath): bool
    {
        return DB::table('event_exclude_agents as eea')
            ->join('agents as a', 'a.id', '=', 'eea.agent_id')
            ->where('eea.event_id', $eventId)
            ->where('a.hierarchy_path', 'LIKE', "$hierarchyPath%")
            ->exists();
    }

    /**
     * 獲取可用獎品
     */
    private function getAvailablePrize($prizes)
    {
        foreach ($prizes as $prize) {
            $availablePrizeContent = PrizeContent::where('prize_id', $prize->id)
                ->where('status', 1)
                ->where(function ($query) {
                    $query->where('number', -1)
                        ->orWhereRaw('(number - sent) > 0');
                })
                ->inRandomOrder()
                ->first();

            if ($availablePrizeContent) {
                return $availablePrizeContent;
            }
        }

        return null;
    }

    /**
     * 檢查 IP、手機號碼、帳號是否重複
     */
    private function checkDuplicateConditions(Member $member, Prize $prize, EventDetail $eventDetail): bool
    {
        $eventStartTime = $eventDetail->start_time;
        $eventEndTime = $eventDetail->end_time;

        // 檢查 AccountBlock 條件
        if ($eventDetail->account_block == 1) {
            if ($member->first_name === null && $eventDetail->type != 'account') {
                return false;
            }

            $accountExists = PrizeMember::join('members as m', 'm.id', '=', 'prize_members.member_id')
                ->where('m.first_name', $member->first_name)
                ->whereBetween('m.created_at', [$eventStartTime, $eventEndTime])
                ->where('prize_members.prize_id', $prize->id)
                ->where('prize_members.event_detail_id', $eventDetail->id)
                ->where('prize_members.member_id', '!=', $member->id)
                ->exists();

            if ($accountExists) {
                return false;
            }
        }

        // 檢查 IpBlock 條件
        if ($eventDetail->ip_block == 1) {
            $ipExists = PrizeMember::join('members as m', 'm.id', '=', 'prize_members.member_id')
                ->where('m.register_ip', $member->register_ip)
                ->whereBetween('m.created_at', [$eventStartTime, $eventEndTime])
                ->where('prize_members.prize_id', $prize->id)
                ->where('prize_members.event_detail_id', $eventDetail->id)
                ->where('prize_members.member_id', '!=', $member->id)
                ->exists();

            if ($ipExists) {
                return false;
            }
        }

        // 檢查 PhoneBlock 條件
        if ($eventDetail->phone_block == 1) {
            $phoneExists = PrizeMember::join('members as m', 'm.id', '=', 'prize_members.member_id')
                ->where('m.mobile', $member->mobile)
                ->whereBetween('m.created_at', [$eventStartTime, $eventEndTime])
                ->where('prize_members.prize_id', $prize->id)
                ->where('prize_members.event_detail_id', $eventDetail->id)
                ->where('prize_members.member_id', '!=', $member->id)
                ->exists();

            if ($phoneExists) {
                return false;
            }
        }

        return true;
    }

    /**
     * 分發獎品, 更新會員餘額、記錄交易日誌等
     */
    private function processPrizeDistribution(Member $member, Prize $prize, EventDetail $eventDetail, float $beforeBalance, int $operatorId): void
    {
        if ($prize->number <= 0) {
            return;
        }

        $prizeContent = $this->getPrizeContent($prize);
        if (!$prizeContent) {
            return;
        }

        if ($this->hasAlreadyClaimedPrize($member, $prize, $eventDetail)) {
            return;
        }

        if ($this->canDistributePrize($prizeContent)) {
            $rewardPoint = $this->calculateRewardPoint($prizeContent, $eventDetail);
            $this->recordPrizeDistribution($member, $prize, $prizeContent, $eventDetail, $rewardPoint, $operatorId);
            $this->updateMemberBalance($member, $rewardPoint);
            $this->logTransaction($member, $rewardPoint, $beforeBalance, $prizeContent->name);
            $this->incrementPrizeSentCount($prizeContent);
        }
    }

    /**
     * 獲取獎品內容
     */
    private function getPrizeContent(Prize $prize)
    {
        return PrizeContent::where('prize_id', $prize->id)
            ->where('status', 1)
            ->orderByRaw('RAND()')
            ->first();
    }

    /**
     * 檢查會員是否已經領取過獎品
     */
    private function hasAlreadyClaimedPrize(Member $member, Prize $prize, EventDetail $eventDetail): bool
    {
        $query = PrizeMember::where('member_id', $member->id)
            ->where('prize_id', $prize->id)
            ->where('event_detail_id', $eventDetail->id);

        if ($prize->is_repeatedly == 1) {
            return $query->exists();
        } elseif ($prize->is_repeatedly == 2) {
            return $query->whereDate('created_at', now())->exists();
        }

        return $query->exists();
    }

    /**
     * 檢查獎品是否可以分發
     */
    private function canDistributePrize(PrizeContent $prizeContent): bool
    {
        return $prizeContent->number == -1 || ($prizeContent->number - $prizeContent->sent) > 0;
    }

    /**
     * 計算獎勵點數
     */
    private function calculateRewardPoint(PrizeContent $prizeContent, EventDetail $eventDetail): float
    {
        return $prizeContent->point_type == 1
            ? $eventDetail->deposit_point * $prizeContent->point_percentage
            : $prizeContent->point;
    }

    /**
     * 記錄獎品分發
     */
    private function recordPrizeDistribution(Member $member, Prize $prize, PrizeContent $prizeContent, EventDetail $eventDetail, float $rewardPoint, int $operatorId): void
    {
        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => $prize->id,
            'content_id' => $prizeContent->id,
            'event_detail_id' => $eventDetail->id,
            'lottery_point' => $rewardPoint,
            'status' => 1,
            'admin' => $operatorId,
        ]);
    }

    /**
     * 更新會員餘額
     */
    private function updateMemberBalance(Member $member, float $rewardPoint): void
    {
        $member->balance += $rewardPoint;
        $member->save();
    }

    /**
     * 記錄交易日誌
     */
    private function logTransaction(Member $member, float $rewardPoint, float $beforeBalance, string $remark): void
    {
        TransactionLog::create([
            'transaction_no' => now()->format('YmdHis') . rand(1000, 9999),
            'agent_id' => $member->agent_id,
            'member_id' => $member->id,
            'type' => 19,
            'point' => $rewardPoint,
            'before_balance' => $beforeBalance,
            'balance' => $member->balance,
            'status' => 1000,
            'remark' => $remark,
            'operator' => 'system',
        ]);
    }

    /**
     * 增加獎品已派發數量
     */
    private function incrementPrizeSentCount(PrizeContent $prizeContent): void
    {
        $prizeContent->increment('sent');
    }
}
