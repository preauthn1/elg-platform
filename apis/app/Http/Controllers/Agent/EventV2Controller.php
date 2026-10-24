<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\EventV2\EventV2PeriodEnum;
use App\Enums\EventV2\EventV2RejectJoinStatusEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Enums\MemberRestrictionTargetEnum;
use App\Enums\Score\ScoreLogDirectionEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\EventV2\AllowJoinRequest;
use App\Http\Requests\Agent\EventV2\CreateRequest;
use App\Http\Requests\Agent\EventV2\DetailRequest;
use App\Http\Requests\Agent\EventV2\JoinListRequest;
use App\Http\Requests\Agent\EventV2\ListRequest;
use App\Http\Requests\Agent\EventV2\QuitRequest;
use App\Http\Requests\Agent\EventV2\RecycleListRequest;
use App\Http\Requests\Agent\EventV2\RejectDetailRequest;
use App\Http\Requests\Agent\EventV2\RejectListRequest;
use App\Http\Requests\Agent\EventV2\TargetOptionsRequest;
use App\Http\Requests\Agent\EventV2\TransactionListRequest;
use App\Http\Requests\Agent\EventV2\UpdateInfoRequest;
use App\Http\Requests\Agent\EventV2\UpdateRequest;
use App\Http\Requests\Agent\EventV2\UpdateWalletBalanceRequest;
use App\Http\Requests\Agent\EventV2\WalletManagementRequest;
use App\Http\Resources\Agent\EventV2\EventListResource;
use App\Http\Resources\Agent\EventV2\EventV2DetailResource;
use App\Http\Resources\Agent\EventV2\EventV2JoinListResource;
use App\Http\Resources\Agent\EventV2\EventV2ListResource;
use App\Http\Resources\Agent\EventV2\EventV2RecycleListResource;
use App\Http\Resources\Agent\EventV2\EventV2RejectDetailResource;
use App\Http\Resources\Agent\EventV2\EventV2RejectListResource;
use App\Http\Resources\Agent\EventV2\EventV2WalletManagementResource;
use App\Http\Resources\Agent\EventV2\TransactionListResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Models\Agent;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailExcludeAgent;
use App\Models\EventV2DetailExcludeVendor;
use App\Models\EventV2DetailGameType;
use App\Models\EventV2DetailLang;
use App\Models\EventV2DetailTarget;
use App\Models\EventV2DetailVendor;
use App\Models\EventV2Recycle;
use App\Models\EventV2RejectJoin;
use App\Models\EventV2RejectRecord;
use App\Models\EventV2UserJoin;
use App\Models\MemberScoreLog;
use App\Models\MemberWallet;
use App\Models\WalletTransactionLog;
use App\Services\EventV2\AngpowService;
use App\Services\EventV2\EventV2Service;
use App\Services\EventV2\EventV2TargetScope;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

#[Group('EventV2 (活動 V2)')]
class EventV2Controller extends Controller
{
    const DEFAULT_LANGUAGE_id = 1;

    public function __construct(
        protected EventV2Service $eventV2Service,
    ) {}

    public function eventList()
    {
        $data = EventV2::select(['id', 'name', 'handler_class'])->orderBy('name', 'desc')->get();

        return ApiResponse::success(EventListResource::collection($data));
    }

    /**
     * 目標用戶的可選項（target/tickets#4234）：依活動的代理商帳號列出這條線裡的會員群組，或以關鍵字搜尋會員。
     * 範圍規則見 EventV2TargetScope；建立／更新驗證用同一份規則。
     */
    public function targetOptions(TargetOptionsRequest $request)
    {
        $scope = EventV2TargetScope::forAgentId((int) $request->agent_id);
        if ($scope === null) {
            return ApiResponse::success([]);
        }

        $options = MemberRestrictionTargetEnum::from((int) $request->target_type) === MemberRestrictionTargetEnum::MEMBER
            ? $scope->memberOptions((string) $request->keyword)
            : $scope->groupOptions();

        return ApiResponse::success($options->values());
    }

    public function joinList(JoinListRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $languageId = $this->getAgentContext()->languageId;

        $query = $this->joinListQuery($request, $agent);

        // 統計卡（target/tickets#4235）：依同一組篩選算全部筆數，不是當頁；沒資料回 0 不回 null。
        // 一律依參加紀錄上凍結的 withdraw_type 分流：主錢包型算金額、積分型算積分，兩者不混
        $summary = (clone $query)->selectRaw(self::JOIN_SUMMARY_SQL, [
            EventV2WithdrawTypeEnum::MAIN_WALLET->value,
            EventV2WithdrawTypeEnum::SCORE->value,
            EventV2WithdrawTypeEnum::MAIN_WALLET->value, EventV2UserJoinStatusEnum::COMPLETE->value,
            EventV2WithdrawTypeEnum::MAIN_WALLET->value, EventV2UserJoinStatusEnum::JOIN->value,
            EventV2WithdrawTypeEnum::MAIN_WALLET->value, EventV2UserJoinStatusEnum::QUIT->value, EventV2UserJoinStatusEnum::OPERATOR_QUIT->value,
            EventV2WithdrawTypeEnum::MAIN_WALLET->value, EventV2UserJoinStatusEnum::SYSTEM_INIT->value,
        ])->toBase()->first();

        $data = (clone $query)->select(
            [
                'event_v2_user_joins.id',
                'event_v2_user_joins.event_v2_detail_id',
                'agents.username AS agent_username',
                'members.username AS member_username',
                'event_v2_user_joins.reward',
                'score_costs.score_cost',
                'event_v2_user_joins.withdraw_type',
                'event_v2_user_joins.balance_limit',
                'event_v2_user_joins.flow_limit',
                'event_v2_user_joins.withdraw_limit',
                'event_v2_user_joins.withdraw',
                'event_v2_bonus_codes.bonus_code',
                'event_v2_user_joins.ip',
                'event_v2_user_joins.status',
                'event_v2_user_joins.end_time',
                'event_v2_user_joins.created_at',
            ]
        )
            ->selectRaw('event_v2_user_joins.created_at AS join_time')
            ->with(['eventV2Detail', 'eventV2Detail.detailLang'])
            ->orderBy('event_v2_user_joins.created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        foreach ($data as $key => $value) {
            $eventDetail = $value->eventV2Detail;
            // 若語系沒設定,預設抓英文
            $detailLang = $eventDetail->detailLang->where('language_id', $languageId)->isNotEmpty() ?
                $eventDetail->detailLang->where('language_id', $languageId)->first() :
                $eventDetail->detailLang->where('language_id', self::DEFAULT_LANGUAGE_id)->first();

            $data[$key]->name = $detailLang->name ?? '';
        }

        return ApiResponse::successWithPagination(
            data: EventV2JoinListResource::collection($data),
            paginator: OnlyPaginateResource::make($data),
            extra: [
                'summary' => [
                    'total_count' => (int) ($summary->total_count ?? 0),
                    ...array_map(
                        static fn (string $key): string => Common::numAdd('0', (string) ($summary->{$key} ?? 0), 4),
                        array_combine(self::JOIN_SUMMARY_AMOUNT_KEYS, self::JOIN_SUMMARY_AMOUNT_KEYS),
                    ),
                ],
            ],
        );
    }

