<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Models\CheckinActivity;
use App\Models\CheckinPrize;
use App\Models\CheckinPrizeRecord;
use App\Models\CheckinRecord;
use App\Models\FlowLimit;
use App\Models\RollingChipLog;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CheckinRepository
{
    private $systemTime;

    private $offsetHours = 8;

    public function __construct()
    {
        $carbon = Carbon::now();
        $this->systemTime = $carbon->toDateTimeString();
        $this->offsetHours = $carbon->toFrontendTime()->offsetHours;
    }

    public function getCheckinActivityByAgentId(int $agentId): ?CheckinActivity
    {
        return CheckinActivity::query()
            ->with([
                'prizes' => function ($query) {
                    $query->select(['id', 'checkin_activity_id', 'day', 'prize_type', 'prize_amount']);
                    $query->orderByDesc('day');
                },
            ])
            ->select(['id', 'flow_limit_multiple', 'ip_block', 'phone_block', 'account_block', 'start_date', 'end_date'])
            ->where('start_date', '<=', $this->systemTime)
            ->where('end_date', '>', $this->systemTime)
            ->where('agent_id', $agentId)
            ->where('status', 1)
            ->first();
    }

    public function hasCheckedInToday(int $memberId): bool
    {
        return CheckinRecord::where('member_id', $memberId)
            ->whereDate(
                DB::raw('DATE_ADD(created_at, INTERVAL ' . $this->offsetHours . ' HOUR)'),
                now()->toFrontendTime()->toDateString()
            )
            ->lockForUpdate()
            ->exists();
    }

    public function confirmBlockRecord(int $activityId, array $confirm): bool
    {
        return CheckinRecord::query()
            ->leftJoin('members', 'checkin_records.member_id', '=', 'members.id')
            ->whereDate(
                DB::raw('DATE_ADD(checkin_records.created_at, INTERVAL ' . $this->offsetHours . ' HOUR)'),
                now()->toFrontendTime()->toDateString(),
            )
            ->where('checkin_records.checkin_activity_id', $activityId)
            ->when(isset($confirm['long_ip']) === true, function ($query) use ($confirm) {
                return $query->where('members.last_login_ip', $confirm['long_ip']);
            })
            ->when(isset($confirm['mobile']) === true, function ($query) use ($confirm) {
                return $query->where('members.mobile', $confirm['mobile']);
            })
            ->when(isset($confirm['first_name']) === true, function ($query) use ($confirm) {
                return $query->where('members.first_name', $confirm['first_name']);
            })
            ->exists();
    }

    public function checkin(int $activityId, int $memberId)
    {
        return CheckinRecord::create([
            'checkin_activity_id' => $activityId,
            'member_id' => $memberId,
            // 'created_at' => '2025-03-17 04:15:24',
        ]);
    }

    public function getCheckinPrize(int $activityId, int $streakDay): Collection
    {
        return CheckinPrize::select(['id', 'prize_type', 'prize_amount'])
            ->where('checkin_activity_id', $activityId)
            ->where('day', $streakDay)
            ->orderByDesc('day')
            ->get();
    }

    public function getCheckinAllPrize(int $activityId): Collection
    {
        return CheckinPrize::select(['id', 'day', 'prize_type', 'prize_amount'])
            ->where('checkin_activity_id', $activityId)
            ->orderByDesc('day')
            ->get();
    }

    /**
     * 查詢某會員在指定活動下的打卡紀錄，並排除已經領取過最後一個獎項之後的紀錄。流程如下：
     *
     * 1.查詢活動最後賞ID
     * 2.查詢用戶是否有最後賞領取紀錄, 如果最後賞領取日期不是今天，則只取晚於該時間的紀錄（即新一輪循環的打卡）。
     * 3.最後依 checkin_date 由新到舊排序，回傳所有符合條件的打卡紀錄集合。
     */
    public function getCheckinDates(int $activityId, int $memberId): array
    {
        // 查詢活動最後賞ID
        $lastPrizeId = CheckinPrize::where('checkin_activity_id', $activityId)
            ->orderByDesc('day')
            ->limit(1)
            ->value('id');

        if (!$lastPrizeId) {
            throw new ApiException('No corresponding prize found for this activity. Please check the activity settings.');
        }

        // 查詢最後賞領取紀錄
        $lastCyclePrizeRecord = CheckinRecord::query()
            ->whereHas('checkinPrizeRecords', function ($query) use ($lastPrizeId) {
                $query->where('checkin_prize_id', $lastPrizeId);
            })
            ->where('member_id', $memberId)
            ->orderByDesc('created_at')
            ->limit(1)
            ->first();

        $today = now()->toFrontendTime()->toDateString();

        // 查詢打卡紀錄
        $records = CheckinRecord::query()
            ->where('checkin_activity_id', $activityId)
            ->where('member_id', $memberId)
            ->when($lastCyclePrizeRecord, function ($query) use ($lastCyclePrizeRecord, $today) {
                $lastPrizeDate = $lastCyclePrizeRecord->created_at->toFrontendTime()->toDateString();
                if ($lastPrizeDate !== $today) {
                    $query->where('created_at', '>', $lastCyclePrizeRecord->created_at);
                }
            })
            ->latest()
            ->get();

        return $records->pluck('checkin_date')->toArray();
    }

    public function createCheckinPrizeRecord(int $checkinRecordId, int $checkinPrizeId)
    {
        return CheckinPrizeRecord::create([
            'checkin_record_id' => $checkinRecordId,
            'checkin_prize_id' => $checkinPrizeId,
        ]);
    }

    public function createWalletTransactionLog(array $data)
    {
        $log = [];

        $column = ['transaction_no', 'agent_id', 'member_id', 'point', 'before_balance', 'balance'];
        foreach ($column as $key) {
            $log[$key] = $data[$key] ?? '';
        }

        $log['wallet_type_id'] = WalletEnum::CODE_GAME;
        $log['operator'] = 'system';
        $log['type'] = WalletTransactionEnum::GAME_WALLET_CHECK_IN;
        $log['status'] = TransactionStatusEnum::STATUS_SUCCESS;

        return WalletTransactionLog::create($log);
    }

    public function createTransactionLog(array $data)
    {
        $log = [];

        $column = ['transaction_no', 'agent_id', 'member_id', 'point', 'before_balance', 'balance'];
        foreach ($column as $key) {
            $log[$key] = $data[$key] ?? '';
        }

        $log['remark'] = '打卡活動';
        $log['rate'] = 1;
        $log['operator'] = 'system';
        $log['type'] = TransactionEnum::CHECKIN_REWARD;
        $log['status'] = TransactionStatusEnum::STATUS_SUCCESS;
        $log['admin'] = 0;

        return TransactionLog::create($log);
    }

    public function createFlowLimit(array $data)
    {
        $flow = [];

        $column = ['member_id', 'reference_id', 'wallet_transaction_log_id', 'flow_limit'];
        foreach ($column as $key) {
            $flow[$key] = $data[$key] ?? '';
        }

        $flow['type'] = 1;
        $flow['event_id'] = 0;
        $flow['reach'] = 0;
        $flow['admin'] = 0;

        return FlowLimit::create($flow);
    }

    public function createRollingChipLog(array $data)
    {
        $log = [];

        $column = ['agent_id', 'member_id', 'amount', 'before_balance', 'balance', 'remark'];
        foreach ($column as $key) {
            $log[$key] = $data[$key] ?? '';
        }

        $log['type'] = 1;
        $log['status'] = 1000;
        $log['admin'] = 0;

        return RollingChipLog::create($log);
    }
}
