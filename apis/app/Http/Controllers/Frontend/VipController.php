<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\PaginationRequest;
use App\Http\Requests\Frontend\Vip\ReceiveRequest;
use App\Http\Resources\Frontend\Vip\FlowLimitResource;
use App\Http\Resources\Frontend\Vip\InfoResource;
use App\Http\Resources\Frontend\Vip\RewardHistoryResource;
use App\Http\Resources\Frontend\Vip\VipContentResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Models\MemberVip;
use App\Models\MemberVipPrize;
use App\Models\VipContent;
use App\Models\VipContentReward;
use App\Models\VipRebateSummaryRecord;
use App\Services\VipService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

#[Group('VIP (會員 VIP)')]
class VipController extends Controller
{
    public function __construct(
        private VipService $vipService
    ) {}

    /**
     * 會員VIP資訊
     *
     * OLD: /api/vip/info
     */
    public function info()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $memberInfo = MemberVip::where('member_id', $member->id)
            ->has('vipContent')
            ->with('vipContent:id,level')
            ->select(
                'id',
                'vip_content_id',
                'deposit_amount',
                'keep_amount'
            )
            ->first();

        if (!is_object($memberInfo)) {
            return ApiResponse::success(InfoResource::make((object) [
                'member_info' => null,
            ]));
        }

        $thisMonday = Carbon::now()->toFrontendTime()->startOfWeek()->toSystemTime();

        $lastRebateSummaryRecord = $this->vipService->getLastRebateSummaryRecord(
            memberId: $member->id,
            thisMonday: $thisMonday
        );
        $thisRebateRecords = $this->vipService->getThisRebateRecord(
            memberId: $member->id,
            startTime: $thisMonday->format('Y-m-d H:i:s'),
            endTime: $thisMonday->copy()->addWeek()->format('Y-m-d H:i:s')
        );

        $memberRewards = VipContentReward::where('vip_content_id', $memberInfo->vip_content_id)
            ->where('status', 1)
            ->whereHas('vipReward', fn ($q) => $q->where('status', 1))
            ->with([
                'vipReward:id,name',
                'memberVipPrizes' => fn ($q) => $q
                    ->where('member_id', $member->id)
                    ->orderByDesc('id')
                    ->take(1),
            ])
            ->select(
                'id',
                'vip_reward_id',
            )
            ->get();

        $rewardInfos = [];
        foreach ($memberRewards as $row) {
            $info = [
                'id' => $row->vipReward->id,
                'reward_id' => 0,
                'name' => $row->vipReward->name,
                'status' => -1,
                'expired_time' => null,
            ];

            $memberRewardInfo = $row->memberVipPrizes->first();
            if (!is_object($memberRewardInfo)) {
                $rewardInfos[] = $info;

                continue;
            }

            $info['reward_id'] = $memberRewardInfo->id;

            if ($memberRewardInfo->status == 1) { // 如果是1:已領取 把過期時間顯示NULL 不然前端會顯示已過期
                $info['status'] = 1;
                $info['expired_time'] = null;
            } else {
                $info['status'] = is_null($memberRewardInfo->expired_time) || ($memberRewardInfo->expired_time > date('Y-m-d H:i:s')) ? 0 : 2;
                $info['expired_time'] = Carbon::parse($memberRewardInfo->expired_time)->toFrontendTime()->format('Y-m-d H:i:s');
            }
            $rewardInfos[] = $info;
        }

        $rebateSummaryRecords = VipRebateSummaryRecord::where('member_id', $member->id)
            ->select(
                'summary_start_time',
                'summary_end_time',
                'game_type_id',
                'bet',
                DB::raw('(proportion * 100) as proportion'),
                'rebate_amount'
            )
            ->get();

