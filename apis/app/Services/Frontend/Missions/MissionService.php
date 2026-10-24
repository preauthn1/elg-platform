<?php

declare(strict_types=1);

namespace App\Services\Frontend\Missions;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\Missions\MissionResetTypeEnum;
use App\Enums\Missions\MissionRewardTypeEnum;
use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\MemberMission;
use App\Models\Mission;
use App\Models\MissionAvailableAgent;
use App\Models\MissionClaimRecord;
use App\Models\MissionName;
use App\Models\RollingChipLog;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use App\Services\WalletService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MissionService
{
    public function list(Member $member, int $languageId): Collection
    {
        $memberId = $member->id;
        $hierarchyPath = $member->agent->hierarchy_array;
        $now = now()->toDateTimeString();

        // 子查詢
        $availableAgentsSub = MissionAvailableAgent::whereIn('agent_id', $hierarchyPath)->select(['mission_id', 'agent_id']);
        $missionNamesSub = MissionName::where('language_id', $languageId)->select(['mission_id', 'name', 'description']);

        $missions = Mission::query()
            ->joinSub($availableAgentsSub, 'available_agents', function ($join) {
                $join->on('missions.id', '=', 'available_agents.mission_id');
            })
            ->leftJoinSub($missionNamesSub, 'mission_names', function ($join) {
                $join->on('missions.id', '=', 'mission_names.mission_id');
            })
            ->where('status', 1)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->select(
                'missions.id',
                \DB::raw('IFNULL(ANY_VALUE(mission_names.name), missions.name) as name'),
                \DB::raw('IFNULL(ANY_VALUE(mission_names.description), missions.description) as description'),
                'missions.reset_type',
                'missions.action_type',
                'missions.condition_value',
                'example.invalid',
                'missions.start_time',
                'missions.end_time',
            )
            ->groupBy(['missions.id'])
            ->get();

        $missionIds = $missions->pluck('id')->toArray();
        $timeRanges = [];

        foreach ($missions as $mission) {
            [$summaryStartDate, $summaryEndDate] = $mission->reset_type->getTimeRange($mission);
            $timeRanges[$mission->id] = [
                'start' => $summaryStartDate,
                'end' => $summaryEndDate,
            ];
        }

        $memberMissions = MemberMission::query()
            ->whereIn('mission_id', $missionIds)
            ->where('member_id', $memberId)
            ->select('mission_id', 'summary_time', 'progress', 'is_claimed')
            ->get()
            ->groupBy('mission_id');

        $missions->each(function ($mission) use ($memberMissions, $timeRanges) {
            $timeRange = $timeRanges[$mission->id];
            $progress = 0;
            $isClaimed = 0;

            if (isset($memberMissions[$mission->id])) {
                $filteredMissions = $memberMissions[$mission->id]->filter(function ($item) use ($timeRange) {
                    return $item->summary_time >= $timeRange['start']
                        && $item->summary_time < $timeRange['end'];
                });

                $progress = $filteredMissions->sum('progress');
                $isClaimed = $filteredMissions->where('is_claimed', 1)->isNotEmpty() ? 1 : 0;
            }

            $mission->progress = Common::numFloor($progress, 2);
            $mission->is_claimed = $isClaimed;
            $mission->reward_type = $mission->info['reward_config']['type'];
            $mission->reward_value = $mission->info['reward_config']['value'];
            $mission->requirement = Common::numFloor($mission->condition_value, 2);
            $mission->game = $this->filterGameConfig($mission->info['action_config']['game'] ?? null);

            unset($mission->info, $mission->start_time, $mission->end_time);
        });

        return $missions;
    }

    private function filterGameConfig(?array $gameConfig): ?array
    {
        if (!$gameConfig) {
            return null;
        }

        $gameData = [];
        $fields = ['game_type', 'vendor_id'];

        foreach ($fields as $field) {
            if (!empty($gameConfig[$field]) && $gameConfig[$field] !== ['0'] && $gameConfig[$field] !== [0]) {
                $gameData[$field] = $gameConfig[$field];
            }
        }

        return !empty($gameData) ? $gameData : null;
    }

    public function claim(Member $member, int $missionId)
    {
        // 鎖分模式中禁止領取獎勵
        if ($member->event_mode > 0) {
            throw new ApiException(__('error_message.1934'));
        }

        // 活動 V2 進行中禁止領取任務獎勵
        if ($member->is_event_v2) {
            throw new ApiException(__('error_message.1991'));
        }

        $memberId = $member->id;
        $now = now()->toDateTimeString();
        $timeOffset = now()->toFrontendTime()->offsetHours;

        $mission = Mission::where('id', $missionId)
            ->where('status', 1)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->first();

        if (!$mission) {
            throw new ApiException('The mission has expired');
        }

        $agent = $member->agent;
        $eventRewardRealnameValidation = $agent->getSettingValue(AgentCustomizedSettingKeyEnum::EVENT_REWARD_REALNAME_VALIDATION);
        $eventRewardMobileValidation = $agent->getSettingValue(AgentCustomizedSettingKeyEnum::EVENT_REWARD_MOBILE_VALIDATION);
        if ($eventRewardRealnameValidation && !$member->realname_validate) {
            throw new ApiException(__('error_message.2047'), HttpCode::HTTP_REDIRECT_APPLY_REALNAME->value);
        }
        if ($eventRewardMobileValidation && !$member->mobile_validate) {
            throw new ApiException(__('error_message.9007'), HttpCode::HTTP_REDIRECT_SET_MOBILE->value);
        }

        [$summaryStartDate, $summaryEndDate] = $mission->reset_type->getTimeRange($mission);

        try {
            DB::beginTransaction();

            $memberMission = MemberMission::query()
                ->lockForUpdate()
                ->where('mission_id', $mission->id)
                ->where('member_id', $memberId)
                ->where('summary_time', '>=', $summaryStartDate)
                ->where('summary_time', '<', $summaryEndDate)
                ->select(
                    DB::raw('SUM(progress) as progress'),
                    DB::raw('MAX(is_claimed) as is_claimed'),
                )
                ->selectRaw('DATE_FORMAT(DATE_ADD(summary_time, INTERVAL ? HOUR), ?) AS date', [$timeOffset, '%Y-%m-%d'])
                ->selectRaw('DATE_FORMAT(DATE_ADD(summary_time, INTERVAL ? HOUR), ?) AS week', [$timeOffset, '%x-%v'])
                ->selectRaw('DATE_FORMAT(DATE_ADD(summary_time, INTERVAL ? HOUR), ?) AS month', [$timeOffset, '%Y-%m'])
                ->first();

            if ($memberMission->is_claimed) {
                throw new ApiException(__('error_message.3001'));
            }

            if (!isset($mission->info['condition_config']['value'])) {
                throw new ApiException(__('error_message.3002'));
            }

            $requirement = $mission->info['condition_config']['value'];
            if ((int) $memberMission->progress < (int) $requirement) {
                throw new ApiException(__('error_message.3003'));
            }

            $missionClaimRecord = MissionClaimRecord::where('mission_id', $mission->id)
                ->where('member_id', $memberId)
                ->where('is_claimed', 0)
                ->when($mission->reset_type->value == MissionResetTypeEnum::DAILY->value, function ($query) use ($memberMission) {
                    $query->where('date', $memberMission->date);
                })
                ->when($mission->reset_type->value == MissionResetTypeEnum::WEEKLY->value, function ($query) use ($memberMission) {
                    $query->where('week', $memberMission->week);
                })
                ->when($mission->reset_type->value == MissionResetTypeEnum::MONTHLY->value, function ($query) use ($memberMission) {
                    $query->where('month', $memberMission->month);
                })
                ->first();

            if ($missionClaimRecord === null) {
                throw new ApiException('missionClaimRecord not found');
            }

            $this->giveReward($mission, $memberId);

            $missionClaimRecord->update([
                'claim_time' => $now,
                'is_claimed' => 1,
            ]);

            MemberMission::query()
                ->where('mission_id', $mission->id)
                ->where('member_id', $memberId)
                ->where('summary_time', '>=', $summaryStartDate)
                ->where('summary_time', '<', $summaryEndDate)
                ->update([
                    'is_claimed' => 1,
                ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function giveReward(Mission $mission, int $memberId)
    {
        $rewardConfig = $mission->info['reward_config'];

        if (!isset($rewardConfig['type'])) {
            throw new ApiException(__('error_message.3004'));
        }

        if (!isset($rewardConfig['value'])) {
            throw new ApiException(__('error_message.3005'));
        }

        $rewardType = $rewardConfig['type'];
        // 外部 JSON 數值進入時正規化至固定精度，避免精度誤差
        $rewardValue = Common::numAdd('0', (string) $rewardConfig['value'], 4);
        $flowLimitMultiple = Common::numAdd('0', (string) ($rewardConfig['flow_limit_multiple'] ?? 0), 4);

        $member = Member::with('agent')->where('id', $memberId)->first();
        switch ($rewardType) {
            case MissionRewardTypeEnum::BALANCE->value:
                $this->giveBalanceReward($member, $rewardValue, $flowLimitMultiple);
                break;
            case MissionRewardTypeEnum::ROLLING_CHIP->value:
                $this->giveRollingChipReward($member, $rewardValue);
                break;
                /*
                case MissionRewardTypeEnum::SHOP_POINT:
                    $this->giveMemberWalletReward($member, WalletEnum::CODE_SHOP, $rewardValue);
                    break;
                */
            default:
                throw new \Exception("Invalid reward type: {$rewardType}");
        }
    }

    private function giveBalanceReward(Member $member, int|float|string $rewardValue, int|float|string $flowLimitMultiple = 0)
    {
        if ($member->agent->is_multi_wallet) {
            $walletTransactionLogId = $this->giveMemberWalletReward($member, WalletEnum::CODE_GAME, $rewardValue);
        } else {
            $transactionLogId = $this->giveMemberReward($member, $rewardValue);
        }

        if (Common::numCompare($flowLimitMultiple, '0') > 0) {
            FlowLimit::create([
                'member_id' => $member->id,
                'type' => TransactionEnum::MISSION_REWARD->value,
                'event_id' => 0,
                'reference_id' => $transactionLogId ?? 0,
                'wallet_transaction_log_id' => $walletTransactionLogId ?? 0,
                'flow_limit' => Common::numMul($rewardValue, $flowLimitMultiple),
                'reach' => 0,
                'admin' => 0,
            ]);
        }
    }

    private function giveMemberWalletReward(Member $member, WalletEnum $walletType, int|float|string $point): int
    {
        // type=2 已廢除（決議⑬）只查不建；GAME 僅多錢包（死路徑）可達，查無存量即顯性中止
        $wallet = (new WalletService)->getLockedMemberWallet($member->id, $walletType)
            ?? throw new \LogicException("已廢除的錢包類型查無存量，member_id={$member->id}，wallet_type={$walletType->value}");
        $beforeBalance = $wallet->balance;
        $afterBalance = Common::numAdd($beforeBalance, $point);
        $wallet->update([
            'balance' => $afterBalance,
        ]);

        $logResult = WalletTransactionLog::create([
            'transaction_no' => Common::getTransactionNo(true),
            'wallet_type_id' => $walletType->value,
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'point' => $point,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'type' => TransactionEnum::MISSION_REWARD->value,
            'remark' => 'System bind mobile phone reward',
            'checksum' => '',
            'result' => '',
            'operator' => 'system',
            'status' => TransactionStatusEnum::STATUS_SUCCESS,
        ]);

        return $logResult->id;
    }

    private function giveMemberReward(Member $member, int|float|string $point): int
    {
        $wallet = Member::lockForUpdate()->where('id', $member->id)->first();
        if (!is_object($wallet)) {
            throw new \Exception('Member not found');
        }

        $beforeBalance = $wallet->balance;
        $afterBalance = Common::numAdd($beforeBalance, $point);
        $wallet->update([
            'balance' => $afterBalance,
        ]);

        $logResult = TransactionLog::create([
            'transaction_no' => Common::getTransactionNo(),
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'point' => $point,
            'vendor_point' => 0,
            'rate' => 0,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'type' => TransactionEnum::MISSION_REWARD->value,
            'operator' => 'system',
            'remark' => 'System mission reward',
            'status' => TransactionStatusEnum::STATUS_SUCCESS,
            'admin' => 0,
        ]);
        if (!$logResult || !$logResult->id) {
            throw new \Exception('Transaction log create failed');
        }

        return $logResult->id;
    }

    private function giveRollingChipReward(Member $member, int|float|string $point): int
    {
        $wallet = (new WalletService)->getLockedMemberWallet($member->id, WalletEnum::CODE_ROLLING_CHIP);
        $beforeBalance = $wallet->balance;
        $afterBalance = Common::numAdd($beforeBalance, $point);
        $wallet->update([
            'balance' => $afterBalance,
        ]);

        $logResult = RollingChipLog::create([
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'type' => RollingChipLogTypeEnum::MISSION_REWARD->value,
            'amount' => $point,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'remark' => 'System mission reward',
            'status' => 1000,
            'admin' => 0,
        ]);

        return $logResult->id;
    }
}
