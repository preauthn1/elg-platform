<?php

namespace App\Http\Controllers;

use App\Helpers\SQLHelper;
use Illuminate\Http\Request;
use App\Models\WJackpot;
use App\Models\WJackpotTicket;
use App\Models\Member;
use App\Models\PrizeMember;
use App\Models\FlowLimit;
use App\Models\TransactionLog;
use App\Enums\AgentCustomizedSettingKeyEnum;
use App\REDIS;
use DB;
use Validator;

class WJackpotController extends Controller
{

    /**
     * event/wjackpot/issues
     * - 抽獎活動
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "issue_id": "抽奖活动期数ID, ex: 1",
     *            "issue_name": "抽獎活動期數; ex: no. 1",
     *            "draw_time": "開獎日期; ex: 2020-08-17 18:00:00",
     *        }
     *  ]
     * }
     */
    public function issue_list(Request $request)
    {
        try {
            $member = Member::find($request->ID);
            $parents = $this->listParent($member->parent);
            $now = now();
            $data = DB::table('events as e')
                ->join('w_jackpots as wj', 'wj.event_id', 'e.id')
                ->join('event_details as ed', function ($join) use ($now) {
                    $join->on('ed.id' , 'wj.event_detail_id')
                        ->where('ed.start_time', '<=', $now);
                })
                ->where(function ($query) use ($parents) {
                    $query->whereNull('agent_id')
                        ->orWhereIn('agent_id', $parents);
                })
                ->orderBy('wj.no', 'DESC')
                ->select(
                    'wj.id AS issue_id',
                    DB::raw("REPLACE('" . __('param.periods') . "', '{N}', wj.no) AS issue_name"),
                    DB::raw("date_add(wj.draw_time, INTERVAL 8 HOUR) AS draw_time")
                )
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
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
     * event/wjackpot/issues/detail
     * - 抽獎活動明細
     *
     * @bodyParam issue_id integer required 抽奖活动期数ID Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *      "issue_id": "抽獎活動ID, ex: 1",
     *      "issue_name": "抽獎名稱, ex: Lucky Draw No. 1",
     *      "begin_time": "開始日期, ex: 2020-08-10 18:00:00",
     *      "end_time": "結束日期, ex: 2020-08-17 18:00:00",
     *      "draw_time": "開獎日期, ex: 2020-08-10 18:00:00",
     *      "result_number": "開獎號碼, ex: 333333-1",
     *      "summary":
     *        {
     *            "totalCount_tickets": "抽獎券張數, ex: 1",
     *            "totalCount_winnings": "獎勵金額總計; ex: 100.00",
     *            "totalCount_unclaimed": "獎勵金額未領取總計 ex: 100.00"
     *        }
     *  }
     * }
     */
    public function issue_detail(Request $request)
    {
        try {
            $main = DB::table('w_jackpots AS wj')
                ->join('event_details as ed', 'ed.id', 'wj.event_detail_id')
                ->where('wj.id', $request->issue_id)
                ->select(
                    'wj.id AS issue_id',
                    DB::raw("REPLACE('" . __('param.w_jackpot_periods') . "', '{N}', wj.no) AS issue_name"),
                    DB::raw('DATE_ADD(ed.start_time, INTERVAL 8 HOUR) AS begin_time'),
                    DB::raw('DATE_ADD(ed.end_time, INTERVAL 8 HOUR) AS end_time'),
                    DB::raw('DATE_ADD(wj.draw_time, INTERVAL 8 HOUR) AS draw_time'),
                    DB::raw("CASE WHEN wj.status = 1 THEN wj.draw_number ELSE '----' END AS result_number"),
                    DB::raw("CASE WHEN wj.status = 1 THEN wj.draw_number_2 ELSE '----' END AS result_number_2"),
                    DB::raw("CASE WHEN wj.status = 1 THEN wj.draw_number_3 ELSE '----' END AS result_number_3"),
                    DB::raw("CASE WHEN wj.status = 1 THEN wj.draw_number_4 ELSE '----' END AS result_number_4"),
                    DB::raw("CASE WHEN wj.status = 1 THEN wj.draw_number_5 ELSE '----' END AS result_number_5"),
                    DB::raw("'' as summary")
                )
                ->first();
            $model_ticket = new WJackpotTicket();
            $summary_data = $model_ticket->where('w_jackpot_id', $request->issue_id)
                ->where('member_id', $request->ID)
                ->groupBy('member_id')
                ->select(
                    DB::raw('COUNT(*) AS totalCount_tickets'),
                    DB::raw('TRUNCATE(IFNULL(SUM(point), 0), 2) AS totalCount_winnings'),
                    DB::raw('TRUNCATE(SUM(CASE WHEN status = 0 THEN IFNULL(point, 0) ELSE 0 END), 2) AS totalCount_unclaimed')
                )
                ->first();
            if (!is_object($summary_data)) {
                $summary_data = array();
                $summary_data["totalCount_tickets"] = 0;
                $summary_data["totalCount_winnings"] = 0;
                $summary_data["totalCount_unclaimed"] = 0;
            }
            $data = array();
            $data['issue_id'] = $main->issue_id;
            $data['issue_name'] = $main->issue_name;
            $data['begin_time'] = $main->begin_time;
            $data['end_time'] = $main->end_time;
            $data['draw_time'] = $main->draw_time;
            $data['result_number'] = $main->result_number;
            $data['result_number_2'] = $main->result_number_2;
            $data['result_number_3'] = $main->result_number_3;
            $data['result_number_4'] = explode(',', $main->result_number_4);
            $data['result_number_5'] = explode(',', $main->result_number_5);
            $data['summary'] = $summary_data;
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
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
     * event/wjackpot/issues/tickets
     * - 每期抽獎券明細
     *
     * @bodyParam issue_id integer required 抽奖活动期数ID Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *      "result_number": "",
     *      "tickets": [
     *        {
     *            "ticket_id": "抽獎活動ID, ex: 1",
     *            "ticket_number": "抽獎券號碼; ex: 123456-1",
     *            "reward_type": "獎勵類型 ex: 1:獎金, 2:獎品",
     *            "reward_level": "獎勵等級 ex: 1",
     *            "reward_amount": "獎勵金額 ex: 100.00",
     *            "reward_name": "獎品名稱 ex: Perodua Axia轎車",
     *            "type": "中獎結果; 0:未中 , 1:中獎, ex: 1",
     *            "status": "提領結果; 0:未領 , 1:已領取, ex: 1",
     *            "statusText": "結果; ex: 已領取"
     *        }
     *      ]
     *  },
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
    public function issue_tickets(Request $request)
    {
        try {
            $model = new WJackpot();

            $data = array();
            $model = $model->where('id', $request->issue_id)
                ->select(
                    DB::raw("IFNULL(draw_number, '') AS draw_number"),
                    DB::raw("IFNULL(draw_number_2, '') AS draw_number_2"),
                    DB::raw("IFNULL(draw_number_3, '') AS draw_number_3"),
                    DB::raw("IFNULL(draw_number_4, '') AS draw_number_4"),
                    DB::raw("IFNULL(draw_number_5, '') AS draw_number_5"),
                    'status'
                )
                ->first();
            $status = $model['status'];
            $data["result_number"] = $model['draw_number'];
            $data["result_number_2"] = $model['draw_number_2'];
            $data["result_number_3"] = $model['draw_number_3'];
            $data["result_number_4"] = explode(',', $model['draw_number_4']);
            $data["result_number_5"] = explode(',', $model['draw_number_5']);

            //$model = DB::table('w_jackpot_tickets as t')
            $model = DB::table('w_jackpot_tickets as wjt')
                ->join('w_jackpots as wj', 'wj.id', 'wjt.w_jackpot_id')
                ->join('prizes as p', 'p.event_id', 'wj.event_id')
                ->leftJoin('w_jackpot_reward_items as wjri', 'wjri.id', 'wjt.reward_item_id')
                ->leftJoin('w_jackpot_reward_item_names as wjrin', function($join) use ($request) {
                    $join->on('wjrin.w_jackpot_reward_item_id', 'wjri.id')
                        ->where('wjrin.language_id', $request->LANGUAGE);
                })
                ->where('wjt.w_jackpot_id', $request->issue_id)
                ->where('wjt.member_id', $request->ID)
                ->select(
                    'wjt.id as ticket_id',
                    'ticket_number',
                    'wjri.type AS reward_type',
                    DB::raw('IFNULL(wjri.level, 0) AS reward_level'),
                    DB::raw('TRUNCATE(wjt.point, 2) AS reward_amount'),
                    DB::raw("CASE WHEN wjri.type = 2 THEN IFNULL(wjrin.prize_name, wjri.prize_name) ELSE '' END AS reward_name"),
                    //0:未開獎, 1:已開獎-已領取; 2:已開獎-未領取; 3:已開獎-沒中獎
                    DB::raw("CASE WHEN ". $status . " = 0 THEN 0 WHEN wjt.status = 1 THEN 1 WHEN wjt.type = 0 THEN 3 ELSE 2 END AS status"),
                    DB::raw("CASE WHEN ". $status . " = 0 THEN '" . __('param.no_draw') .
                        "' WHEN wjt.type = 0 THEN '" . __('param.did_not_win') .
                        "' WHEN wjt.status = 1 THEN '" . __('param.received') .
                        "' ELSE '" . __('param.unaccalimed') . "' END AS statusText")
                );
            $count = $model->count();

            $data["tickets"] = $model->orderBy('wjt.type', 'desc')
                ->orderBy('wjri.level', 'asc')
                ->orderBy('wjt.id', 'asc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,                
                'pagination' => $this->getPagination($count, $request->per_page, $request->page)
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
     * event/wjackpot/issues/receive
     * - 领取奖项
     *
     * @bodyParam ticket_id integer required 抽獎券ID Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function receive(Request $request)
    {
        $code = 0;
        $message = '';
        $type = 30;  //W-jackpot
        try {
            $validator = Validator::make($request->all(), ['ticket_id' => 'required|digits_between:0,10'], $this->messages());
            if (!$validator->passes()) {
                $code = 2002;
                $message = $validator->errors()->all();
            } else {
                //鎖分模式中禁止領取獎勵
                if ($this->checkMemberEventLockByMemberId($request->ID) > 0) {
                    $code = 1934;
                    $message = __('error_message.1934');
                } else {
                    //抽獎券檢查
                    $ticket = DB::table('w_jackpot_tickets as wjt')
                        ->join('w_jackpot_reward_items as wjri', 'wjri.id', 'wjt.reward_item_id')
                        ->leftJoin('w_jackpot_reward_item_names as wjrin', function ($join) use ($request) {
                            $join->on('wjri.id', 'wjt.reward_item_id')
                                ->where('wjrin.language_id', $request->LANGUAGE);
                        })
                        ->join('members as m', 'm.id', 'wjt.member_id')
                        ->join('agents as a', 'a.id', 'm.parent')
                        ->join('w_jackpots as wj', 'wj.id', 'wjt.w_jackpot_id')
                        ->join('prizes as p', function ($join) {
                            $join->on('p.event_id', 'wj.event_id')
                                ->where('p.status', 1);
                        })
                        ->where('wjt.id', $request->ticket_id)
                        ->where('wjt.member_id', $request->ID)
                        ->select(
                            'a.id as agent_id',
                            'wjt.member_id',
                            'wjt.reward_item_id',
                            'wjri.type AS reward_type',
                            'wjt.point',
                            DB::raw('IFNULL(wjrin.prize_name, wjri.prize_name) AS prize_name'),
                            'wjt.type',
                            'wjt.status',
                            'p.id as prize_id',
                            DB::raw('IFNULL(p.flow_limit_multiple, 0) AS event_flow_limit_rate'),
                            'a.customized_setting_group_id'
                        )
                        ->first();
                    if (!is_object($ticket)) {
                        //查無資料
                        $code = 1941;
                        $message = __('error_message.1941');
                    } else if ($ticket->reward_item_id == 0 && $ticket->type == 0) {
                        //未中獎
                        $code = 1942;
                        $message = __('error_message.1942');
                    } else if ($ticket->status == 1) {
                        //已領取
                        $code = 1943;
                        $message = __('error_message.1943');
                    } else if ($ticket->reward_type == 2) {
                        //獎項, 請洽客服人員
                        $code = 1944;
                        $message = __('error_message.1944');
                    } else {
                        DB::beginTransaction();
                        $member = Member::lockForUpdate()
                            ->where('id', $request->ID)
                            ->first();
                        $before_balance = $member->balance;
                        $balance = ($member->balance + $ticket->point);

                        WJackpotTicket::find($request->ticket_id)
                            ->update(
                                [
                                    'status' => 1,
                                    'time' => date('Y-m-d H:i:s')
                                ]
                            );

                        //已領取
                        PrizeMember::where('member_id', $request->ID)
                            ->where('prize_id', $ticket->prize_id)
                            ->where('lottery_number', $request->ticket_id)
                            ->update(
                                [
                                    'status' => 1,
                                ]
                            );

                        $member->update([
                            'balance' => $balance
                        ]);
                        DB::commit();

                        //寫入轉點記錄
                        $log = [
                            'transaction_no' => $request->ticket_id,
                            'agent_id' => $ticket->agent_id,
                            'member_id' => $ticket->member_id,
                            'type' => $type,
                            'rate' => 1,
                            'point' =>  $ticket->point,
                            'before_balance' => $before_balance,
                            'balance' => $balance,
                            'status' => 1000,
                            'remark' => '',
                            'operator' => 'system',
                        ];
                        $log_record = TransactionLog::create($log);

                        //增加稽核流水log
                        if (floatval($ticket->event_flow_limit_rate) > 0) {
                            $flow = array();
                            $flow['member_id'] = $member->id;
                            $flow['type'] = $type;
                            $flow['reference_id'] = $log_record->id;
                            $flow['flow_limit'] = strval($ticket->point * floatval($ticket->event_flow_limit_rate));
                            $flow['reach'] = 0;
                            $flow['admin'] = 0;
                            FlowLimit::create($flow);
                        }
                        $code = 1000;
                        $message = __('error_message.1000');
                    }
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            $code = $info[0];
            $message = $info[2];
        } catch (\Exception $e) {
            $code = $e->getCode();
            $message = $e->getMessage();
        }

        if ($code != 1000) {
            DB::rollback();
        }

        return response()->json([
            'code' => $code,
            'message' => $message
        ]);
    }

    /**
     * event/wjackpot/issues/receive-all
     * - 该期奖项全部领取
     *
     * @bodyParam issue_id integer required 抽奖活动期数ID Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function receive_all(Request $request)
    {
        $type = 30;  //W-jackpot
        try {
            $validator = Validator::make($request->all(), ['issue_id' => 'required|digits_between:0,10'], $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            } else {
                //鎖分模式中禁止領取獎勵
                if ($this->checkMemberEventLockByMemberId($request->ID) > 0) {
                    return response()->json([
                        'code' => 1934,
                        'message' => __('error_message.1934')
                    ]);
                }

                //抽獎券取得
                $tickets = DB::table('w_jackpot_tickets as wjt')
                    ->join('members as m', 'm.id', 'wjt.member_id')
                    ->join('agents as a', 'a.id', 'm.parent')
                    ->join('w_jackpots as wj', 'wj.id', 'wjt.w_jackpot_id')
                    ->join('prizes as p', function ($join) {
                        $join->on('p.event_id', 'wj.event_id')
                            ->where('p.status', 1);
                    })
                    ->where('wjt.w_jackpot_id', $request->issue_id)
                    ->where('wjt.member_id', $request->ID)
                    ->where('wjt.reward_item_id', '>', 0)
                    ->where('wjt.status', 0)
                    ->where('wjt.point', '>', 0)
                    ->select(
                        'wjt.id as ticket_id',
                        'a.id as agent_id',
                        'wjt.member_id',
                        'wjt.reward_item_id',
                        'wjt.point',
                        'wjt.type',
                        'wjt.status',
                        'p.id as prize_id',
                        DB::raw('IFNULL(p.flow_limit_multiple, 0) AS event_flow_limit_rate'),
                        'a.customized_setting_group_id'
                    )
                    ->get();

                if (count($tickets) == 0) {
                    //查無資料
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ]);
                } else {
                    DB::beginTransaction();
                    $member = Member::lockForUpdate()->where('id', $request->ID)
                        ->first();
                    $before_balance = $member->balance;
                    foreach ($tickets as $ticket) {
                        $balance = ($before_balance + $ticket->point);
                        WJackpotTicket::find($ticket->ticket_id)
                            ->update(
                                [
                                    'status' => 1,
                                    'time' => date('Y-m-d H:i:s')
                                ]
                            );

                        //已領取
                        PrizeMember::where('member_id', $request->ID)
                            ->where('prize_id', $ticket->prize_id)
                            ->where('lottery_number', $ticket->ticket_id)
                            ->update(
                                [
                                    'status' => 1,
                                ]
                            );

                        $member->update([
                            'balance' => $balance
                        ]);

                        //寫入轉點記錄
                        $log = [
                            'transaction_no' => $ticket->ticket_id,
                            'agent_id' => $ticket->agent_id,
                            'member_id' => $ticket->member_id,
                            'type' => $type,
                            'rate' => 1,
                            'point' =>  $ticket->point,
                            'before_balance' => $before_balance,
                            'balance' => $balance,
                            'status' => 1000,
                            'remark' => '',
                            'operator' => 'system',
                        ];
                        $log_record = TransactionLog::create($log);

                        //增加稽核流水log
                        if (floatval($ticket->event_flow_limit_rate) > 0) {
                            $flow = array();
                            $flow['member_id'] = $ticket->member_id;
                            $flow['type'] = $type;
                            $flow['reference_id'] = $log_record->id;
                            $flow['flow_limit'] = strval($ticket->point * floatval($ticket->event_flow_limit_rate));
                            $flow['reach'] = 0;
                            $flow['admin'] = 0;
                            FlowLimit::create($flow);
                        }
                        $before_balance = $balance;
                    }
                    DB::commit();
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ]);
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollback();
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2],
                'type' => 'QueryException'
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'type' => 'Exception'
            ]);
        }
    }

    private function messages()
    {
        return [
            'id.required' => __('error_message.1902'),
            'id.digits_between' => __('error_message.1903'),

            'issue_id.required' => __('error_message.1939'),
            'issue_id.digits_between' => __('error_message.1903'),
            'ticket_id.required' => __('error_message.1940'),
            'ticket_id.digits_between' => __('error_message.1903'),

            'detail_id.required' => __('error_message.1902'),
            'detail_id.digits_between' => __('error_message.1903'),
            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),
            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}
