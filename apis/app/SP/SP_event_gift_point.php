<?php

declare(strict_types=1);

namespace App\SP;

use App\Enums\WalletEnum;
use App\Models\Agent;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\RollingChipLog;
use App\Models\TransactionLog;
use App\Services\AgentService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Throwable;

class SP_event_gift_point
{
    public function __construct(
        protected AgentService $agentService,
        protected WalletService $walletService,
    ) {}

    /**
     * 處理活動獎勵派發
     *
     * @throws Throwable
     */
    public function handle(int $type, Member $member): bool
    {
        return DB::transaction(function () use ($type, $member) {
            $agent = $member->agent;

            // 檢查是否有活動獎勵
            $giftPoint = $this->getGiftPoint($type, $agent);
            $giftType = $this->getGiftType($type, $agent);
            $flowLimitRate = $this->getFlowLimitRate($agent);

            if ($giftPoint <= 0 || $this->hasReceivedGift($type, $member)) {
                return false;
            }

            if ($giftType == 1) {
                $this->handleCashGift($member, $giftPoint, $type);
            } elseif ($giftType == 2) {
                $this->handleRollingChipGift($member, $giftPoint, $type);
            }

            if ($flowLimitRate > 0) {
                $this->createFlowLimit($member, $type, $giftPoint, $flowLimitRate);
            }

            return true;
        });
    }

    /**
     * 取得活動獎勵點數
     */
    private function getGiftPoint(int $type, Agent $agent): float
    {
        $settingKey = $type == 1 ? 6501 : 6502; // 註冊送或綁定手機送
        $setting = $this->agentService->getSpecificAgentSetting($agent, $settingKey);
        $setting = isset($setting) ? (float) $setting : 0;

        return $setting;
    }

    /**
     * 取得獎勵類型
     */
    private function getGiftType(int $type, Agent $agent): int
    {
        $settingKey = $type == 1 ? 6504 : 6505; // 註冊送或綁定手機送
        $setting = $this->agentService->getSpecificAgentSetting($agent, $settingKey);
        $setting = isset($setting) ? (int) $setting : 1;

        return $setting;
    }

    /**
     * 檢查是否已經收到獎勵
     */
    private function hasReceivedGift(int $type, Member $member): bool
    {
        return TransactionLog::query()
            ->where('member_id', $member->id)
            ->where('type', $type == 1 ? 20 : 21) // 註冊送或綁定手機送
            ->exists();
    }

    /**
     * 處理現金獎勵
     */
    private function handleCashGift(Member $member, float $giftPoint, int $type): void
    {
        $beforeBalance = $member->balance;
        $member->increment('balance', $giftPoint);

        TransactionLog::create([
            'transaction_no' => now()->format('YmdHis') . rand(1000, 9999),
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'type' => $type == 1 ? 20 : 21,
            'point' => $giftPoint,
            'before_balance' => $beforeBalance,
            'balance' => $beforeBalance + $giftPoint,
            'status' => 1000,
            'remark' => $type == 1 ? '注册送' : '绑定手机送',
            'admin' => 51,
        ]);
    }

    /**
     * 處理禮碼獎勵
     */
    private function handleRollingChipGift(Member $member, float $giftPoint, int $type): void
    {
        $wallet = $this->walletService->getOrCreateWallet($member->id, WalletEnum::CODE_ROLLING_CHIP);

        $beforeBalance = $wallet->balance;
        $wallet->increment('balance', $giftPoint);

        RollingChipLog::create([
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'type' => $type == 1 ? 10 : 11,
            'amount' => $giftPoint,
            'before_balance' => $beforeBalance,
            'balance' => $beforeBalance + $giftPoint,
            'status' => 1000,
            'remark' => $type == 1 ? '注册送' : '绑定手机送',
            'admin' => 51,
        ]);
    }

    /**
     * 取得稽核流水倍數
     */
    private function getFlowLimitRate(Agent $agent): float
    {
        $setting = $this->agentService->getSpecificAgentSetting($agent, 6503);
        $setting = isset($setting) ? (float) $setting : 0;

        return $setting;
    }

    /**
     * 建立稽核流水
     */
    private function createFlowLimit(Member $member, int $type, float $giftPoint, float $flowLimitRate): void
    {
        FlowLimit::create([
            'member_id' => $member->id,
            'type' => $type == 1 ? 20 : 21,
            'flow_limit' => $giftPoint * $flowLimitRate,
            'admin' => 51,
        ]);
    }
}
