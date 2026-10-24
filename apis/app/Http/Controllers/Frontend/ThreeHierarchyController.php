<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Data\TransactionLogOptionalData;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\DomainRequest;
use App\Http\Requests\Frontend\PaginationRequest;
use App\Http\Requests\Frontend\ThreeHierarchy as ThreeHierarchyRequests;
use App\Http\Resources\Frontend\ThreeHierarchy as ThreeHierarchyResources;
use App\Http\Resources\OnlyPaginateResource;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\MemberAgent;
use App\Models\ThreeHierarchyBonusReport;
use App\Models\ThreeHierarchySummaryBonus;
use App\Models\ThreeHierarchyWithdrawLog;
use App\Services\AgentService;
use App\Services\MemberAgentService;
use App\Services\MemberCodeService;
use App\Services\RollingChipService;
use App\Services\ThreeHierarchyService;
use App\Services\TransactionLogCreateService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

#[Group('ThreeHierarchy (三階代理)')]
class ThreeHierarchyController extends Controller
{
    public function __construct(
        private AgentService $agentService,
        private MemberCodeService $memberCodeService,
        private MemberAgentService $memberAgentService,
        private ThreeHierarchyService $threeHierarchyService,
    ) {}

    /**
     * 三階代理首頁
     *
     * OLD: /api/threeHierarchy
     */
    public function index(DomainRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;

        $startTime = Carbon::now()
            ->toFrontendTime()
            ->startOfDay()
            ->toSystemTime();

        $memberAgent = $this->memberAgentService->getMemberAgent($member);
        $memberAgent->load('parentMember');

        $down_level = $memberAgent->agent_level + 1;
        $down2_level = $memberAgent->agent_level + 2;

        $playerCount = MemberAgent::sameHierarchy($member)
            ->where('member_id', '!=', $member->id)
            ->selectRaw('
                sum(topper_member_id = ?) as directPlayerCount,
                sum(agent_level = ?) as doubleDirectPlayerCount
            ', [$member->id, $down2_level])
            ->first();

        $addedToday = MemberAgent::sameHierarchy($member)
            ->where('member_id', '!=', $member->id)
            ->whereHas('member', function ($query) use ($startTime) {
                $query->where('created_at', '>', $startTime);
            })
            ->count();

        $todayActiveCount = MemberAgent::sameHierarchy($member)
            ->where('member_id', '!=', $member->id)
            ->whereIn('agent_level', [$down_level, $down2_level])
            ->whereHas('summaryBetRecords', function ($query) use ($startTime) {
                $query->where('summary_time', '>', $startTime);
            })
            ->distinct('member_id')
            ->count();

        $memberCode = $this->memberCodeService->get($member);

        $link = $this->agentService->getReferralLinkDomain($agent, $request->input('domain'));

        $isInvitationCodeEnabled = (int) $this->agentService->getSpecificAgentSetting(
            $agent,
            AgentCustomizedSettingKeyEnum::INVITATION_CODE_SAME_ACCOUNT
        );
        $code = ($isInvitationCodeEnabled == 1) ? $member->username : $memberCode->agent_code;

        return ApiResponse::success(ThreeHierarchyResources\IndexResource::make([
            'topUsername' => $memberAgent->parentMember->username ?? '',
            'username' => $member->username,
            'directPlayerCount' => (int) ($playerCount->directPlayerCount ?? 0),
            'doubleDirectPlayerCount' => (int) ($playerCount->doubleDirectPlayerCount ?? 0),
            'addedToday' => $addedToday,
            'activeToday' => $todayActiveCount,
            'code' => $code,
            'link' => $link,
        ]));
    }

    /**
     * 我的業績
     *
     * OLD: /api/threeHierarchy/achievement
     */
    public function achievements(PaginationRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $nowFrontendTime = \Carbon\Carbon::now()->toFrontendTime();
        $summaryTime = $nowFrontendTime->hour >= 23 ?
            $nowFrontendTime->format('Y-m-d 00:00:00') :
            $nowFrontendTime->subDay()->format('Y-m-d 00:00:00');

        $data = ThreeHierarchyBonusReport::query()
            ->where('member_id', $member->id)
            ->where('summary_time', $summaryTime)
            ->has('fromMember')
            ->has('vendor')
            ->has('gameType')
            ->with('fromMember:id,username', 'vendor:id,name', 'gameType:id,name')
            ->select(
                'from_member_id',
                'vendor_id',
                'game_type_id',
                'summary_time',
                'rebate_type',
                DB::raw('TRUNCATE((bet + down_bet + down2_bet),  2) as bet'),
                DB::raw('TRUNCATE((rebate + down_rebate + down2_rebate), 2) as reward')
            )
            ->orderBy('from_member_id')
            ->paginate($request->input('per_page', 7));

        return ApiResponse::successWithPagination(
            data: ThreeHierarchyResources\AchievementsResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    /**
     * 我的返利
     *
     * OLD: /api/threeHierarchy/rebate
     */
    public function rebates(PaginationRequest $request)
    {
        $member = $this->getUserContext()->member;

        $data = ThreeHierarchyBonusReport::query()
            ->has('member')
            ->where('member_id', $member->id)
            ->select([
                'created_at as date',
                DB::raw('TRUNCATE(SUM(IF(rebate_type = 1, rebate, 0)), 2) as rebate_1'),
                DB::raw('TRUNCATE(SUM(IF(rebate_type = 1, down_rebate, 0)), 2) as rebate_2'),
                DB::raw('TRUNCATE(SUM(IF(rebate_type = 1, down2_rebate, 0)), 2) as rebate_3'),
                DB::raw('TRUNCATE(SUM(IF(rebate_type = 2, rebate, 0)), 2) as lose_rebate_1'),
                DB::raw('TRUNCATE(SUM(IF(rebate_type = 2, down_rebate, 0)), 2) as lose_rebate_2'),
                DB::raw('TRUNCATE(SUM(IF(rebate_type = 2, down2_rebate, 0)), 2) as lose_rebate_3'),
                DB::raw('TRUNCATE(SUM(rebate + down_rebate + down2_rebate), 2) as reward'),
            ])
            ->groupBy(['summary_time', 'created_at', 'rebate_type'])
            ->havingRaw('reward > 0')
            ->orderByDesc('summary_time')
            ->orderByDesc('created_at')
            ->orderBy('rebate_type')
            ->paginate($request->input('per_page', 7));

        return ApiResponse::successWithPagination(
            data: ThreeHierarchyResources\RebatesResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    /**
     * 三層代規則說明
     *
     * OLD: /api/threeHierarchy/rule
     */
    public function rule()
    {
        $userData = $this->getUserContext();
        $agentGroupId = $userData?->agent?->vendor_customize_group_id ?? 0;
        $languageId = $userData?->languageId ?? 1;

        $data = $agentGroupId > 0 ? $this->threeHierarchyService->getRule($agentGroupId, $languageId) : [];

        return ApiResponse::success([
            'items' => ThreeHierarchyResources\RuleResource::collection($data),
        ]);
    }

    /**
     * 提現總覽
     *
     * OLD: /api/threeHierarchy/withdrawRecode
     */
    public function withdrawals(Request $request)
    {
        $member = $this->getUserContext()->member;

        $data = ThreeHierarchySummaryBonus::where('member_id', $member->id)
            ->select(
                DB::raw('TRUNCATE(bonus_amount, 2) as totalRewardPoints'),
                DB::raw('TRUNCATE(withdraw_amount, 2) as withdrawnPoints'),
                DB::raw('(TRUNCATE(bonus_amount, 2) - TRUNCATE(withdraw_amount, 2)) as withdrawablePoints')
            )
            ->first();

        return ApiResponse::success(ThreeHierarchyResources\WithdrawalsResource::make([
            'username' => $member->username,
            'totalRewardPoints' => (is_object($data)) ? $data->totalRewardPoints : 0.00,  // 總獎勵全民積分
            'withdrawnPoints' => (is_object($data)) ? $data->withdrawnPoints : 0.00,  // 已提現全民積分
            'withdrawablePoints' => (is_object($data)) ? $data->withdrawablePoints : 0.00, // 可提現全民積分
        ]));
    }

    /**
     * 提現紀錄列表
     *
     * OLD: /api/threeHierarchy/history
     */
    public function withdrawalHistories(PaginationRequest $request)
    {
        $member = $this->getUserContext()->member;

        $data = ThreeHierarchyWithdrawLog::where('member_id', $member->id)
            ->select(
                'id',
                'created_at',
                'withdraw_point',
                'type'
            )
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 7));

        return ApiResponse::successWithPagination(
            data: ThreeHierarchyResources\WithdrawalHistoriesResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    /**
     * 申請提現
     *
     * OLD: /api/threeHierarchy/withdraw
     */
    public function withdrawalCreate(ThreeHierarchyRequests\WithdrawalRequest $request)
    {
        $member = $this->getUserContext()->member;
        $agent = $this->getUserContext()->agent;

        if ($member->event_mode > 0) {
            return ApiResponse::error(__('error_message.1934'));
        }

        // 活動 V2 進行中禁止領取三代反水獎勵
        if ($member->is_event_v2) {
            return ApiResponse::error(__('error_message.1991'));
        }

        $amount = isset($request->amount) ? (floor($request->amount * 100) / 100) : 0;

        $agentSettings = $this->agentService->getAgentSettings($agent);

        try {
            DB::beginTransaction();

            // check and update summary bonus
            $summaryBonus = ThreeHierarchySummaryBonus::lockForUpdate()
                ->where('member_id', $member->id)
                ->first();

            if (!is_object($summaryBonus)) {
                throw new ApiException(__('error_message.1022'));
            }

            $withdrawable = Common::numSub($summaryBonus->bonus_amount, $summaryBonus->withdraw_amount);
            if (Common::numSub($amount, $withdrawable) > 0) {
                throw new ApiException(__('error_message.1022'));
            }

            if (!($summaryBonus->increment('withdraw_amount', $amount))) {
                throw new ApiException(__('error_message.2001'));
            }

            $lockedMember = Member::lockForUpdate()->findOrFail($member->id);
            if ($lockedMember->event_mode > 0) { // double check
                throw new ApiException(__('error_message.1934'));
            }
            if ($lockedMember->is_event_v2) { // double check
                throw new ApiException(__('error_message.1991'));
            }

            $reward_type = $agentSettings[AgentCustomizedSettingKeyEnum::AGENT_REWARD_DISTRIBUTION] ?? 1;
            if ($reward_type == 2) {
                $result = (new RollingChipService($member))->add(
                    amount: $amount,
                    type: RollingChipLogTypeEnum::AGENT_REWARD,
                );
                $logRecord = $result->log;

            } else {
                $beforeBalance = $lockedMember->balance;
                $result = $lockedMember->increment('balance', $amount);
                $lockedMember->refresh();

                $logRecord = (new TransactionLogCreateService($lockedMember))
                    ->setType(TransactionEnum::V_REWARD->value)
                    ->setOptionalData(
                        new TransactionLogOptionalData(
                            remark: '三层代理奖励',
                            operator: (string) $lockedMember->id,
                            admin: $lockedMember->id,
                        )
                    )
                    ->create($amount, $beforeBalance, $lockedMember->balance);
            }

            if (!$result) {
                throw new ApiException(__('error_message.2001'));
            }

            $unlimitFlowLimitRate = floatval($agentSettings[AgentCustomizedSettingKeyEnum::UNLIMIT_FLOW_LIMIT_RATE] ?? 0);
            if ($unlimitFlowLimitRate > 0) {
                FlowLimit::create([
                    'member_id' => $member->id,
                    'type' => TransactionEnum::V_REWARD->value,
                    'event_id' => 0,
                    'reference_id' => $logRecord->id,
                    'flow_limit' => Common::numMul($amount, $unlimitFlowLimitRate),
                    'reach' => 0,
                    'admin' => 0,
                ]);
            }

            $result = ThreeHierarchyWithdrawLog::create([
                'order_no' => Str::upper(Str::random(10)),
                'type' => $reward_type,
                'member_id' => $member->id,
                'withdraw_point' => $amount,
                'remaining_point' => Common::numSub($withdrawable, $amount),
                'status' => 1,
                'admin' => 0,
            ]);
            DB::commit();

            return ApiResponse::success(ThreeHierarchyResources\WithdrawalCreateResource::make([
                'balance' => $lockedMember->balance,
            ]));
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
