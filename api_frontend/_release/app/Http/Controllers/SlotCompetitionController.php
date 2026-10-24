<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\SQLHelper;
use App\Enums\AgentCustomizedSettingKeyEnum;
use App\Models\Member;
use App\Models\Agent;
use App\Models\EventSlotCompetition;
use App\Models\EventSlotCompetitionRecord;
use App\Models\PrizeMember;
use App\REDIS;
use DB;
use Validator;

/**
 * @group SlotCompetition
 */
class SlotCompetitionController extends Controller
{

    /**
     * event/slot_competition/list
     * - 老虎機期數
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "event_id": "id,ex:1",
     *            "id": "id,ex:1",
     *            "no": "老虎機期數,ex:1",
     *            "start_time": "開始時間,ex:2022-05-01 00:00:00",
     *            "end_time": "結束時間,ex:2022-05-31 23:59:59"
     *        },
     *         {
     *            "id": 2,
     *            "no": 2,
     *            "start_time": "2022-05-31 00:00:00",
     *            "end_time": "2022-06-30 23:59:59"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new EventSlotCompetitionRecord();

            // 取自己營運商、營運商上線及平台的活動
            $parents = $this->listParent($request->MY_PARENT_AGENT);
            $data = DB::table('events as e')
                ->join('event_details as ed', function ($join) {
                    $join->on('ed.event_id', 'e.id')
                        ->where('ed.start_time', '<=', date('Y-m-d H:i:s'))
                        ->where('ed.status', 1)
                        ->where('ed.action_id', 8);
                })
                ->join('event_slot_competitions as esc', function ($join) {
                    $join->on('esc.event_id', 'e.id')
                        ->on('esc.event_detail_id', 'ed.id');
                })
                ->whereIn('e.agent_id', $parents)
                ->where('e.status', 1)
                ->orderBy('esc.no', 'DESC')
                ->select(
                    'e.id as event_id',
                    'esc.id as id',
                    'esc.no as no',
                    DB::raw('DATE_ADD(ed.start_time, INTERVAL 8 HOUR) as start_time'),
                    DB::raw('DATE_ADD(ed.end_time, INTERVAL 8 HOUR) as end_time')
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
     * event/slot_competition/leader_board
     * - 老虎機排行榜
     *
     * @bodyParam event_id integer 活動ID Example: 1
     * @bodyParam no integer 期數 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "ranking": "排名,ex:1",
     *            "username": "玩家名稱,ex:ab**ef",
     *            "bet_amount": "投注金額,ex:100.00",
     *            "last_bet_time": "最後投注時間,ex:2022-05-31 00:00:00"
     *        },
     *         {
     *            "ranking": 2,
     *            "username": "aa**bb",
     *            "bet_amount": "50.00",
     *            "last_bet_time": "2022-05-30 00:00:00"
     *        }
     *    ]
     * }
     */
    public function leaderBoard(Request $request)
    {
        try {
            $model = new EventSlotCompetitionRecord();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }

            $request->page = isset($request->page) ? $request->page : 1;
            $request->per_page = isset($request->per_page) ? $request->per_page : 100;

            $result = array();
            if (isset($request->ID)) {
                $data = DB::table('event_slot_competitions as esc')
                    ->join('event_slot_competition_records as escr', 'escr.event_slot_competition_id', 'esc.id')
                    ->join('members as m', function ($join) {
                        $join->on('m.id', 'escr.member_id')
                            ->where('m.status', 1);
                    })
                    ->where('esc.event_id', $request->event_id)
                    ->where('esc.no', $request->no)
                    ->orderBy('escr.bet_amount', 'DESC')
                    ->orderBy('last_bet_time', 'ASC')
                    ->skip(($request->page - 1) * $request->per_page)
                    ->take($request->per_page)
                    ->select(
                        DB::raw('0 as ranking'),
                        'm.id as member_id',
                        'm.username',
                        DB::raw('TRUNCATE(escr.bet_amount, 2) as bet_amount'),
                        DB::raw('DATE_ADD(last_bet_time, INTERVAL 8 HOUR) as last_bet_time')
                    )
                    ->get();

                //名字亂數用*號取代
                $ranking = 1;
                if (count($data) > 0) {
                    foreach ($data as $key => $row) {
                        if ($row->member_id != $request->ID) {
                            $rand = floor((strlen($row->username) - 2) / 2); //中間為*
                            //$rand = rand(0, strlen($row->username) - 2);  //亂數為*
                            $row->username = substr($row->username, 0, $rand) . '**' . substr($row->username, ($rand + 2));
                        }
                        $result[] = array(
                            'ranking' => $ranking++,
                            'username' => $row->username,
                            'bet_amount' => $row->bet_amount,
                            'last_bet_time' => $row->last_bet_time,
                        );
                    }
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $result
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
     * event/slot_competition/ranking
     * - 我的排名
     *
     * @bodyParam no integer 期數 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "event_id": "events.id, ex:1",
     *            "no": "老虎機爭霸賽期數, ex:1",
     *            "bet_amount": "投注金額,ex:100.00",
     *            "ranking": "排名,ex:1",
     *            "point": "獎金,ex:15888.00",
     *            "type": "中獎狀態;0:未中獎,1:已中獎,ex:0",
     *            "status": "狀態;0:未領取,1:已領取,ex:0",
     *            "draw_time": "派彩時間,ex:2022-05-31 00:00:00"
     *            "start_time": "活動開始時間,ex:2022-05-31 00:00:00"
     *            "end_time": "活動結束時間,ex:2022-05-31 00:00:00"
     *            "last_bet_time": "最後投注時間,ex:2022-05-31 00:00:00"
     *        }
     *    ]
     * }
     */
    public function ranking(Request $request)
    {
        try {
            $data = DB::table('event_slot_competitions as esc')
                ->join('event_details as ed', function ($join) {
                    $join->on('ed.id', 'esc.event_detail_id')
                        ->where('ed.status', 1)
                        ->where('ed.action_id', 8);
                })
                ->leftJoin('event_slot_competition_records as escr', function ($join) use ($request) {
                    $join->on('escr.event_slot_competition_id', 'esc.id')
                        ->where('escr.member_id', $request->ID);
                })
                ->where('esc.event_id', $request->event_id)
                ->where('esc.draw_time', '<=', date('Y-m-d H:i:s'))
                ->orderBy('esc.no', 'DESC')
                ->select(
                    'ed.event_id',
                    'esc.no',
                    DB::raw('DATE_ADD(esc.draw_time, INTERVAL 8 HOUR) as draw_time'),
                    DB::raw('DATE_ADD(ed.start_time, INTERVAL 8 HOUR) as start_time'),
                    DB::raw('DATE_ADD(ed.end_time, INTERVAL 8 HOUR) as end_time'),
                    'escr.bet_amount',
                    'escr.ranking',
                    'escr.type',
                    'escr.status',
                    DB::raw('DATE_ADD(escr.last_bet_time, INTERVAL 8 HOUR) as last_bet_time'),
                    DB::raw('CASE WHEN escr.type = 1 THEN escr.point ELSE null END AS point')
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
     * event/slot_competition/receive
     * - 领取奖项
     *
     * @bodyParam event_id integer required events.id Example: 1
     * @bodyParam no integer required 老虎機爭霸賽期數 Example: 1
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
        $type = 101;
        $begin_transaction = false;

        try {
            $model = new EventSlotCompetitionRecord();
            $validator = Validator::make($request->all(), $model->rules_receive, $this->messages());

            if (!$validator->passes()) {
                $code = 2002;
                $message = $validator->errors()->all();
            } elseif ($this->checkMemberEventLockByMemberId($request->ID) > 0) {
                //鎖分模式中禁止領取獎勵
                $code = 1934;
                $message = __('error_message.1934');
            } else {
                //抽獎券檢查
                $ticket = DB::table('event_slot_competitions as esc')
                    ->join('events as e', function ($join) {
                        $join->on('e.id', 'esc.event_id')
                            ->where('e.status', 1);
                    })
                    ->join('event_details as ed', function ($join) {
                        $join->on('ed.id', 'esc.event_detail_id')
                            ->on('ed.event_id', 'e.id')
                            ->where('ed.status', 1);
                    })
                    ->join('prizes as p', function ($join) {
                        $join->on('p.event_id', 'e.id')
                            ->where('p.status', 1);
                    })
                    ->join('event_slot_competition_records as escr', function ($join) use ($request) {
                        $join->on('escr.event_slot_competition_id', 'esc.id')
                            ->where('escr.member_id', $request->ID)
                            ->where('escr.type', 1); //已中獎
                    })
                    ->where('esc.event_id', $request->event_id)
                    ->where('esc.no', $request->no)
                    ->where('esc.status', 1)
                    ->select(
                        'e.id as event_id',
                        'esc.id as event_slot_competition_id',
                        'escr.id as event_slot_competition_record_id',
                        'escr.type',
                        'escr.point',
                        'escr.status',
                        'escr.prize_content_id',
                        'p.id as prize_id',
                        'p.flow_limit_multiple'
                    )
                    ->first();

                if (!is_object($ticket) || ($ticket->prize_content_id == 0 && $ticket->type == 0)) {
                    //未中獎
                    $code = 1946;
                    $message = __('error_message.1946');
                } else if ($ticket->status == 1) {
                    //已領取
                    $code = 1947;
                    $message = __('error_message.1947');
                } else {
                    DB::beginTransaction();
                    $begin_transaction = true;
                    $member = Member::lockForUpdate()
                        ->where('id', $request->ID)
                        ->first();
                    $before_balance = $member->balance;
                    $balance = ($member->balance + $ticket->point);

                    EventSlotCompetitionRecord::find($ticket->event_slot_competition_record_id)
                        ->update(
                            [
                                'status' => 1,
                                'time' => date('Y-m-d H:i:s')
                            ]
                        );

                    //已領取
                    $prize_member = array();
                    $prize_member['member_id'] = $member->id;
                    $prize_member['prize_id'] = $ticket->prize_id;
                    $prize_member['content_id'] = $ticket->prize_content_id;
                    $prize_member['lottery_point'] = $ticket->point;
                    $prize_member['status'] = 1;
                    $prize_member['admin'] = 0;
                    PrizeMember::create($prize_member);

                    $member->update([
                        'balance' => $balance
                    ]);

                    //寫入transation_log
                    $log = array();
                    $log['agent_id'] = $member->parent;
                    $log['member_id'] = $member->id;
                    $log['order_id'] = $ticket->event_slot_competition_record_id;
                    $log['type'] = $type;
                    $log['point'] = $ticket->point;
                    $log['before_balance'] = $before_balance;
                    $log['balance'] = $balance;
                    $log['status'] = 1000;
                    $log['remark'] = '';
                    $log['operator'] = '';
                    $logRecord = app('App\Http\Controllers\TransactionLogController')
                        ->insertLog($log);

                    //增加稽核流水log
                    if (floatval($ticket->flow_limit_multiple) > 0) {
                        $flow = array();
                        $flow['member_id'] = $member->id;
                        $flow['type'] = $type;
                        $flow['event_id'] = $ticket->event_id;
                        $flow['reference_id'] = $logRecord->id;
                        $flow['flow_limit'] = strval($ticket->point * floatval($ticket->flow_limit_multiple));
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

        if ($begin_transaction == true) {
            if ($code != 1000) {
                DB::rollback();
            } else {
                DB::commit();
            }
        }

        return response()->json([
            'code' => $code,
            'message' => $message
        ]);
    }

    /**
     * event/slot_competition/awards
     * - 奖项
     *
     * @bodyParam event_id integer required events.id Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     *  "data": [
     *        {
     *            "event_id": "events.id, ex:1",
     *            "name": "獎項內容, ex:頭獎",
     *            "level": "獎項順序,ex:1",
     *            "number": "派發人數,ex:1",
     *            "point": "獎金,ex:15888.00"
     *        }
     *    ]
     * }
     */
    public function awards(Request $request)
    {
        try {
            $data = DB::table('events as e')
                ->join('event_details as ed', function ($join) {
                    $join->on('ed.event_id', 'e.id')
                        ->where('ed.status', 1)
                        ->where('ed.action_id', 8)
                        ->where('ed.start_time', '<=', date('Y-m-d H:i:s'))
                        ->where('ed.end_time', '>=', date('Y-m-d H:i:s'));
                })
                ->join('prizes as p', function ($join) use ($request) {
                    $join->on('p.event_id', 'e.id')
                        ->where('p.status', 1);
                })
                ->join('prize_contents as pc', function ($join) use ($request) {
                    $join->on('pc.prize_id', 'p.id')
                        ->where('pc.status', 1);
                })
                ->leftJoin('prize_content_names as pcn', function ($join) use ($request) {
                    $join->on('pcn.prize_content_id', 'pc.id')
                        ->where('pcn.language_id', $request->LANGUAGE);
                })
                ->where('e.id', $request->event_id)
                ->where('e.status', 1)
                ->orderBy('pc.level', 'ASC')
                ->select(
                    'e.id as event_id',
                    DB::raw('IFNULL(pcn.name, pc.name) AS name'),
                    'pc.level',
                    'pc.number',
                    'pc.point'
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

    private function messages()
    {
        return [
            'event_id.required' => __('error_message.9997'),
            'event_id.digits_between' => __('error_message.9997'),
            'no.required' => __('error_message.9997'),
            'no.digits_between' => __('error_message.9997')
        ];
    }
}