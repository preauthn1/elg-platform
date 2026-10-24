<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\Common;
use App\Models\LuckyDraw;
use App\Models\LuckyDrawTicket;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Models\PrizeMember;
use App\Models\FlowLimit;
use App\Enums\AgentCustomizedSettingKeyEnum;
use DB;
use App\REDIS;
use Validator;

class EventLuckyDrawController extends Controller
{

    /**
     * event/lucky-draw/issues
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
                ->join('lucky_draws as ld', 'ld.event_id', 'e.id')
                ->join('event_details as ed', function ($join) use ($now) {
                    $join->on('ed.id' , 'ld.event_detail_id')
                        ->where('ed.start_time', '<=', $now);
                })
                ->where(function ($query) use ($parents) {
                    $query->whereNull('agent_id')
                        ->orWhereIn('agent_id', $parents);
                })
                ->orderBy('ld.no', 'DESC')
                ->select(
                    'ld.id as issue_id',
                    DB::raw("REPLACE('" . __('param.periods') . "', '{N}', ld.no) as issue_name"),
                    DB::raw("date_add(ld.draw_time, interval 8 hour) as draw_time")
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
     * event/lucky-draw/issues/detail
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
            $main = DB::table('lucky_draws as ld')
                ->join('event_details as ed', 'ed.id', 'ld.event_detail_id')
                ->where('ld.id', $request->issue_id)
                ->select(
                    'ld.id as issue_id',
                    DB::raw("REPLACE('" . __('param.Lucky_draw_periods') . "', '{N}', ld.no) as issue_name"),
                    DB::raw('DATE_ADD(ed.start_time, INTERVAL 8 HOUR) as begin_time'),
                    DB::raw('DATE_ADD(ed.end_time, INTERVAL 8 HOUR) as end_time'),
                    DB::raw('DATE_ADD(ld.draw_time, INTERVAL 8 HOUR) as draw_time'),
                    DB::raw("CASE WHEN ld.status = 1 THEN ld.draw_number ELSE '-------' END AS result_number"),
                    DB::raw("'' as summary")
                )
                ->first();
            $model_ticket = new LuckyDrawTicket();
            $summary_data = $model_ticket->where('lucky_draw_id', $request->issue_id)
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
     * event/lucky-draw/issues/tickets
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
            $model = new LuckyDraw();

            $data = array();
            $model = $model->where('id', $request->issue_id)
                ->select(
                    DB::raw("IFNULL(draw_number, '') AS draw_number"),
                    'status'
                )
                ->first();
            $status = $model['status'];
            $data["result_number"] = $model['draw_number'];

            $model = DB::table('lucky_draw_tickets as t')
                ->leftJoin('reward_items as ri', 'ri.id', 't.reward_item_id')
                ->leftJoin('reward_item_names as rin', function($join) use ($request) {
                    $join->on('rin.type_id', 'ri.id')
                        ->where('rin.language_id', $request->LANGUAGE);
                })
                ->where('t.lucky_draw_id', $request->issue_id)
                ->where('t.member_id', $request->ID)
                ->select(
                    't.id as ticket_id',
                    'ticket_number',
                    'ri.type AS reward_type',
                    DB::raw('IFNULL(ri.level, 0) AS reward_level'),
                    DB::raw('TRUNCATE(t.point, 2) AS reward_amount'),
                    DB::raw("CASE WHEN ri.type = 2 THEN IFNULL(rin.prize_name, ri.prize_name) ELSE '' END AS reward_name"),
                    //0:未開獎, 1:已開獎-已領取; 2:已開獎-未領取; 3:已開獎-沒中獎
                    DB::raw("CASE WHEN ". $status . " = 0 THEN 0 WHEN t.status = 1 THEN 1 WHEN t.type = 0 THEN 3 ELSE 2 END AS status"),
                    DB::raw("CASE WHEN ". $status . " = 0 THEN '" . __('param.no_draw') .
                        "' WHEN t.type = 0 THEN '" . __('param.did_not_win') .
                        "' WHEN t.status = 1 THEN '" . __('param.received') .
                        "' ELSE '" . __('param.unaccalimed') . "' END AS statusText")
                );
            $count = $model->count();

            $data["tickets"] = $model->orderBy('t.type', 'desc')
                ->orderBy('ri.level', 'asc')
                ->orderBy('t.id', 'asc')
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
     * event/lucky-draw/issues/receive
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
        $type = 18;
        try {
            $validator = Validator::make($request->all(), ['ticket_id' => 'required|digits_between:0,10'], $this->messages());
            if (!$validator->passes()) {
                $code = 2002;
                $message = $validator->errors()->all();
            } else {
                //抽獎券檢查
                $ticket = DB::table('lucky_draw_tickets as t')
                    ->join('reward_items as ri', 'ri.id', 't.reward_item_id')
                    ->leftJoin('reward_item_names as rin', function ($join) use ($request) {
                        $join->on('ri.id', 't.reward_item_id')
                            ->where('rin.language_id', $request->LANGUAGE);
                    })
                    ->join('members as m', 'm.id', 't.member_id')
                    ->join('agents as a', 'a.id', 'm.parent')
                    ->leftJoin('agent_customized_settings as s', function ($join) {
                        $join->on('s.agent_group_id', 'a.customized_setting_group_id')
                            ->where('s.setting_key', AgentCustomizedSettingKeyEnum::EVENT_FLOW_LIMIT_RATE);
                    })
                    ->where('t.id', $request->ticket_id)
                    ->where('t.member_id', $request->ID)
                    ->select(
                        'a.id as agent_id',
                        't.member_id',
                        't.reward_item_id',
                        'ri.type AS reward_type',
                        't.point',
                        DB::raw('IFNULL(rin.prize_name, ri.prize_name) AS prize_name'),
                        't.type',
                        't.status',
                        DB::raw('IFNULL(s.value, 0) AS event_flow_limit_rate'),
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

                    LuckyDrawTicket::find($request->ticket_id)
                        ->update(
                            [
                                'status' => 1,
                                'time' => date('Y-m-d H:i:s')
                            ]
                        );

                    //已領取
                    PrizeMember::where('lottery_number', $request->ticket_id)
                        ->update(
                            [
                                'status' => 1,
                            ]
                        );

                    $member->update([
                        'balance' => $balance
                    ]);
                    DB::commit();

                    //寫入transation_log
                    $log = array();
                    $log['agent_id'] = $ticket->agent_id;
                    $log['member_id'] = $ticket->member_id;
                    $log['order_id'] = $request->ticket_id;
                    $log['type'] = $type;
                    $log['point'] = $ticket->point;
                    $log['before_balance'] = $before_balance;
                    $log['balance'] = $balance;
                    $log['status'] = 1000;
                    $log['remark'] = '';
                    $log['operator'] = $ticket->member_id;
                    $logRecord = app('App\Http\Controllers\TransactionLogController')
                        ->insertLog($log);

                    //增加稽核流水log
                    if (floatval($ticket->event_flow_limit_rate) > 0) {
                        $flow = array();
                        $flow['member_id'] = $member->id;
                        $flow['type'] = $type;
                        $flow['reference_id'] = $logRecord->id;
                        $flow['flow_limit'] = strval($ticket->point * floatval($ticket->event_flow_limit_rate));
                        $flow['reach'] = 0;
                        $flow['admin'] = 0;
                        FlowLimit::create($flow);
                    }
                    $code = 1000;
                    $message = __('error_message.1000');
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
     * event/lucky-draw/issues/receive-all
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
        $code = 0;
        $message = '';
        $type = 18;
        try {
            $validator = Validator::make($request->all(), ['issue_id' => 'required|digits_between:0,10'], $this->messages());
            if (!$validator->passes()) {
                $code = 2002;
                $message = $validator->errors()->all();
            } else {
                //抽獎券取得
                $tickets = DB::table('lucky_draw_tickets as t')
                    ->join('members as m', 'm.id', 't.member_id')
                    ->join('agents as a', 'a.id', 'm.parent')
                    ->leftJoin('agent_customized_settings as s', function ($join) {
                        $join->on('s.agent_group_id', 'a.customized_setting_group_id')
                            ->where('s.setting_key', AgentCustomizedSettingKeyEnum::EVENT_FLOW_LIMIT_RATE);
                    })
                    ->join('lucky_draws as ld', 'ld.id', 't.lucky_draw_id')
                    ->join('prizes as p', 'p.event_id', 'ld.event_id')
                    ->where('t.lucky_draw_id', $request->issue_id)
                    ->where('t.member_id', $request->ID)
                    ->where('t.reward_item_id', '>', 0)
                    ->where('t.status', 0)
                    ->where('t.point', '>', 0)
                    ->select(
                        't.id as ticket_id',
                        'a.id as agent_id',
                        't.member_id',
                        't.reward_item_id',
                        't.point',
                        't.type',
                        't.status',
                        'p.id as prize_id',
                        DB::raw('IFNULL(s.value, 0) AS event_flow_limit_rate'),
                        'a.customized_setting_group_id'
                    )
                    ->get();
                if (count($tickets) == 0) {
                    //查無資料
                    $code = 1000;
                    $message = __('error_message.1000');
                } else {
                    DB::beginTransaction();
                    $member = Member::lockForUpdate()->where('id', $request->ID)
                        ->first();
                    $before_balance = $member->balance;
                    foreach ($tickets as $ticket) {
                        $balance = ($before_balance + $ticket->point);
                        LuckyDrawTicket::find($ticket->ticket_id)
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

                        //寫入transation_log
                        $log = array();
                        $log['agent_id'] = $ticket->agent_id;
                        $log['member_id'] = $ticket->member_id;
                        $log['order_id'] = $request->ticket_id;
                        $log['type'] = $type;
                        $log['point'] = $ticket->point;
                        $log['before_balance'] = $before_balance;
                        $log['balance'] = $balance;
                        $log['status'] = 1000;
                        $log['remark'] = '';
                        $log['operator'] = $ticket->member_id;
                        $logRecord = app('App\Http\Controllers\TransactionLogController')
                            ->insertLog($log);

                        //增加稽核流水log
                        if (floatval($ticket->event_flow_limit_rate) > 0) {
                            $flow = array();
                            $flow['member_id'] = $ticket->member_id;
                            $flow['type'] = $type;
                            $flow['reference_id'] = $logRecord->id;
                            $flow['flow_limit'] = strval($ticket->point * floatval($ticket->event_flow_limit_rate));
                            $flow['reach'] = 0;
                            $flow['admin'] = 0;
                            FlowLimit::create($flow);
                        }
                        $before_balance = $balance;
                    }
                    DB::commit();
                    $code = 1000;
                    $message = __('error_message.1000');
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
        return response()->json([
            'code' => $code,
            'message' => $message
        ]);
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
