<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\MemberTeam\AchievementRequest;
use App\Http\Requests\Frontend\MemberTeam\TeamManagementRequest;
use App\Http\Resources\Frontend\MemberTeam;
use App\Models\GameType;
use App\Models\Member;
use App\Models\MemberAgent;
use App\Models\ThreeHierarchyBonusReport;
use App\Models\ThreeHierarchySummaryBonus;
use App\Models\ThreeHierarchyWithdrawLog;
use App\Services\AgentService;
use App\Services\MemberAgentService;
use App\Services\MemberCodeService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 步驟說明：
 * 1. 每天下午 2:15 執行 SP_calc_three_hierarchy_bonus 儲存計算結果至 three_hierarchy_bonus_report 資料表。
 * 2. 下午 3:15 執行 SP_calc_three_hierarchy_bonus_merge，將 three_hierarchy_bonus_report 的獎勵金額派發至 three_hierarchy_summary_bonus。
 * 3. 玩家可領取獎勵進入錢包，TPOWER 目前於 3:30 透過自動派發，查詢 three_hierarchy_summary_bonus 未領取資料，自動派發至玩家錢包。
 * 4. three_hierarchy_bonus_report_agents 資料表紀錄代理派發，透過 RD-Studio-01 排程於 PayoutThreeHierarchyBonusAgent.php 執行派發。
 */
#[Group('MemberTeam (會員團隊資訊)')]
class MemberTeamController extends Controller
{
    private int $settleHour = 14;

    private int $payoutHour = 15;

    public function __construct(
        private MemberCodeService $memberCodeService,
        private AgentService $agentService,
        private MemberAgentService $memberAgentService,
    ) {}

    /**
     * 基本信息
     *
     * OLD: api/memberTeam/info
     */
    public function info(Request $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;

        $baseTime = Carbon::now();

        $startTime = $baseTime
            ->copy()
            ->toFrontendTime()
            ->startOfDay()
            ->toSystemTime();

        $memberAgent = $this->memberAgentService->getMemberAgent($member);
        $memberAgent->load('parentMember');

        $self_level = $memberAgent->agent_level;
        $down_level = $memberAgent->agent_level + 1;
        $down2_level = $memberAgent->agent_level + 2;

        // 直屬人數
        $directPlayerCount = MemberAgent::sameHierarchy($member)
            ->where('member_id', '!=', $member->id)
            ->where('topper_member_id', $member->id)
            ->count();

        // 今日新增人數
        $addedToday = MemberAgent::sameHierarchy($member)
            ->where('agent_level', $down_level)
            ->whereHas('member', fn ($q) => $q->where('created_at', '>', $startTime))
            ->count();

        // 今日團隊新增人數(包含自己)
        $addedTodayGroupMember = MemberAgent::sameHierarchy($member)
            ->whereIn('agent_level', [$self_level, $down_level, $down2_level])
            ->whereHas('member', fn ($q) => $q->where('created_at', '>', $startTime))
            ->count();

        // 團隊總人數(包含自己)
        $totalGroupMember = MemberAgent::sameHierarchy($member)
            ->whereIn('agent_level', [$self_level, $down_level, $down2_level])
            ->count();

        $memberCode = $this->memberCodeService->get($member);

        $link = $this->agentService->getReferralLinkDomain($agent, $request->input('domain'));

        $isInvitationCodeEnabled = (int) $this->agentService->getSpecificAgentSetting(
            $agent,
            AgentCustomizedSettingKeyEnum::INVITATION_CODE_SAME_ACCOUNT
        );
        $code = ($isInvitationCodeEnabled == 1) ? $member->username : $memberCode->agent_code;

        /**
         * 今日傭金
         * 假設今日16號，下午2點會結算前一天的，判斷當前時間若為2點前顯示的數據是14號的，若為2點後顯示15號的
         */
        $settleTime = $baseTime->copy()->toFrontendTime()->setHour($this->settleHour)->toSystemTime()->format('H'); // 14:00:00
        $dateHour = $baseTime->format('H');
        $summaryTime = ($dateHour >= $settleTime) ?
            $baseTime->copy()->subDay()->format('Y-m-d 00:00:00') :
            $baseTime->copy()->subDays(2)->format('Y-m-d 00:00:00');
        $todayCommission = ThreeHierarchyBonusReport::where('member_id', $member->id)
            ->where('summary_time', $summaryTime)
            ->sum(DB::raw('rebate + down_rebate + down2_rebate'));

        /**
         * 是否已派發今日傭金
         * 2.0是自動派發,　所以可以透過three_hierarchy_withdraw_logs判斷是否有派發紀錄
         * 下午三點後派發
         */
        $payoutTime = ($dateHour >= $settleTime) ?
            $baseTime->copy()->toFrontendTime()->setHour($this->payoutHour)->toSystemTime()->format('Y-m-d H:00:00') :
            $baseTime->copy()->toFrontendTime()->setHour($this->payoutHour)->subDay()->toSystemTime()->format('Y-m-d H:i:s');

        // 是否有派發紀錄
        $threeHierarchyWithdrawLogsCount = ThreeHierarchyWithdrawLog::where('member_id', $member->id)
            ->where('created_at', '>=', $payoutTime)
            ->count();
        $is_payout = ($threeHierarchyWithdrawLogsCount > 0) ? 1 : 0;

        return ApiResponse::success(MemberTeam\InfoResource::make([
            'directPlayerCount' => (int) $directPlayerCount,
            'addedToday' => (int) $addedToday,
            'addedTodayGroupMember' => (int) $addedTodayGroupMember,
            'totalGroupMember' => (int) $totalGroupMember,
            'todayCommission' => (float) $todayCommission,
            'is_payout' => $is_payout,
            'code' => $code,
            'link' => 'https://' . $link . ((strpos($link, '?') != false) ? '' : '/Signup/') . $code,
        ]));
    }

