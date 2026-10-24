<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\REDIS;
use App\Http\Controllers\Controller;
use App\Models\UnlimitSystemRate;
use App\Models\UnlimitWithdrawLog;
use App\Models\MemberAgent;
use App\Models\MemberCode;
use App\Models\SummaryBetRecordTeam;
use App\Models\UnlimitBonusReport;
use App\Models\UnlimitSummaryBonus;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\Agent;
use App\Models\AgentDomain;
use App\Enums\AgentCustomizedSettingKeyEnum;
use App\Helpers\Common;
use DB;
use Validator;

/**
 * @group Unlimit
 * - 無限代
 */
class UnlimitController extends Controller
{
    /**
     * unlimit
     * - 玩家詳細信息
     *
     * @bodyParam domain string required 域名 Example: example.invalid
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *    "topUsername": "parent",
     *    "username": "self",
     *    "teamCount": 8,
     *    "memberCount": 7,
     *    "addedToday": 6,
     *    "activeToday": 5,
     *    "code": 'a1b2c3',
     *    "link": "https://example.invalid/Lobby?code=a1b2c3"
     * 	}
     * }
     */
    public function index(Request $request)
    {
        try {
            $startTime = date('Y-m-d H:i:s', strtotime(date('Y-m-d').' 00:00:00-8hour'));

            $member = Member::where('id', $request->ID)->first();

            //查看自己有沒有寫入member_agent
            if (!is_object(MemberAgent::where('member_id', $request->ID)->first())) {
                $memberAgent = array();
                $memberAgent['member_id'] = $request->ID;
                $memberAgent['parent_id'] = $member->parent;
                $memberAgent['agent_mode'] = 1;
                $memberAgent['agent_level'] = 1;
                $memberAgent['agent_relation'] = '0,'.$request->ID.',';
                MemberAgent::create($memberAgent);
            }

            $date = DB::table('member_agent as ma')
                ->where('ma.parent_id', $member->parent)
                ->where('ma.agent_relation', 'like', '%,' . $request->ID . ',%')
                ->where('ma.member_id', '!=', $request->ID)
                ->select(
                    DB::raw('sum(ma.topper_member_id = '.$request->ID.') as directPlayerCount'),
                    DB::raw('count(ma.topper_member_id) as teamCount')
                )
                ->first();
           
            //directPlayerCount
            $directPlayerCount = (isset($date->directPlayerCount)) ? $date->directPlayerCount : 0;
            
            //teamCount
            $teamCount = (isset($date->teamCount)) ? $date->teamCount : 0;

            //今日新增
            $addedToday = DB::table('member_agent as ma')
                ->join('members as m', function ($join) use ($startTime) {
                    $join->on('m.id', 'ma.member_id')
                        ->where('m.created_at', '>', $startTime);
                })
                ->where('ma.parent_id', $member->parent)
                ->where('ma.agent_relation', 'like', '%,' . $request->ID . ',%')
                ->where('ma.member_id', '!=', $request->ID)
                ->count();

            //活躍人數(指有投注)
            $activeToday = DB::table('member_agent as ma')
                ->join('summary_bet_records as s', function ($join) use ($startTime) {
                    $join->on('s.member_id', 'ma.member_id')
                        ->where('s.summary_time', '>', $startTime);
                })
                ->where('ma.parent_id', $member->parent)
                ->where('ma.agent_relation', 'like', '%,' . $request->ID . ',%')
                ->where('ma.member_id', '!=', $request->ID)
                ->select(
                    DB::raw('count(distinct s.member_id) as cnt')
                )
                ->first();

            //玩家
            $modelMemberCode = new MemberCode();
            if (!is_object(MemberCode::where('member_id', $request->ID)->first())) {
                $memberCode = array();
                $memberCode['member_id'] = $request->ID;
                $memberCode['agent_code'] = $this->getMemberAgentCode();
                MemberCode::create($memberCode);
            }
            $resultMemberCode = $modelMemberCode->where('member_id', $request->ID)->first();
            
            $member = DB::table('members as m')
                        ->join('agents as a', 'a.id', 'm.parent')
                        ->leftJoin('member_agent as ma', 'ma.member_id', 'm.id')
                        ->leftJoin('members as m1', 'ma.topper_member_id', 'm1.id')
                        ->where('m.id', $request->ID)
                        ->select(
                            'm.username',
                            'a.hierarchy',
                            'ma.topper_member_id',
                            'm1.username as parent_username'
                        )
                        ->first();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'topUsername' => isset($member->parent_username) ? $member->parent_username : '',
                    'username' => $member->username,
                    'teamCount' => $teamCount,
                    'directPlayerCount' => $directPlayerCount,
                    'addedToday' => $addedToday,
                    'activeToday' => $activeToday->cnt,
                    'code' => $resultMemberCode['agent_code'],
                    'link' => 'https://'.$request->domain.'/Signup/'.$resultMemberCode['agent_code']
                ],
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
     * unlimit/list
     * - 無限代理佣金制度表
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "level": 1,
     *            "minAmount": 0,
     *            "maxAmount": 9999.99,
     *            "rebateRate": 30.00,
     *            "bonusAmount": 0.30
     *        },
     *        {
     *            "level": 2,
     *            "minAmount": 0,
     *            "maxAmount": 9999.99,
     *            "rebateRate": 40.00,
     *            "bonusAmount": 0.40
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new Member();
            
            $data = $model->from('members AS m')
                ->join('agents AS a', 'a.id', 'm.parent')
                ->join('unlimit_systemrate AS us', 'us.agent_group_id', 'a.cash_flow_group_id')
                ->where('m.id', $request->ID)
                ->orderBy('us.level', 'ASC')
                ->select(
                    'us.level',
                    DB::raw('CASE WHEN (us.max_amount % 1) = 0 THEN TRUNCATE(us.min_amount, 0) ELSE TRUNCATE(us.min_amount, 2) END AS minAmount'),
                    DB::raw('CASE WHEN (us.max_amount % 1) = 0 THEN TRUNCATE(us.max_amount, 0) ELSE TRUNCATE(us.max_amount, 2) END AS maxAmount'),
                    DB::raw('TRUNCATE(us.bonus_amount, 0) AS bounsAmount'),
                    DB::raw('TRUNCATE(us.rebate_rate, 1) AS rebateRate')
                )
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' =>  $data
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
     * unlimit/directPlayers
     * - 我的玩家
     *
     * @apiParam member string required 玩家ID Example: aaron
     * @queryParam page integer required 第幾頁 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "member": "member",
     *            "teamContribution": 90010.65,
     *            "memberContribution": 0.0,
     *            "teamCount": 10,
     *            "memberCount": 3,
     *            "type": "代理"
     *        },
     *        {
     *            "member": "member1",
     *            "TeamContribution": 0.0,
     *            "memberContribution": 390000.0,
     *            "teamCount": 0,
     *            "memberCount": 1,
     *            "type": "玩家"
     *        }
     *    ],
     *    "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     */
    public function directPlayers(Request $request)
    {
        try {
            $request->per_page = isset($request->per_page)? $request->per_page : 7;
            $request->page = isset($request->page)? $request->page : 1;

            $startTime = date('Y-m-d H:i:s', strtotime(date('Y-m-d').' 00:00:00-8hour'));
            $endTime = date('Y-m-d H:i:s', strtotime(date('Y-m-d').' 23:59:59-8hour'));

            $data = DB::select('call SP_get_unlimit_my_player("'.$request->ID.'", "'.$startTime.'", "'.$endTime.'")');

            //call sp 無法使用count;
            $total = 0;
            foreach ($data as $key => $obj) {
                $data[$key]->teamContribution = (is_null($data[$key]->teamContribution))? 0 : floor($data[$key]->teamContribution * 100) / 100;
                $data[$key]->memberContribution = (is_null($data[$key]->memberContribution))? 0 : floor($data[$key]->memberContribution * 100) / 100;
                $data[$key]->type = ($data[$key]->memberType == 2) ? __('param.agent') : __('param.member');
                $total++;
            }
                
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
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
     * unlimit/reward
     * - 我的業績
     *
     * @queryParam page integer required 第幾頁 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "date": "2020-05-13",
     *            "totalContribution": 1293,
     *            "teamContribution": 6234,
     *            "memberContribution": 872,
     *            "reward": 247,
     *            "type": "未發放"
     *        },
     *        {
     *            "date": "2020-05-12",
     *            "totalContribution": 1046,
     *            "teamContribution": 3390,
     *            "memberContribution": 390,
     *            "reward": 563,
     *            "type": "已發放"
     *        }
     *    ],
     *    "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     */
    public function reward(Request $request)
    {
        try {
            $model = new UnlimitBonusReport();

            $request->per_page = isset($request->per_page)? $request->per_page : 7;
            $request->page = isset($request->page)? $request->page : 1;

            $data = $model->where('member_id', $request->ID)
                ->where(function ($query) {
                    $query->where('performance_team', '>', 0)
                        ->orWhere('performance_direct', '>', 0);
                })
                ->orderBy('summary_date', 'desc')
                ->select(
                    DB::raw('DATE_ADD(created_at, INTERVAL 8 HOUR) as date'),
                    DB::raw('(TRUNCATE(performance_direct, 2) + TRUNCATE(performance_team, 2)) as totalContribution'),
                    DB::raw('TRUNCATE(performance_team, 2) as teamContribution'),
                    DB::raw('TRUNCATE(performance_direct, 2) as memberContribution'),
                    DB::raw('(TRUNCATE(bonus_direct, 2) + TRUNCATE(bonus_team, 2)) as reward'),
                    DB::raw('"'.__('param.issued').'" as type'),
                    DB::raw("CASE WHEN correction = 1 THEN CONCAT('Retroactive Reward\nfor ', memo) ELSE '' END AS memo")
                )
                ->get();
            $total = $data->count();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
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
     * unlimit/history
     * - 我的提現
     *
     * @queryParam page integer required 第幾頁 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "date": "2020-05-13",
     *            "no": 165,
     *            "amount": 2534,
     *            "withdrawType": "point",
     *            "withdrawAccount": "",
     *            "type": "0:未發放, 1:已發放, 2:拒絕; ex:1"
     *        }
     *  ],
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *   }
     * }
     */
    public function getWithdrawHistory(Request $request)
    {
        try {
            $model = new UnlimitWithdrawLog();

            $request->per_page = isset($request->per_page)? $request->per_page : 7;
            $request->page = isset($request->page)? $request->page : 1;

            $data = $model->where('member_id', $request->ID)
                ->orderBy('created_at', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    DB::raw('DATE_ADD(created_at,INTERVAL 8 HOUR) as date'),
                    'id as no',
                    'amount',
                    DB::raw('case when type = 1 then "'.__('param.issued').'" else "'.__('param.notIssued').'" end as type'),
                    DB::raw('"'.__('param.points').'" as withdrawType'),
                    DB::raw('0 as withdrawAccount')
                )
                ->get();
                
            $total = $data->count();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
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
     * unlimit/withdraw
     * - 申請提現
     *
     * @bodyParam amount decimal required 提現金額 Example: 123.45
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     * 		"WithdrawLogId": 1
     * 	}
     * }
     */
    public function withdrawal(Request $request)
    {
        try {
            DB::beginTransaction();
            $transaction_type = 17;
            $remark = '全民代理奖励';
            $model = new UnlimitWithdrawLog();
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            
            //檢查可領取餘額
            $reward = array(
                'totalRewardPoints' => 0,
                'withdrawnPoints' => 0,
                'withdrawablePoints' => 0
            );
            $unlimitSummaryBonus = UnlimitSummaryBonus::lockForUpdate()
                ->where('member_id', $request->ID)
                ->select(
                    'id',
                    'withdraw_amount',
                    DB::raw('TRUNCATE(bonus_amount, 2) as totalRewardPoints'),
                    DB::raw('TRUNCATE(withdraw_amount, 2) as withdrawnPoints'),
                    DB::raw('(TRUNCATE(bonus_amount, 2) - TRUNCATE(withdraw_amount, 2)) as withdrawablePoints')
                )
                ->first();
            $reward['totalRewardPoints'] = (!isset($unlimitSummaryBonus->totalRewardPoints)) ? 0 : $unlimitSummaryBonus->totalRewardPoints;
            $reward['withdrawnPoints'] = (!isset($unlimitSummaryBonus->withdrawnPoints)) ? 0 : $unlimitSummaryBonus->withdrawnPoints;
            $reward['withdrawablePoints'] = (!isset($unlimitSummaryBonus->withdrawablePoints)) ? 0 : $unlimitSummaryBonus->withdrawablePoints;
            if ($request->amount > floatval($reward['withdrawablePoints'])) {
                //領取失敗
                DB::rollback();
                return response()->json([
                    'code' => 1022,
                    'message' => __('error_message.1022'),
                ]);
            }

            // 取會員資料
            $member = Member::lockForUpdate()
                ->where('id', $request->ID)
                ->select('id', 'parent', 'balance', 'event_mode')
                ->first();

            if ($member->event_mode == 1) {
                //鎖分模式中禁止領取全民代理獎勵
                DB::rollback();
                return response()->json([
                    'code' => 1934,
                    'message' => __('error_message.1934')
                ]);
            }
            // 取代理資料
            $agent = DB::table('agents as a')
                ->leftJoin('agent_customized_settings as s', function ($join) {
                    $join->on('s.agent_group_id', 'a.customized_setting_group_id')
                        ->where('s.setting_key', AgentCustomizedSettingKeyEnum::UNLIMIT_FLOW_LIMIT_RATE);
                })
                ->where('a.id', $member->parent)
                ->select(
                    'a.id',
                    'a.transaction_all',
                    DB::raw('IFNULL(s.value, 0) AS unlimit_flow_limit_rate')
                )
                ->first();
            if (is_object($member) && is_object($agent)) {
                $before_balance = $member->balance;
                $result = $member->increment('balance', $request->amount);
                if ($result) {
                    // 重新取最後的餘額
                    $member = Member::where('id', $request->ID)->select('id', 'balance')->first();
                    //寫入transation_log
                    $log = array();
                    $log['agent_id'] = $agent->id;
                    $log['member_id'] = $member->id;
                    $log['type'] = $transaction_type;
                    $log['point'] = $request->amount;
                    $log['before_balance'] = $before_balance;
                    $log['balance'] = $member->balance;
                    $log['status'] = 1000;
                    $log['remark'] = $remark;
                    $log['operator'] = $request->operator;
                    $log['admin'] = $member->id;
                    $logRecord = app('App\Http\Controllers\TransactionLogController')
                        ->insertLog($log);

                    $unlimitSummaryBonus->withdraw_amount = ($unlimitSummaryBonus->withdraw_amount + $request->amount);
                    $result1 = $unlimitSummaryBonus->save();
                    if ($result1) {
                        if (floatval($agent->unlimit_flow_limit_rate) > 0) {
                            //增加稽核流水log
                            $flow = array();
                            $flow['member_id'] = $request->ID;
                            $flow['type'] = 1;
                            $flow['reference_id'] = $logRecord->id;
                            $flow['flow_limit'] = strval($request->amount * floatval($agent->unlimit_flow_limit_rate));
                            $flow['reach'] = 0;
                            $flow['admin'] = 0;
                            $flowResult = FlowLimit::create($flow);
                        }

                        $row = array();
                        $row['member_id'] = $request->ID;
                        $row['order_no'] = Str::upper(str_random(10)); //訂單單號
                        $row['amount'] = $request->amount;
                        $row['reward_point'] = $reward['totalRewardPoints'];  //前一天結算金額
                        $row['withdrawn_point'] = $reward['withdrawnPoints'] + $request->amount; //已提領金額
                        $row['type'] = 1;
                        $row['remark'] = $remark;
                        $row['admin'] = 0;
                        $result = $model->create($row);
                        DB::commit();

                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => $member->balance
                        ]);
                    } else {
                        DB::rollback();
                        // 資料庫操作失敗
                        return response()->json([
                            'code' => 2001,
                            'message' => __('error_message.2001')
                        ]);
                    }
                } else {
                    DB::rollback();
                    // 資料庫操作失敗
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001')
                    ]);
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollback();
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * unlimit/withdrawRecode
     * - 提現紀錄欄
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     * 		"username": "user",
     * 		"totalRewardPoints": 81293,
     * 		"withdrawnPoints": 4534,
     * 		"withdrawablePoints": 5434
     * 	}
     * }
     */
    public function getWithdrawal(Request $request)
    {
        try {
            $model = new Member();
            $member = $model->where('id', $request->ID)->first();
            
            if (!is_object($member)) {
                return response()->json([
                    'code' => 1005,
                    'message' => __('error_message.1005')
                ]);
            }

            $model = new UnlimitSummaryBonus();
            $data = $model->where('member_id', $request->ID)
                ->select(
                    DB::raw('TRUNCATE(bonus_amount, 2) as totalRewardPoints'),
                    DB::raw('TRUNCATE(withdraw_amount, 2) as withdrawnPoints'),
                    DB::raw('(TRUNCATE(bonus_amount, 2) - TRUNCATE(withdraw_amount, 2)) as withdrawablePoints')
                )
                ->first();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'username' => $member->username,
                    'totalRewardPoints' => (is_object($data)) ? $data->totalRewardPoints : 0.00,  //總獎勵全民積分
                    'withdrawnPoints' => (is_object($data)) ? $data->withdrawnPoints : 0.00,  //已提現全民積分
                    'withdrawablePoints' => (is_object($data)) ? $data->withdrawablePoints : 0.00 //可提現全民積分
                    ],
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
    
    private function calcRewardPoint($request)
    {
        $result = array(
            'totalRewardPoints' => 0,
            'withdrawnPoints' => 0,
            'withdrawablePoints' => 0
        );

        if (isset($request->ID)) {
            $model = new UnlimitSummaryBonus();
            $data = $model->where('member_id', $request->ID)
                ->select(
                    DB::raw('TRUNCATE(bonus_amount, 2) as totalRewardPoints'),
                    DB::raw('TRUNCATE(withdraw_amount, 2) as withdrawnPoints'),
                    DB::raw('(TRUNCATE(bonus_amount, 2) - TRUNCATE(withdraw_amount, 2)) as withdrawablePoints')
                )
                ->first();
            $result['totalRewardPoints'] = (!isset($data->totalRewardPoints)) ? 0 : $data->totalRewardPoints;
            $result['withdrawnPoints'] = (!isset($data->withdrawnPoints)) ? 0 : $data->withdrawnPoints;
            $result['withdrawablePoints'] = (!isset($data->withdrawablePoints)) ? 0 : $data->withdrawablePoints;
        }
        return $result;
    }

    private function getMemberAgentCode()
    {
        $str = Str::upper(env('UNLIMIT_PREFIX').str_random(7));
        $is_exist = MemberCode::where('agent_code', $str)->count();
        if ($is_exist) {
            return $this->getMemberAgentCode();
        }
        return $str;
    }

    private function messages()
    {
        return [
            'game_id.required' => __('error_message.1407'),
            'game_id.digits_between' => __('error_message.1414'),

            'status.required' => __('error_message.2009'),
            'status.boolean' => __('error_message.2009'),

            'number.numeric' => __('error_message.2032'),

            'vendor_id.numeric' => __('error_message.1401'),
            'type_id.numeric' => __('error_message.1402'),
            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),
            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}