    /**
     * 參加紀錄統計卡（target/tickets#4235）的八個數字，一句查完；綁定順序見 joinList()。
     * - total_reward 總獎勵金額：主錢包型 reward，全部狀態（＝列表獎勵欄加總）
     * - total_score_reward 總獲得積分：積分型 reward
     * - total_score 總消耗積分：實際扣掉的門票積分（score_costs），是「消耗」不是「獲得」
     * - total_issued 已發放：主錢包型、完成，取實領 withdraw
     * - total_joining／total_quit／total_reset 進行中／放棄（含後台操作放棄）／重置：主錢包型 reward
     * 驗算：total_reward ＝ 完成筆 reward ＋ total_joining ＋ total_quit ＋ total_reset；total_issued 取實領，不在等式內。
     */
    private const string JOIN_SUMMARY_SQL = 'COUNT(*) AS total_count'
        . ', COALESCE(SUM(CASE WHEN event_v2_user_joins.withdraw_type = ? THEN event_v2_user_joins.reward END), 0) AS total_reward'
        . ', COALESCE(SUM(CASE WHEN event_v2_user_joins.withdraw_type = ? THEN event_v2_user_joins.reward END), 0) AS total_score_reward'
        . ', COALESCE(SUM(score_costs.score_cost), 0) AS total_score'
        . ', COALESCE(SUM(CASE WHEN event_v2_user_joins.withdraw_type = ? AND event_v2_user_joins.status = ? THEN event_v2_user_joins.withdraw END), 0) AS total_issued'
        . ', COALESCE(SUM(CASE WHEN event_v2_user_joins.withdraw_type = ? AND event_v2_user_joins.status = ? THEN event_v2_user_joins.reward END), 0) AS total_joining'
        . ', COALESCE(SUM(CASE WHEN event_v2_user_joins.withdraw_type = ? AND event_v2_user_joins.status IN (?, ?) THEN event_v2_user_joins.reward END), 0) AS total_quit'
        . ', COALESCE(SUM(CASE WHEN event_v2_user_joins.withdraw_type = ? AND event_v2_user_joins.status = ? THEN event_v2_user_joins.reward END), 0) AS total_reset';

    /** 統計卡的金額／積分鍵（total_count 以外），一律回 4 位小數字串 */
    private const array JOIN_SUMMARY_AMOUNT_KEYS = [
        'total_reward', 'total_score_reward', 'total_score', 'total_issued', 'total_joining', 'total_quit', 'total_reset',
    ];

    /**
     * 參加紀錄的篩選母體：列表與統計卡共用同一組 join／範疇／篩選，只差 select 與分頁。
     * score_costs 是每筆參加扣掉的門票積分（member_score_logs 參照 event_v2_user_joins.id 的扣除加總，
     * target/tickets#4235）；沒扣分的活動（非紅包）為 NULL，前端顯示「—」。
     */
    private function joinListQuery(JoinListRequest $request, Agent $agent): Builder
    {
        $scoreCosts = MemberScoreLog::query()
            ->selectRaw('reference_id, SUM(score) AS score_cost')
            ->where('reference_type', ScoreSourceTypeEnum::EVENT_V2_USER_JOIN->value)
            ->where('direction', ScoreLogDirectionEnum::DECREASE->value)
            ->groupBy('reference_id');

        return EventV2UserJoin::query()
            ->leftJoin('event_v2_details', 'event_v2_details.id', '=', 'event_v2_user_joins.event_v2_detail_id')
            ->leftJoin('event_v2', 'event_v2.id', '=', 'event_v2_details.event_v2_id')
            ->leftJoin('event_v2_bonus_codes', 'event_v2_bonus_codes.event_v2_user_join_id', '=', 'event_v2_user_joins.id')
            ->leftJoin('agents', 'agents.id', '=', 'event_v2_details.agent_id')
            ->leftJoin('members', 'members.id', '=', 'event_v2_user_joins.member_id')
            ->leftJoinSub($scoreCosts, 'score_costs', 'score_costs.reference_id', '=', 'event_v2_user_joins.id')
            // admin跟admin子帳號可以看全部
            ->when($agent->id != 1 && $agent->host_id != 1, function ($query) use ($agent) {
                $hierarchy_path = $agent->hierarchy_path;
                // 代理子帳號可以看到代理的活動
                if ($agent->host_id != 0) {
                    $upperAgent = Agent::where('id', $agent->host_id)->first();
                    $hierarchy_path = $upperAgent->hierarchy_path;
                }

                return $query->where('agents.hierarchy_path', 'like', $hierarchy_path . '%');
            })
            ->when($request->event_v2_id, function ($query) use ($request) {
                return $query->where('event_v2.id', $request->event_v2_id);
            })
            ->when($request->event_v2_detail_id, function ($query) use ($request) {
                return $query->where('event_v2_user_joins.event_v2_detail_id', $request->event_v2_detail_id);
            })
            ->when($request->member_username, function ($query) use ($request) {
                return $query->where('members.username', $request->member_username);
            })
            ->when($request->agent_username, function ($query) use ($request) {
                return $query->where('agents.username', $request->agent_username);
            })
            ->when($request->start_time && $request->end_time, function ($query) use ($request) {
                $startTime = Carbon::parseFrontend($request->start_time . ':00')->toSystemTime()->toDateTimeString();
                $endTime = Carbon::parseFrontend($request->end_time . ':59')->toSystemTime()->toDateTimeString();

                return $query->whereBetween('event_v2_user_joins.created_at', [$startTime, $endTime]);
            })
            ->when($request->status, function ($query) use ($request) {
                return $query->where('event_v2_user_joins.status', $request->status);
            });
    }

