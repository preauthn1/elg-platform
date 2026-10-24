<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\CheckinActivity;
use App\Models\Member;
use App\Repositories\CheckinRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CheckinService
{
    public function __construct(
        private CheckinRepository $checkinRepository,
        private WalletService $walletService,
    ) {}

    public function checkinRecords(Member $member): array
    {
        $memberId = $member->id;
        $generalAgentId = $member->agent->general_agent_id;

        // 取得打卡活動與獎勵
        $activity = $this->checkinRepository->getCheckinActivityByAgentId($generalAgentId);

        if (!$activity) {
            throw new ApiException('Activity not found');
        }

        $maxDay = $activity->prizes->max('day');
        $days = range(1, $maxDay);

        // 取得打卡紀錄
        $checkinDates = $this->checkinRepository->getCheckinDates($activity->id, $memberId);

        $now = now()->toFrontendTime();
        $today = $now->toDateString();
        $yesterday = $now->subDay()->toDateString();

        // 確認當天是否已經打卡，並計算連續天數
        if (!in_array($today, $checkinDates, true)) {
            $streakDay = $this->_getStreakDay($checkinDates, $yesterday);
            $checkinDay = $streakDay + 1;
        } else {
            $streakDay = $this->_getStreakDay($checkinDates, $today);
            $checkinDay = $streakDay >= $maxDay ? 1 : $streakDay;
        }

        $prizeTypeMap = $this->_getPrizeTypeMap();
        $data = [];
        foreach ($days as $day) {
            $row = [
                'day' => $day,
                'is_checkin' => $day <= $streakDay,
                'checkin_day' => $day == $checkinDay,
                'prize' => [],
            ];

            $prizes = $activity->prizes->where('day', $day);
            foreach ($prizes as $item) {
                $row['prize'][] = [
                    'prize_type' => $prizeTypeMap[$item->prize_type] ?? '',
                    'prize_amount' => $item->prize_amount,
                ];
            }
            $data[] = $row;
        }

        return $data;
    }

    public function checkin(Member $member)
    {
        // 鎖分模式中禁止領取獎勵
        if ($member->event_mode > 0) {
            throw new ApiException(__('error_message.1934'));
        }

        // 活動 V2 進行中禁止領取簽到獎勵
        if ($member->is_event_v2) {
            throw new ApiException(__('error_message.1991'));
        }

        $memberId = $member->id;
        $agentId = $member->parent;
        $generalAgentId = $member->agent->general_agent_id;

        $activity = $this->checkinRepository->getCheckinActivityByAgentId($generalAgentId);

        // 檢查是否有活動
        if (!$activity) {
            throw new ApiException('Activity not found');
        }

        return DB::transaction(function () use ($activity, $memberId, $agentId, $member) {
            // 檢查是否已經打卡
            if ($this->checkinRepository->hasCheckedInToday($memberId) === true) {
                throw new ApiException('Already checked in');
            }

            // 檢查活動限制 (活動時間、ip、手機號、真實姓名)
            $this->_checkActivityLimit($activity, $member);

            $checkin = $this->checkinRepository->checkin($activity->id, $memberId);

            // 取得打卡日期
            $checkinDates = $this->checkinRepository->getCheckinDates($activity->id, $memberId);

            // 計算打卡連續天數
            $today = now()->addHours(8)->toDateString();
            $streakDay = $this->_getStreakDay($checkinDates, $today);

            // 取得打卡獎勵
            $prizes = $this->checkinRepository->getCheckinPrize($activity->id, $streakDay);

            if ($prizes->isEmpty()) {
                return [];
            }

            $prizeTypeMap = $this->_getPrizeTypeMap();
            $prizeArray = [];

            foreach ($prizes as $prize) {
                $prizeRecord = $this->checkinRepository->createCheckinPrizeRecord($checkin->id, $prize->id);
                match ($prize->prize_type) {
                    // 平台獎勵
                    1 => $this->_sendPoint($prizeRecord->id, $agentId, $memberId, $prize->prize_amount, $activity->flow_limit_multiple),
                    // 禮碼獎勵
                    2 => $this->_sendRollingChip($agentId, $memberId, $prize->prize_amount),
                    default => null,
                };

                $prizeArray[] = [
                    'prize_type' => $prizeTypeMap[$prize->prize_type] ?? '',
                    'prize_amount' => $prize->prize_amount,
                ];
            }

            return $prizeArray;
        });
    }

    private function _checkActivityLimit(CheckinActivity $activity, Member $member): void
    {
        $now = now();
        // lad('$activity', $activity);

        // 檢查是否在活動期間內
        if (Carbon::parse($activity->start_date)->gt($now) || Carbon::parse($activity->end_date)->lt($now)) {
            throw new ApiException('Activity not in date');
        }

        // 檢查是否阻擋重複IP
        if ($activity->ip_block === 1) {
            $ip = Common::getIp();
            $longIp = ip2long($ip);
            if ($this->checkinRepository->confirmBlockRecord($activity->id, ['long_ip' => $longIp]) === true) {
                throw new ApiException('IP already checked in');
            }
        }

        // 檢查是否阻擋重複手機號
        if ($activity->phone_block === 1) {
            if ($this->checkinRepository->confirmBlockRecord($activity->id, ['mobile' => $member->mobile]) === true) {
                throw new ApiException('Phone already checked in');
            }
        }

        // 檢查是否阻擋重複真實姓名
        if ($activity->account_block === 1) {
            if ($this->checkinRepository->confirmBlockRecord($activity->id, ['first_name' => $member->first_name]) === true) {
                throw new ApiException('Account already checked in');
            }
        }
    }

    /**
     * 計算連續簽到幾天
     */
    private function _getStreakDay($checkinRecord, $day): int
    {
        $streakDay = 0;
        // $day = $today;
        // $day = date('Y-m-d', strtotime('2025-03-17 04:15:24 +8 hours'));

        foreach ($checkinRecord as $date) {
            if ($day == $date) {
                $streakDay++;
                $day = date('Y-m-d', strtotime($day . ' -1 day'));
            } else {
                break;
            }
        }

        return $streakDay;
    }

    /**
     * @param  int  $prizeId  打卡獎勵紀錄id
     * @param  int  $agentId  代理id
     * @param  int  $memberId  會員id
     * @param  string  $amount  獎勵金額（Eloquent DECIMAL 欄位回傳 string）
     * @param  int  $flowLimitMultiple  流水限制倍數
     */
    private function _sendPoint(int $prizeId, int $agentId, int $memberId, string $amount, int $flowLimitMultiple): void
    {
        $agent = Agent::find($agentId);
        if ($agent->is_multi_wallet) {
            // type=2 已廢除（決議⑬）只查不建；多錢包為死路徑，查無存量即顯性中止
            $wallet = $this->walletService->getOrCreateWallet($memberId, WalletEnum::CODE_GAME)
                ?? throw new \LogicException("type=2 遊戲錢包已廢除且查無存量，member_id={$memberId}");
            $beforeBalance = $wallet->balance;
            $wallet->balance = Common::numAdd($beforeBalance, $amount, 4);
            $wallet->save();

            $transactionLog = $this->checkinRepository->createWalletTransactionLog([
                'transaction_no' => $prizeId,
                'agent_id' => $agentId,
                'member_id' => $memberId,
                'point' => $amount,
                'before_balance' => $beforeBalance,
                'balance' => $wallet->balance,
            ]);

            if ($flowLimitMultiple > 0) {
                $this->checkinRepository->createFlowLimit([
                    'member_id' => $memberId,
                    'reference_id' => 0,
                    'wallet_transaction_log_id' => $transactionLog->id,
                    'flow_limit' => Common::numMul($amount, $flowLimitMultiple, 4),
                ]);
            }
        } else {
            // 單錢包代理：獎金直接記在會員主餘額，不是錢包表——這裡的 $wallet 是 Member。
            $wallet = Member::find($memberId);
            $beforeBalance = $wallet->balance;
            $wallet->balance = Common::numAdd($beforeBalance, $amount, 4);
            $wallet->save();

            $transactionLog = $this->checkinRepository->createTransactionLog([
                'transaction_no' => $prizeId,
                'agent_id' => $agentId,
                'member_id' => $memberId,
                'point' => $amount,
                'before_balance' => $beforeBalance,
                'balance' => $wallet->balance,
            ]);

            if ($flowLimitMultiple > 0) {
                $this->checkinRepository->createFlowLimit([
                    'member_id' => $memberId,
                    'reference_id' => $transactionLog->id,
                    'wallet_transaction_log_id' => 0,
                    'flow_limit' => Common::numMul($amount, $flowLimitMultiple, 4),
                ]);
            }
        }
    }

    /**
     * @param  int  $agentId  代理id
     * @param  int  $memberId  會員id
     * @param  string  $amount  獎勵金額（Eloquent DECIMAL 欄位回傳 string）
     */
    private function _sendRollingChip(int $agentId, int $memberId, string $amount): void
    {
        $wallet = $this->walletService->getOrCreateWallet($memberId, WalletEnum::CODE_ROLLING_CHIP);
        $beforeBalance = $wallet->balance;
        $afterBalance = Common::numAdd($beforeBalance, $amount, 4);
        $wallet->balance = $afterBalance;
        $wallet->save();

        $this->checkinRepository->createRollingChipLog([
            'agent_id' => $agentId,
            'member_id' => $memberId,
            'amount' => $amount,
            'before_balance' => $beforeBalance,
            'balance' => $wallet->balance,
            'remark' => '打卡活動',
        ]);
    }

    private function _getPrizeTypeMap(): array
    {
        return [
            1 => __('param.checkin.prize_type.1'),
            2 => __('param.checkin.prize_type.2'),
            3 => __('param.checkin.prize_type.3'),
        ];
    }
}