    /**
     * 業績查詢
     *
     * OLD: api/memberTeam/achievement
     */
    public function achievement(AchievementRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $languageId = $userData->languageId;

        $baseTime = Carbon::now();

        $settleTime = $baseTime->copy()->toFrontendTime()->setHour($this->settleHour)->toSystemTime()->format('H'); // 14:00:00
        $dateHour = $baseTime->format('H');

        // type 1:查今日業績, 2:查昨日業績
        $type = isset($request->type) ? (int) $request->type : 1;
        $subDay = $type === 2 ? 2 : 1;

        $summaryTime = ($dateHour >= $settleTime) ?
            $baseTime->copy()->subDays($subDay)->format('Y-m-d 00:00:00') :
            $baseTime->copy()->subDays($subDay + 1)->format('Y-m-d 00:00:00');

        // Get Datas.
        $baseQuery = GameType::from('game_types as gt')
            ->leftjoin('game_type_names as gtn', fn ($join) => $join
                ->on('gtn.game_type_id', 'gt.id')
                ->where('gtn.language_id', $languageId)
            )
            ->leftjoin('three_hierarchy_bonus_report as report', fn ($join) => $join
                ->on('report.game_type_id', 'gt.id')
                ->where('report.member_id', $member->id)
                ->where('report.summary_time', $summaryTime)
            )
            ->where('gt.status', 1);

        $data = (clone $baseQuery)
            ->select([
                DB::raw('IFNULL(gtn.name, gt.name) as game_type'),
                DB::raw('IFNULL(SUM(report.bet + report.down_bet + report.down2_bet), 0) as totalAchievement'), // 總業績
                DB::raw('IFNULL(SUM(report.down_bet), 0) as directPlayersAchievement'), // 直屬業績
                DB::raw('IFNULL(SUM(report.down_bet + report.down2_bet), 0) as teamAchievement'), // 團隊業績
            ])
            ->groupBy(['gt.name', 'gtn.name'])
            ->get();

        $total = (clone $baseQuery)
            ->select(
                DB::raw('IFNULL(SUM(report.bet + report.down_bet + report.down2_bet), 0) as totalAchievement'), // 總業績
                DB::raw('IFNULL(SUM(report.down_bet), 0) as directPlayersAchievement'), // 直屬業績
                DB::raw('IFNULL(SUM(report.down_bet + report.down2_bet), 0) as teamAchievement') // 團隊業績
            )
            ->first();

        return ApiResponse::success(MemberTeam\AchievementResource::make([
            'data' => $data,
            'total' => $total,
        ]));
    }

