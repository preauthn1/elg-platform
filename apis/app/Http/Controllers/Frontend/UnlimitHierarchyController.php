<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Data\TransactionLogOptionalData;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\DomainRequest;
use App\Http\Requests\Frontend\PaginationRequest;
use App\Http\Requests\Frontend\UnlimitHierarchy\CreateWithdrawRequest;
use App\Http\Resources\Frontend\UnlimitHierarchy as UnlimitHierarchyResources;
use App\Http\Resources\OnlyPaginateResource;
use App\Models\AgentVendor;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\MemberAgent;
use App\Models\SummaryBetRecordTeam;
use App\Models\UnlimitBonusReport;
use App\Models\UnlimitSummaryBonus;
use App\Models\UnlimitSystemRate;
use App\Models\UnlimitWithdrawLog;
use App\Services\AgentService;
use App\Services\MemberAgentService;
use App\Services\MemberCodeService;
use App\Services\RollingChipService;
use App\Services\TransactionLogCreateService;
use App\Services\WalletService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[Group('Unlimit Hierarchy (無限代)')]
class UnlimitHierarchyController extends Controller
{
    public function __construct(
        private MemberCodeService $memberCodeService,
        private MemberAgentService $memberAgentService,
        private AgentService $agentService,
        private WalletService $walletService,
    ) {}

    /**
     * 玩家詳細信息
     *
     * OLD: /api/unlimit
     */
    public function index(DomainRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent;

        $startTime = Carbon::now()->toFrontendTime()->startOfDay()->toSystemTime()->format('Y-m-d H:i:s');

        $memberAgent = $this->memberAgentService->getMemberAgent($member);

        $data = MemberAgent::sameHierarchy($member)
            ->where('member_id', '!=', $member->id)
            ->select(
                DB::raw("SUM(topper_member_id = {$member->id}) as directPlayerCount"),
                DB::raw('COUNT(topper_member_id) as teamCount'),
            )
            ->first();

        $directPlayerCount = (int) ($data?->directPlayerCount ?? 0);
        $teamCount = (int) ($data?->teamCount ?? 0);

        $addedToday = MemberAgent::sameHierarchy($member)
            ->where('member_id', '!=', $member->id)
            ->whereHas('member', fn ($q) => $q->where('created_at', '>', $startTime))
            ->count();

        // 活躍人數(指有投注)
        $activeToday = MemberAgent::sameHierarchy($member)
            ->where('member_id', '!=', $member->id)
            ->whereHas('summaryBetRecords', fn ($q) => $q->where('summary_time', '>', $startTime))
            ->distinct('member_id')
            ->count();

        $memberCode = $this->memberCodeService->get($member);

        $link = $this->agentService->getReferralLinkDomain($agent, $request->input('domain'));

        $isInvitationCodeEnabled = (int) $this->agentService->getSpecificAgentSetting(
            $agent,
            AgentCustomizedSettingKeyEnum::INVITATION_CODE_SAME_ACCOUNT
        );
        $code = ($isInvitationCodeEnabled == 1) ? $member->username : $memberCode->agent_code;

        return ApiResponse::success(UnlimitHierarchyResources\IndexResource::make([
            'topUsername' => $memberAgent->parentMember->username ?? '',
            'username' => $member->username,
            'directPlayerCount' => $directPlayerCount,
            'teamCount' => $teamCount,
            'addedToday' => $addedToday,
            'activeToday' => $activeToday,
            'code' => $code,
            'link' => $link,
        ]));
    }

    /**
     * 無限代理佣金制度表
     *
     * OLD: /api/unlimit/list
     */
    public function list(DomainRequest $request)
    {
        $userData = $this->getUserContext();
        $agent = $userData->agent;

        $data = UnlimitSystemRate::where('agent_group_id', $agent->cash_flow_group_id)
            ->select(
                'level',
                DB::raw('CASE WHEN (max_amount % 1) = 0 THEN TRUNCATE(min_amount, 0) ELSE TRUNCATE(min_amount, 2) END AS minAmount'),
                DB::raw('CASE WHEN (max_amount % 1) = 0 THEN TRUNCATE(max_amount, 0) ELSE TRUNCATE(max_amount, 2) END AS maxAmount'),
                DB::raw('TRUNCATE(bonus_amount, 0) AS bounsAmount'),
                DB::raw('TRUNCATE(rebate_rate, 2) AS rebateRate')
            )
            ->orderBy('level')
            ->get();

        return ApiResponse::success(UnlimitHierarchyResources\ListResource::collection($data));
    }