        return ApiResponse::success(InfoResource::make((object) [
            'member_info' => $memberInfo,
            'last_rebate_records' => $lastRebateSummaryRecord,
            'this_rebate_records' => $thisRebateRecords,
            'reward' => $rewardInfos,
            'rebate_summary_records' => $rebateSummaryRecords,
        ]));
    }

    /**
     * 會員VIP等級列表
     *
     * OLD: /api/vip/info (NEW, extract vip_content)
     */
    public function contents()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $languageId = $userData->languageId;

        $vipContents = VipContent::whereIn('agent_id', $agent->hierarchy_array)
            ->select(
                'id',
                'level',
                'target_amount',
                'keep_amount',
                'withdrawal_times_limit',
                'withdrawal_amount_limit',
                'vip_channel',
                'name',
                'image'
            )
            ->get();

        if (!is_object($vipContents)) {
            return ApiResponse::success(VipContentResource::collection([]));
        }

        $vipContents->load([
            'vipContentNames' => fn ($q) => $q
                ->where('language_id', $languageId)
                ->select('id', 'vip_content_id', 'name', 'image'),

            'vipContentRewards' => fn ($q) => $q
                ->where('status', 1)
                ->whereHas('vipReward', fn ($q) => $q->where('status', 1))
                ->select(
                    'id',
                    'vip_content_id',
                    'vip_reward_id',
                    'type',
                    'point',
                    'point_percentage'
                ),
            'vipContentRewards.vipReward:id,name',

            'vipContentWithdrawalTypes' => fn ($q) => $q
                ->where('status', 1)
                ->whereHas('withdrawalType', fn ($q) => $q->where('status', 1))
                ->select('id', 'vip_content_id', 'withdrawal_type_id'),
            'vipContentWithdrawalTypes.withdrawalType:id,name',
            'vipContentWithdrawalTypes.withdrawalType.names' => fn ($q) => $q
                ->where('language_id', $languageId)
                ->select('id', 'withdrawal_type_id', 'name'),

            'vipRebateProportions' => fn ($q) => $q
                ->select(
                    'vip_content_id',
                    'game_type_id',
                    DB::raw('(proportion * 100) as proportion')
                ),
        ]);

        foreach ($vipContents as $row) {
            $row->name = $row->vipContentNames?->first()?->name ?? $row->name;
            $row->image = $row->vipContentNames?->first()?->image ?? $row->image;

            $row->reward = $row->vipContentRewards->map(function ($reward) {
                return [
                    'id' => $reward->vipReward->id,
                    'name' => $reward->vipReward->name,
                    'point' => match ($reward->type) {
                        0 => $reward->point,
                        1 => $reward->point_percentage * 100 . '%',
                        2 => '-',
                        default => '-',
                    },
                ];
            });

            $row->support_order_type = $row->vipContentWithdrawalTypes->map(function ($item) {
                $withdrawalType = $item->withdrawalType;

                return [
                    'name' => $withdrawalType->names->first()?->name ?? $withdrawalType->name,
                ];
            });

            $row->rebate_proportion = $row->vipRebateProportions;
        }

        return ApiResponse::success(VipContentResource::collection($vipContents));
    }

    /**
     * 領獎紀錄
     *
     * OLD: /api/vip/rewardHistory
     */
    public function rewardHistory(Request $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $data = MemberVipPrize::where('member_id', $member->id)
            ->where('status', 1)
            ->has('vipContentReward')
            ->has('vipContentReward.vipReward')
            ->with('vipContentReward.vipReward:id,name')
            ->select(
                'id',
                'vip_content_reward_id',
                'point',
                'updated_at'
            )
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(RewardHistoryResource::collection($data));
    }

    /**
     * VIP 稽核流水限制列表
     *
     * OLD: /api/vip/flow_limit
     */
    public function flowLimit(PaginationRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;

        $page = (int) $input['page'];
        $perPage = (int) $input['per_page'];

        $result = $this->vipService->getFlowLimits($member->id, $page, $perPage);

        $paginator = new LengthAwarePaginator($result['data'], $result['total'], $perPage, $page);

        return ApiResponse::success([
            'items' => FlowLimitResource::collection($result['data']),
            'pagination' => new OnlyPaginateResource($paginator),
        ]);
    }

    /**
     * 領取單筆 VIP 獎勵
     *
     * OLD: /api/vip/receive
     */
    public function receive(ReceiveRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;

        $this->vipService->claimReward($member, (int) $input['id']);

        return ApiResponse::success();
    }

    /**
     * 一次領取所有待領的每日返水獎勵
     *
     * OLD: /api/vip/receive_rebate
     */
    public function receiveRebate()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $this->vipService->claimRebateRewards($member);

        return ApiResponse::success();
    }
}