    /**
     * 團隊管理
     *
     * OLD: api/memberTeam/teamManagement
     */
    public function teamManagement(TeamManagementRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $targetUsername = $request->username;

        $baseTime = Carbon::now();

        $settleTime = $baseTime->copy()->toFrontendTime()->setHour($this->settleHour)->toSystemTime()->format('H'); // 14:00:00
        $dateHour = $baseTime->format('H');

        $summaryTime = ($dateHour >= $settleTime) ?
            $baseTime->copy()->subDay()->format('Y-m-d 00:00:00') :
            $baseTime->copy()->subDays(2)->format('Y-m-d 00:00:00');

        // 取得直屬玩家清單
        $directPlayersList = MemberAgent::where('topper_member_id', $member->id)->pluck('member_id');
        $targetMember = Member::where('username', $targetUsername)
            ->whereIn('id', $directPlayersList) // 只能查自己的直屬
            ->select('id')
            ->first();

        if (!is_object($targetMember)) {
            return ApiResponse::success(); // 查無此直屬會員：回成功、data 為空陣列；舊系統 API_Frontend 在這種情況也回空陣列
        }

        $achievements = ThreeHierarchyBonusReport::where('member_id', $member->id)
            ->where('from_member_id', $targetMember->id)
            ->where('summary_time', $summaryTime)
            ->sum('down_bet');

        return ApiResponse::success(MemberTeam\TeamManagementResource::make([
            'username' => $targetUsername,
            'achievements' => $achievements,
        ]));

    }

    /**
     * 代理報表頁面資訊
     *
     * OLD: api/memberTeam/agentAchievement
     */
    public function agentAchievement(Request $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $languageId = $userData->languageId;

        $baseTime = Carbon::now();

        $settleTime = $baseTime->copy()->toFrontendTime()->setHour($this->settleHour)->toSystemTime()->format('H'); // 14:00:00
        $dateHour = $baseTime->format('H');

        $summaryTime = ($dateHour >= $settleTime) ?
            $baseTime->copy()->subDay() :
            $baseTime->copy()->subDays(2);

        $lastPeriodSummaryTime = $summaryTime->copy()->subDay()->format('Y-m-d 00:00:00');
        $summaryTime = $summaryTime->format('Y-m-d 00:00:00');

        // 本期直屬下級業績
        // 本期團隊業績
        // 本期總業績
        $data = ThreeHierarchyBonusReport::where('member_id', $member->id)
            ->where('summary_time', $summaryTime)
            ->select(
                DB::raw('IFNULL(SUM(down_bet), 0) as directPlayersAchievement'),
                DB::raw('IFNULL(SUM(down_bet + down2_bet), 0) as teamAchievement'),
                DB::raw('IFNULL(SUM(bet + down_bet + down2_bet), 0) as totalAchievement')
            )
            ->first();

        // 上期傭金
        $lastpPeriodCommission = ThreeHierarchyBonusReport::where('member_id', $member->id)
            ->where('summary_time', $lastPeriodSummaryTime)
            ->sum(DB::raw('rebate + down_rebate + down2_rebate'));

        // 歷史總傭金
        $historicalTotalCommission = ThreeHierarchySummaryBonus::where('member_id', $member->id)
            ->sum('bonus_amount');

        return ApiResponse::success(MemberTeam\AgentAchievementResource::make([
            'directPlayersAchievement' => $data?->directPlayersAchievement ?? 0,
            'teamAchievement' => $data?->teamAchievement ?? 0,
            'totalAchievement' => $data?->totalAchievement ?? 0,
            'lastpPeriodCommission' => $lastpPeriodCommission,
            'historicalTotalCommission' => $historicalTotalCommission,
        ]));
    }
}