    public function recycleList(RecycleListRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $languageId = $this->getAgentContext()->languageId;

        $data = EventV2Recycle::select(
            [
                'event_v2_recycles.id',
                'event_v2_recycles.event_v2_user_join_id',
                'event_v2_recycles.final_balance',
                'event_v2_recycles.withdraw',
                'event_v2_recycles.recycle_amount',
                'event_v2_recycles.status',
                'event_v2_recycles.created_at',
                'agents.username AS agent_username',
                'members.username AS member_username',
            ]
        )
            ->leftJoin('event_v2_user_joins', 'event_v2_recycles.event_v2_user_join_id', '=', 'event_v2_user_joins.id')
            ->leftJoin('event_v2_details', 'event_v2_details.id', '=', 'event_v2_user_joins.event_v2_detail_id')
            ->leftJoin('event_v2', 'event_v2.id', '=', 'event_v2_details.event_v2_id')
            ->leftJoin('agents', 'agents.id', '=', 'event_v2_details.agent_id')
            ->leftJoin('members', 'members.id', '=', 'event_v2_user_joins.member_id')
            ->with(['eventV2UserJoin.eventV2Detail.detailLang'])
            // admin跟admin子帳號可以看全部
            ->when($agent->id != 1 && $agent->host_id != 1, function ($query) use ($agent) {
                $hierarchy_path = $agent->hierarchy_path;
                // 代理子帳號可以看到代理的活動
                if ($agent->host_id != 0) {
                    $upperAgent = Agent::where('id', $agent->host_id)->first();
                    $hierarchy_path = $upperAgent->hierarchy_path;
                }

                return $query->where('agents.hierarchy_path', 'like', $hierarchy_path . '%');
            })
            ->when($request->event_v2_id, function ($query) use ($request) {
                return $query->where('event_v2.id', $request->event_v2_id);
            })
            ->when($request->member_username, function ($query) use ($request) {
                return $query->where('members.username', $request->member_username);
            })
            ->when($request->agent_username, function ($query) use ($request) {
                return $query->where('agents.username', $request->agent_username);
            })
            ->when($request->start_time && $request->end_time, function ($query) use ($request) {
                $startTime = Carbon::parseFrontend($request->start_time . ':00')->toSystemTime()->toDateTimeString();
                $endTime = Carbon::parseFrontend($request->end_time . ':59')->toSystemTime()->toDateTimeString();

                return $query->whereBetween('event_v2_recycles.created_at', [$startTime, $endTime]);
            })
            ->when($request->status, function ($query) use ($request) {
                return $query->where('event_v2_recycles.status', $request->status);
            })
            ->orderBy('event_v2_recycles.created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        foreach ($data as $key => $value) {
            $eventDetail = $value->eventV2UserJoin->eventV2Detail;
            // 若語系沒設定,預設抓英文
            $detailLang = $eventDetail->detailLang->where('language_id', $languageId)->isNotEmpty() ?
                $eventDetail->detailLang->where('language_id', $languageId)->first() :
                $eventDetail->detailLang->where('language_id', self::DEFAULT_LANGUAGE_id)->first();

            $data[$key]->name = $detailLang->name ?? '';
        }

        return ApiResponse::successWithPagination(
            data: EventV2RecycleListResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    public function rejectList(RejectListRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $languageId = $this->getAgentContext()->languageId;

        $data = EventV2RejectJoin::select([
            'event_v2_reject_joins.id',
            'event_v2_reject_joins.event_v2_detail_id',
            'event_v2_reject_joins.event_v2_user_join_id',
            'agents.username AS agent_username',
            'members.username AS member_username',
            'event_v2_reject_records.validate_code',
            'event_v2_reject_records.ip',
            'event_v2_reject_joins.allowed_time',
            'event_v2_reject_joins.status',
            'event_v2_reject_records.updated_at',
        ])
            ->with(['eventV2Detail', 'eventV2Detail.detailLang'])
            ->leftJoin('event_v2_details', 'event_v2_details.id', '=', 'event_v2_reject_joins.event_v2_detail_id')
            ->leftJoin('members', 'members.id', '=', 'event_v2_reject_joins.member_id')
            ->leftJoin('agents', 'agents.id', '=', 'event_v2_details.agent_id')
            ->leftJoin('event_v2_reject_records', 'event_v2_reject_records.id', '=', 'event_v2_reject_joins.last_event_v2_reject_record_id')
            // admin跟admin子帳號可以看全部
            ->when($agent->id != 1 && $agent->host_id != 1, function ($query) use ($agent) {
                $hierarchy_path = $agent->hierarchy_path;
                // 代理子帳號可以看到代理的活動
                if ($agent->host_id != 0) {
                    $upperAgent = Agent::where('id', $agent->host_id)->first();
                    $hierarchy_path = $upperAgent->hierarchy_path;
                }

                return $query->where('agents.hierarchy_path', 'like', $hierarchy_path . '%');
            })
            ->when($request->event_v2_id, function ($query) use ($request) {
                return $query->where('event_v2_details.event_v2_id', $request->event_v2_id);
            })
            ->when($request->member_username, function ($query) use ($request) {
                return $query->where('members.username', $request->member_username);
            })
            ->when($request->agent_username, function ($query) use ($request) {
                return $query->where('agents.username', $request->agent_username);
            })
            ->when($request->start_time && $request->end_time, function ($query) use ($request) {
                $startTime = Carbon::parseFrontend($request->start_time . ':00')->toSystemTime()->toDateTimeString();
                $endTime = Carbon::parseFrontend($request->end_time . ':59')->toSystemTime()->toDateTimeString();

                return $query->whereBetween('event_v2_reject_records.updated_at', [$startTime, $endTime]);
            })
            ->when($request->status, function ($query) use ($request) {
                return $query->where('event_v2_reject_joins.status', $request->status);
            })
            ->orderBy('event_v2_reject_records.updated_at', 'desc')
            ->paginate($request->input('per_page', 15));

        foreach ($data as $key => $value) {
            $eventDetail = $value->eventV2Detail;
            // 若語系沒設定,預設抓英文
            $detailLang = $eventDetail->detailLang->where('language_id', $languageId)->isNotEmpty() ?
                $eventDetail->detailLang->where('language_id', $languageId)->first() :
                $eventDetail->detailLang->where('language_id', self::DEFAULT_LANGUAGE_id)->first();

            $data[$key]->name = $detailLang->name ?? '';
        }

        return ApiResponse::successWithPagination(
            data: EventV2RejectListResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    public function allowJoin(AllowJoinRequest $request)
    {
        $rejectJoin = $this->findRejectJoinInScope((int) $request->id);

        $rejectJoin->update([
            'allowed_time' => Carbon::now()->toDateTimeString(),
            'status' => EventV2RejectJoinStatusEnum::ALLOW_JOIN->value,
        ]);

        return ApiResponse::success();
    }

    public function rejectDetail(RejectDetailRequest $request)
    {
        $this->findRejectJoinInScope((int) $request->reject_join_id);

        $data = EventV2RejectRecord::where('event_v2_reject_join_id', $request->reject_join_id)
            ->orderBy('updated_at', 'desc')
            ->get();

        return ApiResponse::success(EventV2RejectDetailResource::collection($data));
    }

    /**
     * 取回落在本代理可視範疇內的拒絕名單；範疇比照讀取端 rejectList()（活動所屬代理）。
     *
     * 查無與越權都回同一個 9003，避免用回應差異枚舉他人代理線的 reject_join_id。
     */
    private function findRejectJoinInScope(int $rejectJoinId): EventV2RejectJoin
    {
        $agent = $this->getAgentContext()->agent;
        $rejectJoin = EventV2RejectJoin::where('id', $rejectJoinId)->first();

        if ($rejectJoin === null || !$agent->canSeeAgent($rejectJoin->eventV2Detail?->agent_id)) {
            throw new ApiException(__('error_message.9003')); // 權限不足
        }

        return $rejectJoin;
    }

    public function transactionList(TransactionListRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $data = WalletTransactionLog::select([
            'agents.username AS agent_username',
            'members.username AS member_username',
            'vendors.name AS vendor_name',
            'event_v2_wallet_transaction_logs.account_username',
            'event_v2_wallet_transaction_logs.event_v2_user_join_id',
            'event_v2_user_joins.event_v2_detail_id',
            'event_v2_user_joins.status AS user_join_status',
            'operate_agents.username AS operate_agent_username',
            'wallet_transaction_logs.*',
        ])
            ->leftJoin('agents', 'agents.id', '=', 'wallet_transaction_logs.agent_id')
            ->leftJoin('members', 'members.id', '=', 'wallet_transaction_logs.member_id')
            ->leftJoin('vendors', 'vendors.id', '=', 'wallet_transaction_logs.vendor_id')
            ->leftJoin('event_v2_wallet_transaction_logs', 'event_v2_wallet_transaction_logs.wallet_transaction_log_id', '=', 'wallet_transaction_logs.id')
            ->leftJoin('agents as operate_agents', 'operate_agents.id', '=', 'event_v2_wallet_transaction_logs.operate_agent_id')
            ->leftJoin('event_v2_user_joins', 'event_v2_user_joins.id', '=', 'event_v2_wallet_transaction_logs.event_v2_user_join_id')
            // admin跟admin子帳號可以看全部
            ->when($agent->id != 1 && $agent->host_id != 1, function ($query) use ($agent) {
                $hierarchy_path = $agent->hierarchy_path;
                // 代理子帳號可以看到代理的活動
                if ($agent->host_id != 0) {
                    $upperAgent = Agent::where('id', $agent->host_id)->first();
                    $hierarchy_path = $upperAgent->hierarchy_path;
                }

                return $query->where('agents.hierarchy_path', 'like', $hierarchy_path . '%');
            })
            ->when($request->start_time && $request->end_time, function ($query) use ($request) {
                $startTime = Carbon::parseFrontend($request->start_time)->toSystemTime()->toDateTimeString();
                $endTime = Carbon::parseFrontend($request->end_time)->toSystemTime()->toDateTimeString();

                return $query->whereBetween('wallet_transaction_logs.created_at', [$startTime, $endTime]);
            })
            ->when($request->agent_username, function ($query) use ($request) {
                return $query->where('agents.username', $request->agent_username);
            })
            ->where('members.username', $request->member_username)
            ->where('wallet_type_id', WalletEnum::CODE_EVENT_WALLET)
            ->orderByDesc('wallet_transaction_logs.created_at')
            ->paginate($request->input('per_page', 15));

        return ApiResponse::successWithPagination(
            data: TransactionListResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    public function list(ListRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $languageId = $this->getAgentContext()->languageId;

        $eventDetails = EventV2Detail::select([
            'event_v2_details.id',
            'agents.username AS agent_username',
            // 活動分類（類型列原名，與建立活動「選擇活動分類」下拉同一份）；name 下面會換成活動自己的多語系名稱
            'event_v2_details.event_v2_id',
            'event_v2.name AS event_type_name',
            'event_v2_details.start_time',
            'event_v2_details.end_time',
            'event_v2_details.sort',
            'event_v2_details.status',
            'event_v2_details.withdraw_type',
            'event_v2_details.member_period',
            'event_v2_details.total_period',
            'event_v2_details.created_at',
        ])
            ->with([
                'detailLang' => function ($query) use ($languageId) {
                    $query->whereIn('language_id', [self::DEFAULT_LANGUAGE_id, $languageId]);
                },
            ])
            ->leftJoin('event_v2', 'event_v2.id', '=', 'event_v2_details.event_v2_id')
            ->leftJoin('agents', 'agents.id', '=', 'event_v2_details.agent_id')
            ->leftJoin('event_v2_detail_langs', 'event_v2_detail_langs.event_v2_detail_id', '=', 'event_v2_details.id')
            // admin跟admin子帳號可以看全部
            ->when($agent->id != 1 && $agent->host_id != 1, function ($query) use ($agent) {
                $hierarchy_path = $agent->hierarchy_path;
                // 代理子帳號可以看到代理的活動
                if ($agent->host_id != 0) {
                    $upperAgent = Agent::where('id', $agent->host_id)->first();
                    $hierarchy_path = $upperAgent->hierarchy_path;
                }

                return $query->where('agents.hierarchy_path', 'like', $hierarchy_path . '%');
            })
            ->when($request->agent_username, function ($query) use ($request) {
                return $query->where('agents.username', $request->agent_username);
            })
            ->when($request->name, function ($query) use ($request) {
                return $query->where('event_v2_detail_langs.name', 'like', '%' . $request->name . '%');
            })
            ->when($request->withdraw_type !== null, function ($query) use ($request) {
                return $query->where('event_v2_details.withdraw_type', $request->withdraw_type);
            })
            ->when($request->status !== null, function ($query) use ($request) {
                return $query->where('event_v2_details.status', $request->status);
            })
            ->distinct()
            ->orderBy('event_v2_details.created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        foreach ($eventDetails as $key => $eventDetail) {
            // 若語系沒設定,預設抓英文
            $detailLang = $eventDetail->detailLang->where('language_id', $languageId)->isNotEmpty() ?
                $eventDetail->detailLang->where('language_id', $languageId)->first() :
                $eventDetail->detailLang->where('language_id', self::DEFAULT_LANGUAGE_id)->first();

            $eventDetails[$key]->name = $detailLang->name ?? '';
        }

        return ApiResponse::successWithPagination(
            data: EventV2ListResource::collection($eventDetails),
            paginator: OnlyPaginateResource::make($eventDetails)
        );
    }

    public function detail(DetailRequest $request)
    {
        $eventDetail = EventV2Detail::select([
            'id',
            'event_v2_id',
            'agent_id',
            'condition_formula',
            'condition_config',
            'reward_formula',
            'reward_config',
            'balance_formula',
            'balance_config',
            'flow_formula',
            'flow_config',
            'withdraw_formula',
            'withdraw_config',
            'bonus_vendor_id',
            'ip_block',
            'phone_block',
            'account_block',
            'join_withdraw',
            'cost_type',
            'withdraw_type',
            'member_period',
            'total_period',
            'start_time',
            'end_time',
            'sort',
            'is_recycle',
            'status',
        ])
            ->with([
                'detailLang',
                'targets.target',
                'detailExcludeAgents',
                'detailGameTypes',
                'detailVendors',
                'detailExcludeVendors',
            ])
            ->where('id', $request->event_detail_id)
            ->first();

        // 範疇比照 list()：活動所屬代理須落在登入者可視範圍內，否則等同可直接讀別線活動的設定（4995）
        if ($eventDetail === null || !$this->getAgentContext()->agent->canSeeAgent($eventDetail->agent_id)) {
            return ApiResponse::error('活動不存在');
        }

        return ApiResponse::success(EventV2DetailResource::make($eventDetail));
    }

    public function create(CreateRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        if ($agent->id !== 1 && $agent->host_id !== 1) {
            return ApiResponse::error('無權限操作');
        }

        if (!EventV2::find($request->event_v2_id)) {
            return ApiResponse::error('活動不存在');
        }

        return DB::transaction(fn () => $this->createEventV2Detail($request));
    }

    /**
     * 建立活動詳細及其關聯資料
     */
    private function createEventV2Detail(CreateRequest $request): JsonResponse
    {
        $detail = EventV2Detail::create($this->getDetailAttributes($request));

        $this->createDetailLangs($detail, $request);
        $this->syncTargets($detail, $request->targetType(), $request->targetIds());
        $this->createExcludeAgents($detail, $request);
        $this->createGameTypes($detail, $request);
        $this->createVendors($detail, $request);
        $this->createExcludeVendors($detail, $request);

        return ApiResponse::success();
    }

    /**
     * 取得活動詳細屬性
     */
    private function getDetailAttributes(CreateRequest $request): array
    {
        // 領取去向一律問 Request（舊後台不送時為主錢包）；積分型現階段強制參加即領獎，
        // 未來若開放「先打流水、達標才領積分」只需拿掉這個強制
        $withdrawType = $request->effectiveWithdrawType();

        return [
            'event_v2_id' => $request->event_v2_id,
            'agent_id' => $request->agent_id,
            'condition_formula' => $request->condition_formula,
            'condition_config' => AngpowService::timeWindowToSystem($request->condition_config),
            'reward_formula' => $request->reward_formula,
            'reward_config' => $request->reward_config,
            'balance_formula' => $request->balance_formula,
            'balance_config' => $request->balance_config,
            'flow_formula' => $request->flow_formula,
            'flow_config' => $request->flow_config,
            'withdraw_formula' => $request->withdraw_formula,
            'withdraw_config' => $request->withdraw_config,
            'bonus_vendor_id' => $request->bonus_vendor_id,
            'ip_block' => $request->ip_block ? implode(',', $request->ip_block) : null,
            'phone_block' => $request->phone_block ? implode(',', $request->phone_block) : null,
            'account_block' => $request->account_block ? implode(',', $request->account_block) : null,
            'join_withdraw' => $withdrawType->isScore() ? 1 : $request->join_withdraw,
            'cost_type' => $request->cost_type,
            'withdraw_type' => $withdrawType->value,
            EventV2PeriodEnum::MEMBER_FIELD => $request->effectivePeriod(EventV2PeriodEnum::MEMBER_FIELD)->value,
            EventV2PeriodEnum::TOTAL_FIELD => $request->effectivePeriod(EventV2PeriodEnum::TOTAL_FIELD)->value,
            'start_time' => Carbon::parseFrontend($request->start_time)->toSystemTime()->toDateTimeString(),
            'end_time' => Carbon::parseFrontend($request->end_time)->toSystemTime()->toDateTimeString(),
            'is_recycle' => $request->is_recycle,
            'status' => $request->status,
        ];
    }

    /**
     * 建立活動語系資料
     */
    private function createDetailLangs(EventV2Detail $detail, CreateRequest $request): void
    {
        if (!$request->detail_lang) {
            return;
        }

        foreach ($request->detail_lang as $value) {
            EventV2DetailLang::create([
                'event_v2_detail_id' => $detail->id,
                'language_id' => $value['language_id'],
                'name' => $value['name'],
                'image' => $value['image'],
                'reward_name' => $value['reward_name'],
                'content' => $value['content'],
            ]);
        }
    }

    /**
     * 目標用戶整組重寫（target/tickets#4234）：先清掉再寫入；$type 為 null＝不限制（不留任何一列）。
     *
     * @param  list<int>  $ids
     */
    private function syncTargets(EventV2Detail $detail, ?MemberRestrictionTargetEnum $type, array $ids): void
    {
        EventV2DetailTarget::query()->where('event_v2_detail_id', $detail->id)->delete();
        if ($type === null || $ids === []) {
            return;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            EventV2DetailTarget::query()->insert(array_map(fn (int $id) => [
                'event_v2_detail_id' => $detail->id,
                'target_type' => $type->morphAlias(),
                'target_id' => $id,
            ], $chunk));
        }
    }

    /**
     * 建立排除代理資料
     */
    private function createExcludeAgents(EventV2Detail $detail, CreateRequest $request): void
    {
        if (!$request->exclude_agent) {
            return;
        }

        foreach ($request->exclude_agent as $value) {
            EventV2DetailExcludeAgent::insert([
                'event_v2_detail_id' => $detail->id,
                'agent_id' => $value,
            ]);
        }
    }

    /**
     * 建立遊戲分類限制資料
     */
    private function createGameTypes(EventV2Detail $detail, CreateRequest $request): void
    {
        if (!$request->restrict_game_type) {
            return;
        }

        foreach ($request->restrict_game_type as $value) {
            EventV2DetailGameType::insert([
                'event_v2_detail_id' => $detail->id,
                'game_type_id' => $value,
            ]);
        }
    }

    /**
     * 建立遊戲廠商限制資料
     */
    private function createVendors(EventV2Detail $detail, CreateRequest $request): void
    {
        if (!$request->restrict_vendor) {
            return;
        }

        foreach ($request->restrict_vendor as $value) {
            EventV2DetailVendor::insert([
                'event_v2_detail_id' => $detail->id,
                'vendor_id' => $value,
            ]);
        }
    }

    /**
     * 建立排除遊戲廠商資料
     */
    private function createExcludeVendors(EventV2Detail $detail, CreateRequest $request): void
    {
        if (!$request->exclude_vendor_data) {
            return;
        }

        foreach ($request->exclude_vendor_data as $value) {
            EventV2DetailExcludeVendor::insert([
                'event_v2_detail_id' => $detail->id,
                'vendor_id' => $value,
            ]);
        }
    }

    /** @noinspection D */
    public function update(UpdateRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        if ($agent->id !== 1 && $agent->host_id !== 1) {
            return ApiResponse::error('無權限操作');
        }

        if (!EventV2::find($request->event_v2_id)) {
            return ApiResponse::error('活動不存在');
        }

        return DB::transaction(fn () => $this->updateEventV2Detail($request));
    }

    /**
     * 更新活動詳細及其關聯資料
     */
    private function updateEventV2Detail(UpdateRequest $request): JsonResponse
    {
        $detail = EventV2Detail::find($request->event_detail_id);
        $updateData = $this->getUpdateDetailAttributes($request, $detail);
        $detail->update($updateData);

        $this->updateDetailLangs($detail, $request);
        // 舊後台不送目標用戶：不動原值；送了（含 0＝清成不限制）才整組重寫
        if ($request->has('target_type')) {
            $this->syncTargets($detail, $request->targetType(), $request->targetIds());
        }
        $this->updateExcludeAgents($detail, $request);
        $this->updateGameTypes($detail, $request);
        $this->updateVendors($detail, $request);
        $this->updateExcludeVendors($detail, $request);

        return ApiResponse::success();
    }

    /**
     * 取得更新活動詳細屬性
     */
    private function getUpdateDetailAttributes(UpdateRequest $request, EventV2Detail $detail): array
    {
        // 沒送 withdraw_type（舊後台）沿用現值；積分型現階段強制參加即領獎
        $withdrawType = $request->effectiveWithdrawType();

        $updateData = [
            'agent_id' => $request->agent_id,
            'condition_formula' => $request->condition_formula,
            'condition_config' => AngpowService::timeWindowToSystem($request->condition_config),
            'reward_formula' => $request->reward_formula,
            'reward_config' => $request->reward_config,
            'balance_formula' => $request->balance_formula,
            'balance_config' => $request->balance_config,
            'flow_formula' => $request->flow_formula,
            'flow_config' => $request->flow_config,
            'withdraw_formula' => $request->withdraw_formula,
            'withdraw_config' => $request->withdraw_config,
            'bonus_vendor_id' => $request->bonus_vendor_id,
            'ip_block' => $request->ip_block ? implode(',', $request->ip_block) : null,
            'phone_block' => $request->phone_block ? implode(',', $request->phone_block) : null,
            'account_block' => $request->account_block ? implode(',', $request->account_block) : null,
            'join_withdraw' => $withdrawType->isScore() ? 1 : $request->join_withdraw,
            'cost_type' => $request->cost_type,
            'end_time' => Carbon::parseFrontend($request->end_time)->toSystemTime()->toDateTimeString(),
            'is_recycle' => $request->is_recycle,
            'status' => $request->status,
        ];

        // 只有真的送了 withdraw_type 才寫；舊後台整包送但沒這個欄位，不得把積分活動翻回主錢包
        // 與 ValidatesWithdrawType::effectiveWithdrawType() 同一把尺：null／'' 視為沒送、不動原值
        if ($request->filled('withdraw_type')) {
            $updateData['withdraw_type'] = $withdrawType->value;
        }
        // 兩個週期欄位同理：舊後台不送，不得把紅包的週期翻回 0
        foreach (EventV2PeriodEnum::FIELDS as $field) {
            if ($request->filled($field)) {
                $updateData[$field] = $request->effectivePeriod($field)->value;
            }
        }

        // 活動開始前可修改時間與代理資訊
        if (now() < $detail->start_time) {
            $updateData['event_v2_id'] = $request->event_v2_id;
            $updateData['agent_id'] = $request->agent_id;
            $updateData['start_time'] = Carbon::parseFrontend($request->start_time)->toSystemTime()->toDateTimeString();
        }

        return $updateData;
    }

    /**
     * 更新活動語系資料
     */
    private function updateDetailLangs(EventV2Detail $detail, UpdateRequest $request): void
    {
        if (!$request->detail_lang) {
            return;
        }

        foreach ($request->detail_lang as $value) {
            EventV2DetailLang::updateOrCreate([
                'event_v2_detail_id' => $detail->id,
                'language_id' => $value['language_id'],
            ], [
                'name' => $value['name'] ?? '',
                'image' => $value['image'],
                'reward_name' => $value['reward_name'],
                'content' => $value['content'],
            ]);
        }
    }

    /**
     * 更新排除代理資料
     */
    private function updateExcludeAgents(EventV2Detail $detail, UpdateRequest $request): void
    {
        EventV2DetailExcludeAgent::where('event_v2_detail_id', $detail->id)->delete();
        if (!$request->exclude_agent) {
            return;
        }

        foreach ($request->exclude_agent as $value) {
            EventV2DetailExcludeAgent::insert([
                'event_v2_detail_id' => $detail->id,
                'agent_id' => $value,
            ]);
        }
    }

    /**
     * 更新遊戲分類限制資料
     */
    private function updateGameTypes(EventV2Detail $detail, UpdateRequest $request): void
    {
        EventV2DetailGameType::where('event_v2_detail_id', $detail->id)->delete();
        if (!$request->restrict_game_type) {
            return;
        }

        foreach ($request->restrict_game_type as $value) {
            EventV2DetailGameType::insert([
                'event_v2_detail_id' => $detail->id,
                'game_type_id' => $value,
            ]);
        }
    }

    /**
     * 更新遊戲廠商限制資料
     */
    private function updateVendors(EventV2Detail $detail, UpdateRequest $request): void
    {
        EventV2DetailVendor::where('event_v2_detail_id', $detail->id)->delete();
        if (!$request->restrict_vendor) {
            return;
        }

        foreach ($request->restrict_vendor as $value) {
            EventV2DetailVendor::insert([
                'event_v2_detail_id' => $detail->id,
                'vendor_id' => $value,
            ]);
        }
    }

    /**
     * 更新排除遊戲廠商資料
     */
    private function updateExcludeVendors(EventV2Detail $detail, UpdateRequest $request): void
    {
        EventV2DetailExcludeVendor::where('event_v2_detail_id', $detail->id)->delete();
        if (!$request->exclude_vendor_data) {
            return;
        }

        foreach ($request->exclude_vendor_data as $value) {
            EventV2DetailExcludeVendor::insert([
                'event_v2_detail_id' => $detail->id,
                'vendor_id' => $value,
            ]);
        }
    }

    public function updateInfo(UpdateInfoRequest $request)
    {
        $detail = EventV2Detail::find($request->event_detail_id);

        // 範疇比照 list()：不在可視範圍內的活動一律視同不存在，避免跨線改別人的活動名稱／圖文／狀態（4995）
        if (!$detail || !$this->getAgentContext()->agent->canSeeAgent($detail->agent_id)) {
            return ApiResponse::error('活動不存在');
        }

        return DB::transaction(function () use ($request, $detail) {
            $updateData = [
                'sort' => $request->sort ?? 0,
            ];

            if ($request->status !== null) {
                $updateData['status'] = $request->status;
            }

            $detail->update($updateData);

            if ($request->detail_lang) {
                foreach ($request->detail_lang as $value) {
                    EventV2DetailLang::updateOrCreate([
                        'event_v2_detail_id' => $detail->id,
                        'language_id' => $value['language_id'],
                    ], [
                        'name' => $value['name'],
                        'image' => $value['image'],
                        'content' => $value['content'],
                    ]);
                }
            }

            return ApiResponse::success();
        });
    }

    public function walletManagement(WalletManagementRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $languageId = $this->getAgentContext()->languageId;

        $data = EventV2UserJoin::select(
            [
                'event_v2_user_joins.id AS join_id',
                'event_v2_user_joins.event_v2_detail_id',
                'event_v2_details.created_at',
                'members.username AS member_username',
                'agents.username AS agent_username',
                'member_wallets.id AS wallet_id',
                'member_wallets.balance AS balance',
            ]
        )
            ->leftJoin('event_v2_details', 'event_v2_details.id', '=', 'event_v2_user_joins.event_v2_detail_id')
            ->leftJoin('event_v2', 'event_v2.id', '=', 'event_v2_details.event_v2_id')
            ->leftJoin('agents', 'agents.id', '=', 'event_v2_details.agent_id')
            ->leftJoin('members', 'members.id', '=', 'event_v2_user_joins.member_id')
            ->leftJoin('member_wallets', function ($join) {
                $join->on('event_v2_user_joins.member_id', '=', 'member_wallets.member_id')
                    ->where('member_wallets.wallet_type_id', WalletEnum::CODE_EVENT_WALLET);
            })
            ->with('eventV2Detail.detailLang')
            // admin跟admin子帳號可以看全部
            ->when($agent->id != 1 && $agent->host_id != 1, function ($query) use ($agent) {
                $hierarchy_path = $agent->hierarchy_path;
                // 代理子帳號可以看到代理的活動
                if ($agent->host_id != 0) {
                    $upperAgent = Agent::where('id', $agent->host_id)->first();
                    $hierarchy_path = $upperAgent->hierarchy_path;
                }

                return $query->where('agents.hierarchy_path', 'like', $hierarchy_path . '%');
            })
            ->when($request->event_v2_id, function ($query) use ($request) {
                return $query->where('event_v2.id', $request->event_v2_id);
            })
            ->when($request->member_username, function ($query) use ($request) {
                return $query->where('members.username', $request->member_username);
            })
            ->when($request->agent_username, function ($query) use ($request) {
                return $query->where('agents.username', $request->agent_username);
            })
            ->where('event_v2_user_joins.status', EventV2UserJoinStatusEnum::JOIN->value)
            ->orderBy('event_v2_user_joins.created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        foreach ($data as $key => $value) {
            $eventDetail = $value->eventV2Detail;
            // 若語系沒設定,預設抓英文
            $detailLang = $eventDetail->detailLang->where('language_id', $languageId)->isNotEmpty() ?
                $eventDetail->detailLang->where('language_id', $languageId)->first() :
                $eventDetail->detailLang->where('language_id', self::DEFAULT_LANGUAGE_id)->first();

            $data[$key]->name = $detailLang->name ?? '';
        }

        return ApiResponse::successWithPagination(
            data: EventV2WalletManagementResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    public function updateWalletBalance(UpdateWalletBalanceRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        return DB::transaction(function () use ($request, $agent) {
            // 外部 float 入境即正規化，避免直接 (string) 帶著精度誤差進金額運算
            $adjustment = Common::numAdd('0', (string) $request->balance, 4);

            // 僅限活動錢包：member_wallets 同表存放主錢包／禮碼錢包，未限型別時
            // 傳入其他 wallet_id 就會把可提現的真錢加分（本端點只該碰活動錢包）
            $memberWallet = MemberWallet::where('id', $request->wallet_id)
                ->where('wallet_type_id', WalletEnum::CODE_EVENT_WALLET)
                ->lockForUpdate()
                ->first();

            $member = $memberWallet?->member;

            if ($member === null) {
                return ApiResponse::error('活動錢包不存在', 501);
            }

            $userJoin = EventV2UserJoin::where('member_id', $member->id)->where('status', EventV2UserJoinStatusEnum::JOIN->value)->first();

            if ($userJoin === null) {
                return ApiResponse::error(__('event_v2.validation.no_join_2'), 501); // 您尚未參加任何活動
            }

            // 範疇比照讀取端 walletManagement()：以活動所屬代理判定，列表看得到的才能調整
            if (!$agent->canSeeAgent($userJoin->eventV2Detail?->agent_id)) {
                throw new ApiException(__('error_message.9003')); // 權限不足
            }

            $balance = Common::numAdd($adjustment, $memberWallet->balance, 4);

            // 下限 0；字串金額用 > 比較會退化成 PHP 型別轉換，一律走 numCompare
            if (Common::numCompare($balance, '0', 4) < 0) {
                $balance = '0';
            }

            $this->eventV2Service->insertWalletTransactionLog($member, $userJoin, [
                'type' => WalletTransactionEnum::EVENT_V2_OPERATOR_CHANGE->value,
                'point' => $adjustment,
                'before_balance' => $memberWallet->balance,
                'balance' => $balance,
            ], [
                'operate_agent_id' => $agent->id,
            ]);

            $memberWallet->update([
                'balance' => $balance,
            ]);

            return ApiResponse::success();
        });
    }

    public function quit(QuitRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        return DB::transaction(function () use ($request, $agent) {

            // 重置活動
            $userJoin = EventV2UserJoin::where('id', $request->join_id)->where('status', EventV2UserJoinStatusEnum::JOIN->value)->first();
            if ($userJoin === null) {
                return ApiResponse::error(__('event_v2.validation.no_join_2'), 501); // 您尚未參加任何活動
            }

            // 範疇比照讀取端 joinList()：以活動所屬代理判定，列表看得到的才能重置
            if (!$agent->canSeeAgent($userJoin->eventV2Detail?->agent_id)) {
                throw new ApiException(__('error_message.9003')); // 權限不足
            }

            $userJoin->withdraw = 0;
            $userJoin->status = EventV2UserJoinStatusEnum::OPERATOR_QUIT->value;

            $this->eventV2Service->initEventV2($userJoin->member, $userJoin);

            return ApiResponse::success();
        });
    }
}
