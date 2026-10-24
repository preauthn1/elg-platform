<?php

namespace App\Http\Controllers;

use App\Enums\AgentCustomizedSettingKeyEnum;
use App\Helpers\Common;
use App\Helpers\SQLHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Models\Order;
use App\Models\TransactionLog;
use App\Models\Agent;
use App\Models\Member;
use App\Models\SummaryBetRecord;
use App\Models\AgentCustomizedSetting;
use App\Services\UnlimitedService;
use App\Services\ReportService;
use App\Repositories\AgentCustomizedSettingRepository;

use App\REDIS;
use App\AgentChild;
use Validator;
use DB;
use Log;

class NewReportController extends Controller
{
    protected $unlimitedService;
    protected $reportService;
    protected $agentCustomizedSettingRepository;

    /**
     * ReportController constructor.
     */
    public function __construct()
    {
        $this->unlimitedService = new UnlimitedService();
        $this->reportService = new ReportService();
        $this->agentCustomizedSettingRepository = new AgentCustomizedSettingRepository();
    }

    /**
     * newreport/platformReconciliationTotal
     * -sr11 代理商遊戲平台對帳表(上半部統計)
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
     * @bodyParam game_type integer required 遊戲分類 Example: 1
     * @bodyParam start_time date required 開始日期 Example: 2019-01-01 00
     * @bodyParam end_time date required 結束日期 Example: 2019-12-31 01
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "agent_id": "代理商編號,ex:1",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "agent_nickname": "代理商暱稱,ex:abc",
     *            "vendor": "平台名稱,ex:ag",
     *            "vendor_id": "平台系統編號,ex:1",
     *            "game_type": "遊戲類別,ex:slot",
     *            "game_type_id": "遊戲類別系統編號,ex:1",
     *            "total_bet": "總押注,ex:100",
     *            "win_lose": "總輸贏,ex:200",
     *            "commission": "佣金,ex:1",
     *            "lottery": "中彩金,ex:100",
     *            "share_amount": "分成,ex:1"
     *        }
     *    ]
     * }
     */
    public function platformReconciliationTotal(Request $request)
    {
        try {         
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'game_type' => 'digits_between:0,10',
                'start_time' => 'required|date_format:Y-m-d H:i:s',
                'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            if (isset($request->agent_username) && $request->agent_username != '') {
                $agent = Agent::where('username', $request->agent_username)->first();
                if (is_object($agent)) {
                    //子帳號權限調整 $request->ID => $request->HOST
                    //只能查詢自己或自己下線的
                    if (!$this->isChild($request->HOST, $agent->id) &&  $agent->id != $request->ID) {
                        //不是下線
                        return response()->json([
                            'code' => 1050,
                            'message' => __('error_message.1050'),
                        ]);
                    }
                } else {
                    //代理商不存在
                    return response()->json([
                        'code' => 1050,
                        'message' => __('error_message.1050'),
                    ]);
                }
            } else {
                //查登入的這個agent
                $agent = Agent::where('id', $request->HOST)->first();
            }

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour -1second'));

            //取得各站個性化翻譯
            $translates = app('App\Http\Controllers\LanguageController')->getTranslate($request);

            // 代理 + 會員
            $model = $this->platformReconciliation($request, $agent);
            // 活動獎勵
            if (strpos($request->vendor_id, '-1') !== false) {
                $reward_text = (is_array($translates))
                    ? $translates[AgentCustomizedSettingKeyEnum::LABEL_TEXT_REWARD]
                    : 'Reward';
                $event_model = $this->platformReconciliationEventTotal($request, $agent, $reward_text);
                $model = $model->union($event_model);
            }
            // 無限代獎勵
            if (strpos($request->vendor_id, '-2') !== false) {
                $promotion = (is_array($translates)) ? $translates['3003'] : 'V-Reward';

                $agent_unlimited_model = $this->unlimitedService->newPlatformReconciliationTotal($request, $agent, true, $promotion);
                $model = $model->union($agent_unlimited_model);
                $member_unlimited_model = $this->unlimitedService->newPlatformReconciliationTotal($request, $agent, false, $promotion);
                $model = $model->union($member_unlimited_model);
            }
            //支付手續費&簡訊費
            if (strpos($request->vendor_id, '-3') !== false || strpos($request->vendor_id, '-4') !== false) {
                $fee_model = $this->platformReconciliationFeeTotal($request, $agent, $start_time, $end_time);
                $model = $model->union($fee_model);
            }
            $model->orderBy('agent_id', 'asc')
                ->orderBy('sort', 'asc')
                ->orderBy('game_type_id', 'asc')
                ->orderBy('type', 'asc');
            //Log::info("get vendor [" . Common::debugSQL($model) . "]");
            $data = $model->get();

            //6004 vjackpot比例
            $query_setting_vjackpot = $this->agentCustomizedSettingRepository->getSettingByGroupID($agent->customized_setting_group_id , 6004);            

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'show_v_jackpot' => (($query_setting_vjackpot->setting_value > 0) ? true : false)
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * newreport/platformReconciliationDetail
     * -sr11 代理商遊戲平台對帳表(代理)
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
     * @bodyParam game_type integer required 遊戲分類 Example: 1
     * @bodyParam start_time date required 開始日期 Example: 2019-01-01 00
     * @bodyParam end_time date required 結束日期 Example: 2019-12-31 01
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "agent_id": "代理商編號,ex:1",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "agent_nickname": "代理商暱稱,ex:abc",
     *            "vendor": "平台名稱,ex:ag",
     *            "vendor_id": "平台系統編號,ex:1",
     *            "game_type": "遊戲類別,ex:slot",
     *            "game_type_id": "遊戲類別系統編號,ex:1",
     *            "total_bet": "總押注,ex:100",
     *            "win_lose": "總輸贏,ex:200",
     *            "commission": "佣金,ex:1",
     *            "lottery": "中彩金,ex:100",
     *            "share_amount": "分成,ex:1"
     *        }
     *    ]
     * }
     */
    public function platformReconciliationDetail(Request $request)
    {
        ini_set('max_execution_time', 60);
        try {
            DB::enableQueryLog();
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'game_type' => 'digits_between:0,10',
                'start_time' => 'required|date_format:Y-m-d H:i:s',
                'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            if (isset($request->agent_username) && $request->agent_username != '') {
                $agent = Agent::where('username', $request->agent_username)->first();
                if (is_object($agent)) {
                    //子帳號權限調整 $request->ID => $request->HOST
                    //只能查詢自己或自己下線的
                    if (!$this->isChild($request->HOST, $agent->id) &&  $agent->id != $request->ID) {
                        //不是下線
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                        ]);
                    }
                } else {
                    //代理商不存在
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                }
            } else {
                //查登入的這個agent
                $agent = Agent::where('id', $request->HOST)->first();
            }

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour -1second'));

            $temp_query = DB::table('agents as ta')
                ->whereRaw("hierarchy_path LIKE '" . $agent->hierarchy_path . "%'")
                ->whereRaw('id != '. $agent->id)
                ->select(
                    DB::raw('id AS child'),
                    DB::raw("SUBSTRING_INDEX(SUBSTR(hierarchy, 1, LOCATE('," . $agent->id . ",', hierarchy) - 1), ',', -1) AS agent_id"),
                    'ta.vendor_customize_group_id'
                );

            //取得各站個性化翻譯
            $translates = app('App\Http\Controllers\LanguageController')->getTranslate($request);

            // 代理
            $model = $this->platformReconciliationDetailModel($request, $temp_query);
            $total = $model->getCountForPagination();

            // 獎勵
            if (strpos($request->vendor_id, '-1') !== false) {
                $reward_text = (is_array($translates))
                    ? $translates[AgentCustomizedSettingKeyEnum::LABEL_TEXT_REWARD]
                    : 'Reward';
                $event_model = $this->platformReconciliationEventModel($request, $temp_query, $reward_text);
                $total += $event_model->getCountForPagination();
                $model = $model->union($event_model);
            }
            // 無限代獎勵
            if (strpos($request->vendor_id, '-2') !== false) {
                $promotion = (is_array($translates)) ? $translates['3003'] : 'V-Reward';

                $agent_unlimited_model = $this->unlimitedService->newPlatformReconciliationModel($request, true, $temp_query, $promotion);
                $total += $agent_unlimited_model->getCountForPagination();
                $model = $model->union($agent_unlimited_model);
            }
            //支付手續費&簡訊費
            if (strpos($request->vendor_id, '-3') !== false || strpos($request->vendor_id, '-4') !== false) {
                $fee_model = $this->platformReconciliationFeeModel($request, $temp_query, $start_time, $end_time);
                $total += $fee_model->getCountForPagination();
                $model = $model->union($fee_model);
            }

            $summary = DB::table(DB::raw('(' . $model->toSql(). ') as z'))
                ->mergeBindings($model)
                ->select(
                    DB::raw('SUM(z.total_bet) AS total_bet'),
                    DB::raw('SUM(z.win_lose) AS total_win_lose'),
                    DB::raw('SUM(z.cost) AS total_cost'),
                    DB::raw('SUM(z.commission) AS total_commission'),
                    DB::raw('SUM(z.lottery) AS total_lottery'),
                    DB::raw('SUM(z.v_jackpot) AS total_v_jackpot')
                )
                ->first();
            
            $data = $model
                ->orderBy('agent_id', 'asc')
                ->orderBy('sort', 'asc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();
            $queries = DB::getQueryLog();
            $debugInfo = ['queries'=>$queries, 'user_id'=>$request->ID, 'params'=>$request->all(), 'data'=>$data];
            Common::log($request->ID, $debugInfo, 'newPlatformReconciliationDetail');

            $show_vjackpot = AgentCustomizedSetting::where('agent_group_id', $agent->customized_setting_group_id)
                ->where('setting_key', 6004)
                ->select('value as v_jackpot_rate')
                ->first();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'summary' => $summary,
                'show_v_jackpot' => (($show_vjackpot->v_jackpot_rate > 0) ? true : false),
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * newreport/platformReconciliationMember
     * -sr11 代理商遊戲平台對帳表(下半部明細-會員)
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
     * @bodyParam game_type integer required 遊戲分類 Example: 1
     * @bodyParam start_time date required 開始日期 Example: 2019-01-01 00
     * @bodyParam end_time date required 結束日期 Example: 2019-12-31 01
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "agent_id": "代理商編號,ex:1",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "agent_nickname": "代理商暱稱,ex:abc",
     *            "vendor": "平台名稱,ex:ag",
     *            "vendor_id": "平台系統編號,ex:1",
     *            "game_type": "遊戲類別,ex:slot",
     *            "game_type_id": "遊戲類別系統編號,ex:1",
     *            "total_bet": "總押注,ex:100",
     *            "win_lose": "總輸贏,ex:200",
     *            "commission": "佣金,ex:1",
     *            "lottery": "中彩金,ex:100",
     *            "share_amount": "分成,ex:1"
     *        }
     *    ]
     * }
     */
    public function platformReconciliationMember(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'game_type' => 'digits_between:0,10',
                'start_time' => 'required|date_format:Y-m-d H:i:s',
                'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            if (isset($request->agent_username) && $request->agent_username != '') {
                $agent = Agent::where('username', $request->agent_username)->first();
                if (is_object($agent)) {
                    //子帳號權限調整 $request->ID => $request->HOST
                    //只能查詢自己或自己下線的
                    if (!$this->isChild($request->HOST, $agent->id) &&  $agent->id != $request->ID) {
                        //不是下線
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                        ]);
                    }
                } else {
                    //代理商不存在
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                }
            } else if (isset($request->member_username) && $request->member_username != '') {
                $agent = DB::table('agents as a')
                    ->join('members as m', function ($join) use ($request) {
                    $join->on('m.parent', 'a.id')
                        ->where('m.username', $request->member_username);
                    })
                    ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%')
                    ->select(
                        'a.id',
                        'a.username',
                        'a.nickname',
                        'a.customized_setting_group_id'
                    )
                    ->first();
            } else {
                //查登入的這個agent
                $agent = Agent::where('id', $request->HOST)->first();
            }

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour -1second'));

            //取得各站個性化翻譯
            $translates = app('App\Http\Controllers\LanguageController')->getTranslate($request);

            $model = $this->platformReconciliationMemberModel($request, $agent);

            //活動獎勵 V-World
            if (strpos($request->vendor_id, '-1') !== false) {
                $reward_text = (is_array($translates))
                    ? $translates[AgentCustomizedSettingKeyEnum::LABEL_TEXT_REWARD]
                    : 'Reward';
                $event_model = $this->platformReconciliationEventMember($request, $agent, $reward_text);
                $model = $model->union($event_model);
            }
            // 無限代獎勵 V-Reward
            if (strpos($request->vendor_id, '-2') !== false) {
                $promotion = (is_array($translates)) ? $translates['3003'] : 'V-Reward';

                $unlimited_model = $this->unlimitedService->newPlatformReconciliationMember($request, $agent, $promotion);
                $model = $model->union($unlimited_model);
            }
            //支付手續費&簡訊費
            if (strpos($request->vendor_id, '-3') !== false || strpos($request->vendor_id, '-4') !== false) {
                $fee_model = $this->platformReconciliationFeeMember($request, $agent, $start_time, $end_time);
                $model = $model->union($fee_model);
            }
            $data = $model->orderBy('member_username')
                ->orderBy('sort')
                ->get();

            $show_vjackpot = AgentCustomizedSetting::where('agent_group_id', $agent->customized_setting_group_id)
                ->where('setting_key', 6004)
                ->select('value as v_jackpot_rate')
                ->first();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'show_v_jackpot' => (($show_vjackpot->v_jackpot_rate > 0) ? true : false)
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function platformReconciliationMemberModel($request, $agent)
    {
        //以agent為主
        $data = DB::table('summary_bet_records as s')
            ->join('members as m', function ($join) use ($request) {
                $join->on('m.id', 's.member_id');
                if (isset($request->member_username) && $request->member_username != '') {
                    $join->where('m.username', $request->member_username);
                }
            })
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.id', $agent->id);
            })
            ->join('vendors as v', function ($join) use ($request) {
                $join->on('v.id', 's.vendor_id');
                if (isset($request->vendor_id) && $request->vendor_id  != '') {
                    $join->whereIn('v.id', explode(',', $request->vendor_id));
                }
            })
            ->leftJoin('vendor_customized_items as vi', function ($join) {
                $join->on('vi.agent_group_id', 'a.vendor_customize_group_id')
                    ->on('vi.vendor_id', 'v.id')
                    ->on('vi.item_type', DB::raw(1));
            })
            ->leftJoin('games as g', 'g.id', 's.game_id')
            ->leftJoin('game_types as t', 't.id', 'g.type_id')
            ->where('s.summary_time', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')))
            ->where('s.summary_time', '<', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour 1minute')));

        if (isset($request->game_type) && $request->game_type > 0) {
            $data = $data->where('t.id', $request->game_type);
        }
        // $data = $data->where('s.vendor_id', '!=', 4); //豪龍沒有會員明細，所以先隱藏
        $model = $data->groupBy('m.id')
            ->groupBy('v.id')
            ->groupBy('t.id')
            ->select(
                'm.username as member_username',
                'm.nickname as member_nickname',
                DB::raw('IFNULL(vi.name, v.name) as vendor'),
                'v.id as vendor_id',
                't.name as game_type',
                't.id as game_type_id',
                DB::raw('SUM(CASE WHEN v.id = 36 THEN 0 ELSE s.bet END) as total_bet'),
                DB::raw('SUM(CASE WHEN v.id = 36 THEN 0 ELSE s.win_lose END) as win_lose'),
                DB::raw('SUM(s.cost) as cost'),
                DB::raw('SUM(s.commission) as commission'),
                DB::raw('SUM(s.jackpot) as lottery'),
                DB::raw('SUM(s.share_amount) as share_amount'),
                DB::raw('SUM(s.v_jackpot) as v_jackpot'),
                DB::raw('(s.vendor_id + 1000) AS sort')
            );
        return $model;
    }

    private function platformReconciliationEventMember($request, $agent, $reward_text)
    {
        //以agent為主
        $data = DB::table('transaction_logs as s')
            ->join('members as m', function ($join) use ($request) {
                $join->on('m.id', 's.member_id');
                if (isset($request->member_username) && $request->member_username != '') {
                    $join->where('m.username', $request->member_username);
                }
            })
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.id', $agent->id);
                //->where('a.hierarchy', 'like', '%,' . $agent->id . ',%')->first();
            })
            ->where('s.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')))
            ->where('s.created_at', '<', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour 1minute')))
            // 13:活動獎勵, 15:登入活動, 19:首充活動返利, 20:註冊送, 21:綁定送, 24:充值活動返利, 25:每日充值活動返利, 26:邀請玩家獎勵活動返利, 30:W-jackpot
            ->whereIn('s.type', [13, 15, 19, 20, 21, 24, 25, 26, 30, 101]);

        $model = $data->groupBy('m.id')
            ->select(
                'm.username as member_username',
                'm.nickname as member_nickname',
                DB::raw("'" . $reward_text . "' as vendor"),
                DB::raw("'-1' as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw('0 as game_type_id'),
                DB::raw('0 as total_bet'),
                DB::raw('0 as win_lose'),
                DB::raw('0 as cost'),
                DB::raw('0 as commission'),
                DB::raw('SUM(TRUNCATE(s.point, 4)) as lottery'),
                DB::raw('0 as share_amount'),
                DB::raw('0 as v_jackpot'),
                DB::raw('1 as sort')
            );
        return $model;
    }

    private function platformReconciliationFeeMember($request, $agent, $start_time, $end_time)
    {
        $ar_fee = [];
        if (strpos($request->vendor_id, '-3') !== false) {
            $ar_fee[] = 3;
        }
        if (strpos($request->vendor_id, '-4') !== false) {
            $ar_fee[] = 4;
        }
        //以agent為主
        $data = DB::table('summary_event_records as s')
            ->join('members as m', function ($join) use ($request) {
                $join->on('m.id', 's.member_id');
                if (isset($request->member_username) && $request->member_username != '') {
                    $join->where('m.username', $request->member_username);
                }
            })
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.id', $agent->id);
            })
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<', $end_time)
            ->whereIn('s.event_id', $ar_fee);

        $model = $data->groupBy('m.id', 's.event_id')
            ->select(
                'm.username as member_username',
                'm.nickname as member_nickname',
                DB::raw("CASE s.event_id WHEN 3 THEN 'payfee' WHEN 4 THEN 'smsfee' END AS vendor"),
                DB::raw('(s.event_id * -1) as vendor_id'),
                DB::raw("'-' as game_type"),
                DB::raw('0 as game_type_id'),
                DB::raw('0 as total_bet'),
                DB::raw('0 as win_lose'),
                DB::raw('SUM(s.point) as cost'),
                DB::raw('0 as commission'),
                DB::raw('0 as lottery'),
                DB::raw('0 as share_amount'),
                DB::raw('0 as v_jackpot'),
                's.event_id as sort'
            );
        return $model;
    }

    private function platformReconciliationLotteryMember($request, $agent)
    {
        //以agent為主
        $data = DB::table('summary_bet_records as s')
            ->join('members as m', function ($join) use ($request) {
                $join->on('m.id', 's.member_id');
                if (isset($request->member_username) && $request->member_username != '') {
                    $join->where('m.username', $request->member_username);
                }
            })
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.id', $agent->id);
            })
            ->leftJoin('games as g', 'g.id', 's.game_id')
            ->leftJoin('game_types as t', 't.id', 'g.type_id')
            ->where('s.summary_time', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')))
            ->where('s.summary_time', '<', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour 1minute')));

        if (isset($request->game_type) && $request->game_type > 0) {
            $data = $data->where('t.id', $request->game_type);
        }
        $model = $data->groupBy('m.id')
            ->groupBy('t.id')
            ->select(
                'm.username as member_username',
                'm.nickname as member_nickname',
                DB::raw("'V-Lottery' as vendor"),
                DB::raw("'-3' as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw("0 as game_type_id"),
                DB::raw("0 as total_bet"),
                DB::raw("0 as win_lose"),
                DB::raw("0 as commission"),
                DB::raw("TRUNCATE(SUM(s.bet) * " . env('V_LOTTERY_RATE', 0.003) . ", 2) as lottery"),
                DB::raw("0 as share_amount")
            );
        return $model;
    }

    private function platformReconciliation($request, $agent)
    {
        $main = DB::table('summary_bet_records as s')
            ->join('members as m', 'm.id', 's.member_id')
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.hierarchy', 'LIKE', '%,' . $agent->id . ',%');
            })
            ->join('vendors as v', function ($join) use ($request) {
                $join->on('v.id', 's.vendor_id');
                if (isset($request->vendor_id) && $request->vendor_id  != '') {
                    $join->whereIn('s.vendor_id', explode(',', $request->vendor_id));
                }
            })
            ->leftJoin('games as g', 'g.id', 's.game_id')
            ->where('s.summary_time', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')))
            ->where('s.summary_time', '<', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour -1second')))
            ->groupBy('s.vendor_id')
            ->groupBy('g.type_id')
            ->groupBy('type')
            ->select(
                DB::raw("(CASE WHEN m.parent = " . $agent->id . " THEN 'member' ELSE 'agent' END) as type"),
                's.vendor_id',
                'g.type_id',
                DB::raw('SUM(CASE WHEN v.id = 36 THEN 0 ELSE s.bet END) as total_bet'),
                DB::raw('SUM(CASE WHEN v.id = 36 THEN 0 ELSE s.win_lose END) as win_lose'),
                DB::raw('SUM(s.cost) as cost'),
                DB::raw('SUM(s.commission) as commission'),
                DB::raw('SUM(s.jackpot) as lottery'),
                DB::raw('SUM(s.share_amount) as share_amount'),
                DB::raw('SUM(s.v_jackpot) as v_jackpot'),
                DB::raw('(s.vendor_id + 1000) AS sort'),
                'a.vendor_customize_group_id'
            );

        $model = DB::table(DB::raw("({$main->toSql()}) AS s"))
            ->mergeBindings($main)
            ->leftJoin('game_types as t', 't.id', 's.type_id')
            ->leftJoin('vendors as v', 'v.id', 's.vendor_id')
            ->leftJoin('vendor_customized_items as vi', function ($join) {
                $join->on('vi.agent_group_id', 's.vendor_customize_group_id')
                    ->on('vi.vendor_id', 'v.id')
                    ->on('vi.item_type', DB::raw(1));
            });

        if (isset($request->game_type) && $request->game_type > 0) {
            $model = $model->where('t.id', $request->game_type);
        }
        $model = $model->groupBy('s.vendor_id')
            ->groupBy('s.type')
            ->groupBy('t.id')
            ->select(
                's.type',
                DB::raw($agent->id . ' as agent_id'),
                DB::raw("'" . $agent->username . "' as agent_username"),
                DB::raw("'" . $agent->nickname . "' as agent_nickname"),
                DB::raw('IFNULL(vi.name, v.name) as vendor'),
                DB::raw('v.id as vendor_id'),
                DB::raw('t.name as game_type'),
                DB::raw('t.id as game_type_id'),
                's.total_bet',
                's.win_lose',
                's.cost',
                's.commission',
                's.lottery',
                's.share_amount',
                's.v_jackpot',
                's.sort'
            );
        //Log::info("-------------get vendor [" . Common::debugSQL($model) . "]");
        return $model;
    }

    private function platformReconciliationLottery($request, $agent)
    {
        $model = DB::table('summary_bet_records as s')
            ->join('members as m', 'm.id', 's.member_id')
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.hierarchy', 'LIKE', '%,' . $agent->id . ',%');
            })
            ->leftJoin('games as g', 'g.id', 's.game_id')
            ->leftJoin('game_types as t', 't.id', 'g.type_id')
            ->where('s.summary_time', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')))
            ->where('s.summary_time', '<', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour 1minute')));
        if (isset($request->game_type) && $request->game_type > 0) {
            $model = $model->where('t.id', $request->game_type);
        }
        $model = $model->groupBy('type')
            ->select(
                DB::raw("(CASE WHEN m.parent = " . $agent->id . " THEN 'member' ELSE 'agent' END) as type"),
                DB::raw($agent->id ." as agent_id"),
                DB::raw("'" . $agent->username . "' as agent_username"),
                DB::raw("'" . $agent->nickname . "' as agent_nickname"),
                DB::raw("'V-Lottery' as vendor"),
                DB::raw("-3 as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw("0 as game_type_id"),
                DB::raw('0 as total_bet'),
                DB::raw('0 as win_lose'),
                DB::raw('0 as commission'),
                DB::raw("TRUNCATE(SUM(s.bet) * " . env('V_LOTTERY_RATE', 0.003) . ", 2) as lottery"),
                DB::raw("0 as share_amount")
            );
        return $model;
    }

    private function platformReconciliationEventTotal($request, $agent, $reward_text)
    {
        $main = DB::table('transaction_logs as s')
            ->join('members as m', 'm.id', 's.member_id')
            ->join('agents as a', function($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.hierarchy_path', 'like', $agent->hierarchy_path . '%');
            })
            ->where('s.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')))
            ->where('s.created_at', '<', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour 1minute')))
            // 13:活動獎勵, 15:登入活動, 19:首充活動返利, 20:註冊送, 21:綁定送, 24:充值活動返利, 30:W-jackpot
            ->whereIn('s.type', [13, 15, 19, 20, 21, 24, 25, 26, 30, 101])
            ->groupBy('m.id')
            ->select(
                DB::raw("(CASE WHEN m.parent = " . $agent->id . " THEN 2 ELSE 1 END) as type"),
                DB::raw("SUM(s.point) as point")
            );

        $model = DB::table(DB::raw("({$main->toSql()}) as z"))
            ->mergeBindings($main)
            ->groupBy('z.type')
            ->orderBy('z.type')
            ->select(
                DB::raw("(CASE WHEN z.type = 2 THEN 'member' ELSE 'agent' END) as type"),
                DB::raw($agent->id ." as agent_id"),
                DB::raw("'" . $agent->username . "' as agent_username"),
                DB::raw("'" . $agent->nickname . "' as agent_nickname"),
                DB::raw("'" . $reward_text . "' as vendor"),
                DB::raw("-1 as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw("0 as game_type_id"),
                DB::raw("0 as total_bet"),
                DB::raw("0 as win_lose"),
                DB::raw("0 as cost"),
                DB::raw("0 as commission"),
                DB::raw("SUM(TRUNCATE(z.point, 4)) as lottery"),
                DB::raw("0 as share_amount"),
                DB::raw("0 as v_jackpot"),
                DB::raw('1 AS sort')
            );
        return $model;
    }

    private function platformReconciliationFeeTotal($request, $agent, $start_time, $end_time)
    {
        $ar_fee = [];
        if (strpos($request->vendor_id, '-3') !== false) {
            $ar_fee[] = 3;
        }
        if (strpos($request->vendor_id, '-4') !== false) {
            $ar_fee[] = 4;
        }

        $main = DB::table('summary_event_record_agents as s')
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 's.agent_id')
                    ->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
            })
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<=', $end_time)
            ->whereIn('s.event_id', $ar_fee)
            ->groupBy('s.agent_id', 's.event_id')
            ->select(
                DB::raw("(CASE WHEN s.agent_id = " . $agent->id . " THEN 2 ELSE 1 END) as type"),
                DB::raw('SUM(s.point) as point'),
                DB::raw('(s.event_id * -1) as vendor_id'),
                's.event_id as sort'
            );

        $model = DB::table(DB::raw('(' . $main->toSql(). ') as z'))
            ->mergeBindings($main)
            ->groupBy('z.type', 'z.vendor_id')
            ->orderBy('z.type', 'asc')
            ->select(
                DB::raw("(CASE WHEN z.type = 2 THEN 'member' ELSE 'agent' END) as type"),
                DB::raw($agent->id ." as agent_id"),
                DB::raw("'" . $agent->username . "' as agent_username"),
                DB::raw("'" . $agent->nickname . "' as agent_nickname"),
                DB::raw("CASE z.vendor_id WHEN -3 THEN 'payfee' WHEN -4 THEN 'smsfee' END AS vendor"),
                'z.vendor_id',
                DB::raw("'-' as game_type"),
                DB::raw("0 as game_type_id"),
                DB::raw("0 as total_bet"),
                DB::raw("0 as win_lose"),
                DB::raw("SUM(z.point) as cost"),
                DB::raw("0 as commission"),
                DB::raw("0 as lottery"),
                DB::raw("0 as share_amount"),
                DB::raw("0 as v_jackpot"),
                'z.sort'
            );

        return $model;
    }

    private function platformReconciliationDetailModel($request, $temp)
    {
        $main = DB::table('summary_bet_records as s')
            ->join('members as m', 'm.id', 's.member_id')
            ->join(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 'm.parent')
            ->mergeBindings($temp)
            ->join('vendors as v', function($join) use ($request) {
                $join->on('v.id', 's.vendor_id');
                if (isset($request->vendor_id) && $request->vendor_id  != '') {
                    $join->whereIn('v.id', explode(',', $request->vendor_id));
                }
            })
            ->leftJoin('games as g', 'g.id', 's.game_id')
            ->leftJoin('vendor_customized_items as vi', function ($join) {
                $join->on('vi.agent_group_id', 'temp.vendor_customize_group_id')
                    ->on('vi.vendor_id', 'v.id')
                    ->on('vi.item_type', DB::raw(1));
            })
            ->where('s.summary_time', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')))
            ->where('s.summary_time', '<', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour 1minute')));

        if (isset($request->game_type) && $request->game_type > 0) {
            $main = $main->where('g.type_id', $request->game_type);
        }

        $main = $main->groupBy('m.id')
            ->groupBy('v.id')
            ->groupBy('g.type_id')
            ->select(
                DB::raw("CASE WHEN m.parent = temp.agent_id THEN 2 ELSE 1 END as type"),
                DB::raw('temp.child as child'),
                DB::raw('temp.agent_id as agent_id'),
                DB::raw('IFNULL(vi.name, v.name) as vendor'),
                'v.id as vendor_id',
                'g.type_id as type_id',
                DB::raw('SUM(CASE WHEN v.id = 36 THEN 0 ELSE s.bet END) as bet'),
                DB::raw('SUM(CASE WHEN v.id = 36 THEN 0 ELSE s.win_lose END) as win_lose'),
                DB::raw('SUM(s.cost) as cost'),
                DB::raw('SUM(s.commission) as commission'),
                DB::raw('SUM(s.jackpot) as jackpot'),
                DB::raw('SUM(s.share_amount) as share_amount'),
                DB::raw('SUM(s.v_jackpot) as v_jackpot'),
                DB::raw('(s.vendor_id + 1000) as sort')
            );

        $model = DB::table(DB::raw("({$main->toSql()}) as z"))
            ->mergeBindings($main)
            ->join('agents as a', 'a.id', 'z.agent_id')
            ->leftJoin('game_types as t', 't.id', 'z.type_id');

        $model = $model->groupBy('z.type')
            ->groupBy('z.agent_id')
            ->groupBy('z.vendor_id')
            ->groupBy('t.id')
            ->orderBy('z.agent_id')
            ->orderBy('z.vendor_id')
            ->orderBy('z.type')
            ->select(
                DB::raw("CASE WHEN z.type = 2 THEN 'member' ELSE 'agent' END AS type"),
                'z.agent_id',
                'a.username as agent_username',
                'a.nickname as agent_nickname',
                'z.vendor',
                'z.vendor_id',
                't.name as game_type',
                't.id as game_type_id',
                DB::raw('SUM(z.bet) as total_bet'),
                DB::raw('SUM(z.win_lose) as win_lose'),
                DB::raw('SUM(z.cost) as cost'),
                DB::raw('SUM(z.commission) as commission'),
                DB::raw('SUM(z.jackpot) as lottery'),
                'z.share_amount',
                DB::raw('SUM(z.v_jackpot) as v_jackpot'),
                'z.sort'
            );
        return $model;
    }

    private function platformReconciliationEventModel($request, $temp, $reward_text)
    {
        $main = DB::table('transaction_logs as s')
            ->join('members as m', 'm.id', 's.member_id')
            ->join(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 'm.parent')
            ->mergeBindings($temp)
            // 13:活動獎勵, 15:登入活動, 19:首充活動返利, 20:註冊送, 21:綁定送, 24:充值活動返利, 30:W-jackpot
            ->whereIn('s.type', [13, 15, 19, 20, 21, 24, 25, 26, 30, 101])
            ->where('s.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')))
            ->where('s.created_at', '<', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour 1minute')))
            ->groupBy('m.id')
            ->select(
                DB::raw("CASE WHEN m.parent = temp.agent_id THEN 2 ELSE 1 END AS type"),
                DB::raw("temp.agent_id AS agent_id"),
                DB::raw("temp.child AS child"),
                DB::raw("SUM(s.point) AS point")
            );

        $data = DB::table(DB::raw("({$main->toSql()}) as z"))
            ->mergeBindings($main)
            ->join('agents as a', 'a.id', 'z.agent_id')
            ->groupBy('z.agent_id')
            ->groupBy('z.type')
            ->orderBy('z.agent_id')
            ->orderBy('z.type')
            ->select(
                DB::raw("CASE WHEN z.type = 2 THEN 'member' ELSE 'agent' END AS type"),
                'z.agent_id',
                'a.username as agent_username',
                'a.nickname as agent_nickname',
                DB::raw("'" . $reward_text . "' as vendor"),
                DB::raw("'-1' as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw('0 as game_type_id'),
                DB::raw('0 as total_bet'),
                DB::raw('0 as win_lose'),
                DB::raw('0 as cost'),
                DB::raw('0 as commission'),
                DB::raw('SUM(TRUNCATE(z.point, 4)) as lottery'),
                DB::raw('0 as share_amount'),
                DB::raw('0 as v_jackpot'),
                DB::raw('1 as sort')
            );
        return $data;
    }

    private function platformReconciliationFeeModel($request, $temp, $start_time, $end_time)
    {
        $ar_fee = [];
        if (strpos($request->vendor_id, '-3') !== false) {
            $ar_fee[] = 3;
        }
        if (strpos($request->vendor_id, '-4') !== false) {
            $ar_fee[] = 4;
        }
        $main = DB::table('summary_event_record_agents as s')
            ->join(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 's.agent_id')
            ->mergeBindings($temp)
            ->whereIn('s.event_id', $ar_fee)
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<', $end_time)
            ->groupBy('s.agent_id', 's.event_id')
            ->select(
                DB::raw("CASE WHEN s.agent_id = temp.agent_id THEN 2 ELSE 1 END AS type"),
                DB::raw("temp.agent_id AS agent_id"),
                DB::raw("temp.child AS child"),
                DB::raw("SUM(s.point) AS point"),
                DB::raw('(s.event_id * -1) as vendor_id'),
                's.event_id as sort'
            );

        $model = DB::table(DB::raw("({$main->toSql()}) as z"))
            ->mergeBindings($main)
            ->join('agents as a', 'a.id', 'z.agent_id')
            ->groupBy('z.agent_id', 'z.type', 'z.vendor_id')
            ->orderBy('z.agent_id', 'asc')
            ->orderBy('z.type', 'asc')
            ->orderBy('z.sort', 'asc')
            ->select(
                DB::raw("CASE WHEN z.type = 2 THEN 'member' ELSE 'agent' END AS type"),
                'z.agent_id',
                'a.username as agent_username',
                'a.nickname as agent_nickname',
                DB::raw("CASE z.vendor_id WHEN -3 THEN 'payfee' WHEN -4 THEN 'smsfee' END AS vendor"),
                'z.vendor_id',
                DB::raw("'-' as game_type"),
                DB::raw('0 as game_type_id'),
                DB::raw('0 as total_bet'),
                DB::raw('0 as win_lose'),
                DB::raw('SUM(z.point) as cost'),
                DB::raw('0 as commission'),
                DB::raw('0 as lottery'),
                DB::raw('0 as share_amount'),
                DB::raw('0 as v_jackpot'),
                'z.sort'
            );
        return $model;
    }

    private function platformReconciliationDetailLottery($request, $temp)
    {
        $main = DB::table('summary_bet_records as s')
            ->join('members as m', 'm.id', 's.member_id')
            ->join(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 'm.parent')
            ->mergeBindings($temp)
            ->join('agents as a', 'a.id', 'temp.agent_id')
            ->leftJoin('games as g', 'g.id', 's.game_id')
            ->where('s.summary_time', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')))
            ->where('s.summary_time', '<', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour 1minute')));

        if (isset($request->game_type) && $request->game_type > 0) {
            $main = $main->where('g.type_id', $request->game_type);
        }

        $main = $main->groupBy('m.parent')
            ->select(
                DB::raw("CASE WHEN m.parent = temp.agent_id THEN 'member' ELSE 'agent' END AS type"),
                DB::raw('temp.agent_id as agent_id'),
                DB::raw('temp.child as child'),
                DB::raw('SUM(s.bet) as bet')
            );

        $model = DB::table(DB::raw("({$main->toSql()}) as z"))
            ->mergeBindings($main)
            ->join('agents as a', 'a.id', 'z.child')
            ->groupBy('z.agent_id')
            ->groupBy('z.type')
            ->select(
                'z.type',
                'z.agent_id',
                'a.username as agent_username',
                'a.nickname  as agent_nickname',
                DB::raw("'V-Lottery' as vendor"),
                DB::raw("'-3' as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw("0 as game_type_id"),
                DB::raw("0 as total_bet"),
                DB::raw("0 as win_lose"),
                DB::raw("0 as commission"),
                DB::raw("TRUNCATE(SUM(z.bet) * " . env('V_LOTTERY_RATE', 0.003) . ", 2) as lottery"),
                DB::raw("0 as share_amount")
            );
        return $model;
    }

    private function messages()
    {
        return [

            'agent_id.required' => __('error_message.2017'),
            'agent_id.digits_between' => __('error_message.2018'),

            'member_id.digits_between' => __('error_message.1067'),

            'agent_username.r_alpha_num' => __('error_message.1025'),
            'agent_username.between' => __('error_message.1024'),

            'member_username.r_alpha_num' => __('error_message.1025'),
            'member_username.between' => __('error_message.1024'),

            'member_nickname.between' => __('error_message.2024'),

            'transaction_no.digits_between' => __('error_message.1310'),

            'event_id.required' => __('error_message.1902'),
            'event_id.digits_between' => __('error_message.1903'),

            'source.required' => __('error_message.2035'),
            'source.in' => __('error_message.2035'),

            'start_date.required' => __('error_message.2011'),
            'start_date.date_format' => __('error_message.2013'),

            'end_date.required' => __('error_message.2012'),
            'end_date.date_format' => __('error_message.2014'),
            'end_date.after' => __('error_message.2015'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}