    /**
     * 我的玩家
     *
     * OLD: /api/unlimit/directPlayers
     */
    public function directPlayers(PaginationRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $baseTime = Carbon::now()->toFrontendTime();
        $startTime = $baseTime->copy()->startOfDay()->toSystemTime()->format('Y-m-d H:i:s');
        $endTime = $baseTime->copy()->endOfDay()->toSystemTime()->format('Y-m-d H:i:s');

        $data = DB::select("call SP_get_unlimit_my_player('{$member->id}', '{$startTime}', '{$endTime}')");

        foreach ($data as &$obj) {
            $obj->teamContribution = (is_null($obj->teamContribution)) ? 0 : floor($obj->teamContribution * 100) / 100;
            $obj->memberContribution = (is_null($obj->memberContribution)) ? 0 : floor($obj->memberContribution * 100) / 100;
            $obj->type = ($obj->memberType == 2) ? __('param.agent') : __('param.member');
        }

        $data = collect($data)->paginate($request->input('per_page'));

        return ApiResponse::successWithPagination(
            data: UnlimitHierarchyResources\DirectPlayersResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    /**
     * 我的業績
     *
     * OLD: /api/unlimit/reward
     */
    public function reward(PaginationRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $data = UnlimitBonusReport::query()
            ->where('member_id', $member->id)
            ->where(fn ($q) => $q
                ->where('performance_team', '>', 0)
                ->orWhere('performance_direct', '>', 0)
                ->orWhere('bonus_itself', '>', 0)
            )
            ->orderByDesc('summary_date')
            ->paginate($request->input('per_page'));

        return ApiResponse::successWithPagination(
            data: UnlimitHierarchyResources\RewardResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    /**
     * 我的提現
     *
     * OLD: /api/unlimit/history
     */
    public function withdrawalHistory(PaginationRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $data = UnlimitWithdrawLog::query()
            ->where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page'));

        return ApiResponse::successWithPagination(
            data: UnlimitHierarchyResources\HistoryResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    /**
     * 申請提現
     *
     * OLD: /api/unlimit/withdrawal
     */
    public function withdrawalCreate(CreateWithdrawRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent;

        $agentSettings = $this->agentService->getAgentSettings($agent);
        $amount = $request->input('amount');
        $transactionType = TransactionEnum::V_REWARD->value;
        $remark = '全民代理奖励';

        try {
            DB::beginTransaction();

            $member = Member::where('id', $member->id)->lockForUpdate()->first();
            if ($member->event_mode > 0) { // 鎖分模式中禁止領取全民代理獎勵
                throw new ApiException(__('error_message.1934'));
            }
            if ($member->is_event_v2) { // 活動 V2 進行中禁止領取全民代理獎勵
                throw new ApiException(__('error_message.1991'));
            }

            $unlimitSummaryBonus = UnlimitSummaryBonus::lockForUpdate()
                ->where('member_id', $member->id)
                ->first();

            if (!is_object($unlimitSummaryBonus)) {
                throw new ApiException(__('error_message.1010'));
            }

            $unlimitReward = $unlimitSummaryBonus?->bonus_amount ?? 0;
            $unlimitWithdrawn = $unlimitSummaryBonus?->withdraw_amount ?? 0;
            $unlimitWithdrawable = Common::numSub($unlimitReward, $unlimitWithdrawn);

            if ((int) ($agentSettings[AgentCustomizedSettingKeyEnum::IS_AGENT_ITSELF_REBATE] ?? 0) === 1) {
                $itselfReward = $unlimitSummaryBonus?->itself_bonus_amount ?? 0;
                $itselfWithdrawn = $unlimitSummaryBonus?->itself_withdraw_amount ?? 0;
                $itselfWithdrawable = Common::numSub($itselfReward, $itselfWithdrawn);
            } else {
                // 如果沒開代理自身回水 就全部都是0
                $itselfReward = $itselfWithdrawn = $itselfWithdrawable = 0;
            }

            $reward = [
                'totalRewardPoints' => $unlimitReward + $itselfReward,
                'withdrawnPoints' => $unlimitWithdrawn + $itselfWithdrawn,
                'withdrawablePoints' => Common::numAdd($unlimitWithdrawable, $itselfWithdrawable, 4),
            ];

            if (Common::numCompare($amount, $reward['withdrawablePoints']) > 0) {
                throw new ApiException(__('error_message.1022'));
            }

            $rewardType = $agentSettings[AgentCustomizedSettingKeyEnum::AGENT_REWARD_DISTRIBUTION] ?? 1;
            if ($rewardType === 2) {
                $wallet = $this->walletService->getLockedMemberWallet($member->id, WalletEnum::CODE_ROLLING_CHIP);
                $balanceUpdateResult = (new RollingChipService($member, $wallet))
                    ->setOptionalData([
                        'remark' => $remark,
                    ])
                    ->add($amount, RollingChipLogTypeEnum::AGENT_REWARD);
                $beforeBalance = $balanceUpdateResult->beforeBalance;
                $afterBalance = $balanceUpdateResult->afterBalance;
            } else {
                $beforeBalance = $member->balance;
                $afterBalance = Common::numAdd($beforeBalance, $amount);
                $member->update(['balance' => $afterBalance]);
                (new TransactionLogCreateService($member))
                    ->setType($transactionType)
                    ->setOptionalData(TransactionLogOptionalData::from([
                        'remark' => $remark,
                        'operator' => $member->id,
                        'admin' => $member->id,
                    ]))
                    ->create($amount, $beforeBalance, $afterBalance);
            }

            // 優先扣取無限代獎勵
            if ($unlimitWithdrawable >= $amount) {
                $unlimitSummaryBonus->withdraw_amount = Common::numAdd($unlimitSummaryBonus->withdraw_amount, $amount);
                $unlimitAmount = $amount;
                $itselfAmount = 0;
            } else {
                $overAmount = Common::numSub($amount, $unlimitWithdrawable);
                $unlimitSummaryBonus->withdraw_amount = Common::numAdd($unlimitSummaryBonus->withdraw_amount, $unlimitWithdrawable);
                $unlimitSummaryBonus->itself_withdraw_amount = Common::numAdd($unlimitSummaryBonus->itself_withdraw_amount, $overAmount);
                $unlimitAmount = $unlimitWithdrawable;
                $itselfAmount = $overAmount;
            }
            $unlimitSummaryBonus->save();

            $unlimitFlowLimitRate = $agentSettings[AgentCustomizedSettingKeyEnum::UNLIMIT_FLOW_LIMIT_RATE] ?? 0;
            if (Common::numCompare($unlimitFlowLimitRate, 0) > 0) {
                // 增加稽核流水log
                $flow = [];
                $flow['member_id'] = $member->id;
                $flow['type'] = TransactionEnum::V_REWARD->value;
                $flow['event_id'] = 0;
                $flow['reference_id'] = 0;
                $flow['flow_limit'] = strval($amount * floatval($unlimitFlowLimitRate));
                $flow['reach'] = 0;
                $flow['admin'] = 0;
                FlowLimit::create($flow);
            }

            UnlimitWithdrawLog::create([
                'member_id' => $member->id,
                'order_no' => Str::upper(Str::random(10)), // 訂單單號
                'amount' => $unlimitAmount,
                'reward_point' => $unlimitReward,  // 前一天結算金額
                'withdrawn_point' => Common::numAdd($unlimitWithdrawn, $unlimitAmount), // 已提領金額
                'itself_reward_point' => $itselfReward,  // 前一天結算金額
                'itself_withdrawn_point' => Common::numAdd($itselfWithdrawn, $itselfAmount), // 已提領金額
                'itself_amount' => $itselfAmount,
                'type' => 1,
                'remark' => $remark,
                'admin' => 0,
            ]);

            DB::commit();

            return ApiResponse::success(UnlimitHierarchyResources\WithdrawalCreateResource::make([
                'balance' => $member->balance,
            ]));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            throw $e;
        }
    }

    /**
     * 提現紀錄欄
     *
     * OLD: /api/unlimit/withdrawRecode
     */
    public function withdrawalRecord(PaginationRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $data = UnlimitSummaryBonus::where('member_id', $member->id)->first();

        return ApiResponse::success(UnlimitHierarchyResources\WithdrawalRecordResource::make([
            'username' => $member->username,
            'totalRewardPoints' => Common::numFloor($data?->bonus_amount ?? 0, 2),
            'withdrawnPoints' => Common::numFloor($data?->withdraw_amount ?? 0, 2),
            'withdrawablePoints' => Common::numSub($data?->bonus_amount ?? 0, $data?->withdraw_amount ?? 0),
            'flowAmount' => Common::numFloor($data?->flow_amount ?? 0, 2),
            'itselfWithdrawnPoints' => Common::numFloor($data?->itself_withdraw_amount ?? 0, 2),
            'itselfWithdrawablePoints' => Common::numSub($data?->itself_bonus_amount ?? 0, $data?->itself_withdraw_amount ?? 0),
        ]));
    }

    /**
     * 今日流水
     *
     * OLD: /api/unlimit/getFlowAmount
     */
    public function flowAmount()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent;

        $startTime = Carbon::now();
        $endTime = $startTime->copy()->addDay();

        $hasAgentVendor_agentId = 1; // 查看agent_vendors是吃哪個代理
        foreach ($agent->hierarchy_array as $agentId) {
            $agentVendor = AgentVendor::where('agent_id', $agentId)->first();
            if ($agentVendor) {
                $hasAgentVendor_agentId = $agentVendor->vendor_id;
                break;
            }
        }

        $data = SummaryBetRecordTeam::from('summary_bet_records_team as sbrt')
            ->join('agent_vendors as av', fn ($join) => $join
                ->on('av.vendor_id', 'sbrt.vendor_id')
                ->where('av.agent_id', $hasAgentVendor_agentId)
            )
            ->join('vendors as v', 'v.id', 'sbrt.vendor_id')
            ->leftJoin('vendor_info_games as vig', fn ($join) => $join
                ->on('vig.game_id', 'sbrt.game_id')
                ->on('vig.vendor_info_id', 'av.vendor_info_id')
            )
            ->where('sbrt.summary_time', '>=', $startTime)
            ->where('sbrt.summary_time', '<', $endTime)
            ->where('sbrt.member_id', $member->id)
            ->select(
                DB::raw('IFNULL( SUM(sbrt.bet * IFNULL( IFNULL(vig.valid_bet_rate, v.valid_bet_rate), 0) ), 0 ) as performance')
            )
            ->first();

        return ApiResponse::success(UnlimitHierarchyResources\FlowAmountResource::make([
            'performance' => $data?->performance ?? 0,
        ]));
    }
}
