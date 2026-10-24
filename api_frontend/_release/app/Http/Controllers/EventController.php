<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\SQLHelper;
use App\Helpers\Common;
use App\Models\Event;
use App\Models\EventName;
use App\Models\EventDetail;
use App\Models\TransactionLog;
use App\Models\Member;
use App\Models\LoginEvent;
use App\Models\LoginEventName;
use App\Models\PrizeContent;
use App\Models\EventProcess;
use App\Models\EventCode;
use App\Enums\EventEnum;
use App\Enums\PrizeEnum;
use App\Models\PrizeMember;
use DB;
use App\REDIS;
use Validator;

class EventController extends Controller
{

    /**
     * events
     * - 活動列表
     *
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": 1,
     *            "title": "活動標題,ex:儲值滿額送",
     *            "content": "活動內容,ex:端午假期。。。",
     *            "type_id": "類型編號(1:活動,2:任務),ex:1",
     *            "agent_id": "營運商系統編號,ex:1",
     *            "prize_id": "獎勵活動編號：ex:1",
     *            "prize_number": "取得獎勵數量,ex:1",
     *            "prize_is_repeatedly": "可否重覆領取0:不可,1:可以,2:每日可重覆,ex:1",
     *            "prize_expire_date": "獎勵兌換到期日,ex:0000-00-00 00:00:00",
     *            "prize_draw_date": "開獎日期,ex:0000-00-00 00:00:00",
     *            "count": "可抽獎次數,ex:10",
     *            "type": "遊戲(1:刮刮卡Scratch,2:輪盤Roulete,3:直接兌獎,4:抽獎券),ex:1",
     *            "start_time": "活動開始時間，ex:2019-06-14 00:00:00",
     *            "end_time": "活動結束時間，ex:2019-07-11 23:59:59",
     *            "show_exchange": "兌換按鈕0:不顯示1:顯示,ex:1",
     *            "show_exchange_text": "兌換按鈕文字0:不顯示1:顯示,ex:1",
     *            "remaining_number": "可兌換獎勵的剩餘次數ex:5",
     *            "reached_today": "今日是否達成ex:0",
     *            "detail(需滿足的條件)": [
     *                {
     *                    "id": 1,
     *                    "action_id": "條件類型,ex:1",
     *                    "order_type_id": null,
     *                    "order_type_name": null,
     *                    "vendor_id": null,
     *                    "vendor_name": null,
     *                    "game_id": null,
     *                    "game_type_id": null,
     *                    "game_type_name": null,
     *                    "rate": "0.00",
     *                    "point": 100,
     *                    "start_time": "2019-06-14 00:00:00",
     *                    "end_time": "2019-07-11 23:59:59",
     *                    "now_point":"目前進度,ex:50",
     *                    "action_name":"指定動作文字,ex:首次儲值"
     *                },
     *                {
     *                    "id": 2,
     *                    "action_id": 2,
     *                    "order_type_id": null,
     *                    "order_type_name": null,
     *                    "vendor_id": null,
     *                    "vendor_name": null,
     *                    "game_type_id": null,
     *                    "game_type_name": null,
     *                    "game_id": null,
     *                    "rate": "0.00",
     *                    "point": 500,
     *                    "start_time": "2019-06-14 00:00:00",
     *                    "end_time": "2019-07-15 23:59:59",
     *                    "now_point":50,
     *                    "action_name":"贏得金額"
     *                }
     *            ]
     *        }
     *    ],
     *  "is_reddeem":"是否可兌換獎勵，0:不可兌換1:可兌換",
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new Event();
            $now = date('Y-m-d H:i:s');
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            // 取自己的營運商
            $member = Member::find($request->ID);
            // 取自己營運商、營運商上線及平台的活動
            $parents = $this->listParent($member->parent);
            $event = DB::table('events as a')
                ->join('event_details as ed', function ($join) {
                    $join->on('ed.event_id', 'a.id')
                        ->where('ed.status', 1)
                        ->whereNotIn('ed.action_id', [8]);
                })
                ->leftJoin('prizes as p', 'p.event_id', 'a.id')
                ->leftJoin('prize_members as m', function ($join) use ($request) {
                    $join->on('m.prize_id', 'p.id')
                        ->where('m.member_id', $request->ID);
                })
                ->leftJoin('event_names as n', function ($join) use ($request) {
                    $join->on('n.event_id', 'a.id')
                        ->where('n.language_id', $request->LANGUAGE);
                })
                ->where(function ($query) use ($parents) {
                    $query->whereNull('agent_id')
                        ->orWhereIn('agent_id', $parents);
                })
                ->where('p.expire_date', '>', date('Y-m-d H:i:s'))
                ->where('a.status', 1)
                ->groupBy('a.id')
                ->orderBy('a.created_at', 'desc')
                ->orderBy('a.id', 'desc')
                ->select(
                    'a.id',
                    DB::raw("(CASE WHEN n.title is NULL THEN a.title ELSE n.title END) as title"),
                    DB::raw("(CASE WHEN n.content is NULL THEN a.content ELSE n.content END) as content"),
                    'a.type_id',
                    'a.agent_id',
                    'p.id as prize_id',
                    'p.number as prize_number',
                    'p.is_repeatedly as prize_is_repeatedly',
                    DB::raw('DATE_ADD(p.expire_date,INTERVAL 8 HOUR) as prize_expire_date'),
                    DB::raw('DATE_ADD(p.expire_date,INTERVAL 8 HOUR) as expire_date'),
                    DB::raw('DATE_ADD(p.draw_date,INTERVAL 8 HOUR) as prize_draw_date'),
                    DB::raw("count(m.id) as count"),
                    'p.type_id as type',
                    'ed.action_id as action_id'
                )
                //->skip(($request->page - 1) * $request->per_page)
                //->take($request->per_page)
                ->get();
            $total = count($event);
            $result = array();
            if (is_object($event)) {
                $is_redeem = 0; //是否有可兌獎的活動
                foreach ($event as $row) {
                    $show_exchange = 0;
                    $sort = 2;
                    $text = __('param.prize_button.exchange');
                    $prize = DB::table('prizes as p')
                        ->leftJoin('prize_members as m', function ($join) use ($request) {
                            $join->on('m.prize_id', 'p.id')
                                ->where('m.member_id', $request->ID);
                        })
                        ->where('p.status', 1)
                        ->where('p.event_id', $row->id)
                        ->select(
                            'p.id',
                            'p.expire_date',
                            DB::raw("SUM((CASE WHEN m.STATUS=0 THEN 1 ELSE 0 END)) as exchange"),
                            DB::raw("SUM((CASE WHEN m.STATUS=1 THEN 1 ELSE 0 END)) as exchanged"),
                            'p.is_repeatedly'
                        )
                        ->first();
                    if ($row->action_id == 55) {
                        $prize_member = DB::table('prize_members')
                            ->where('prize_id', $prize->id)
                            ->where('member_id', $request->ID)
                            ->whereRaw('DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) = DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR))')
                            ->first();
                        $row->reached_today = (is_object($prize_member)) ? 1 : 0;
                    }

                    if ($prize->expire_date < date('Y-m-d H:i:s') && $prize->expire_date != '') {
                        // 已過期
                        $sort = 4;
                        $text = __('param.prize_button.expired');
                    } elseif ($prize->exchange > 0) {
                        // 可兌獎
                        $sort = 1;
                        $show_exchange = 1;
                    } elseif ($prize->exchanged > 0 && $prize->is_repeatedly == 0) {
                        // 全部已兌獎
                        $sort = 3;
                        $text = __('param.prize_button.exchanged');
                    }
                    $row->show_exchange = $show_exchange;
                    $row->show_exchange_text = $text;
                    $row->remaining_number = $prize->exchange ? (int) $prize->exchange : 0;
                    $start_time = '';
                    $end_time = '';
                    $mDetails = DB::table('event_details as d')
                        ->leftJoin('event_processes as p', function ($join) use ($request) {
                            $join->on('p.detail_id', 'd.id')
                                ->where('p.member_id', $request->ID);
                        })
                        ->leftJoin('order_type_names as otn', function ($join) use ($request) {
                            $join->on('otn.type_id', 'd.order_type_id')
                                ->where('otn.language_id', $request->LANGUAGE);
                        })
                        ->leftJoin('game_types as gt', function ($join) {
                            $join->on('gt.id', 'd.game_type_id');
                        })
                        ->leftJoin('game_type_names as gtn', function ($join) use ($request) {
                            $join->on('gtn.game_type_id', 'd.game_type_id')
                            ->where('gtn.language_id', $request->LANGUAGE);
                        });
                    $mDetails = ($row->type == 6)
                        ? $mDetails->leftJoin('w_jackpots as ld', 'ld.event_detail_id', 'd.id')
                        : $mDetails->leftJoin('lucky_draws as ld', 'ld.event_detail_id', 'd.id');

                    $mDetails = $mDetails->leftJoin('vendors as v', 'v.id', 'd.vendor_id')
                        ->where('d.event_id', $row->id)
                        ->where('d.status', 1);
                    $point = 'd.point';
                    if ($row->type == 5) {
                        //v-jackpot
                        $mDetails = $mDetails->where('d.start_time', '<=', date('Y-m-d H:i:s'))
                            ->where('d.end_time', '>', date('Y-m-d H:i:s'));
                        $point = DB::raw("CASE WHEN NOW() between '2020-11-10 16:00:00' and '2020-11-15 10:59:59' THEN 100 ELSE d.point END as point");
                    } elseif ($row->type == 6) {
                        //w-jackpot
                        $mDetails = $mDetails->where('d.start_time', '<=', date('Y-m-d H:i:s'))
                            ->where('d.end_time', '>', date('Y-m-d H:i:s'));
                    }
                    $details = $mDetails->select(
                        'd.id',
                        'd.action_id',
                        'd.order_type_id',
                        'otn.name as order_type_name',
                        'd.vendor_id',
                        'v.name as vendor_name',
                        'd.game_id',
                        'd.game_type_id',
                        DB::raw('IFNULL(gtn.name, gt.name) as game_type_name'),
                        'd.rate',
                        $point,
                        DB::raw('DATE_ADD(d.start_time,INTERVAL 8 HOUR) as start_time'),
                        DB::raw('DATE_ADD(d.end_time,INTERVAL 8 HOUR) as end_time'),
                        DB::raw("(CASE WHEN p.point is null THEN 0 ELSE (p.point-p.received) END) as now_point"),
                        DB::raw("IFNULL(ld.no, '') as lucky_draw_no"),
                        DB::raw('DATE_ADD(ld.draw_time,INTERVAL 8 HOUR) as prize_draw_date')
                    )
                        ->get();
                    if (count($details) > 0) {
                        // 查看活動結果
                        foreach ($details as $detail) {
                            //邀請玩家優惠
                            if ($detail->action_id == 31) {
                                $event_code = EventCode::where('event_id', $row->id)
                                    ->where('member_id', $request->ID)
                                    ->first();

                                if (!isset($event_code->event_id)) {
                                    $code = $this->eventCodeEncrypt($row->id, $request->ID);
                                    $event_code = EventCode::create(
                                        [
                                            'event_id' => $row->id,
                                            'member_id' => $request->ID,
                                            'code' => $code
                                        ]
                                    );
                                }
                                //IE+活動ID+玩家ID
                                $detail->code = $event_code->code;
                            }

                            $detail->action_name = __('param.event_action.' . $detail->action_id);
                            $detail->now_point = ($detail->now_point > 0) ? floor($detail->now_point) : 0;
                            //如果算完後進度不達標，狀態要改為不可兌換
                            if ($detail->now_point < $detail->point && $show_exchange == 1) {
                                $sort = 2;
                                $row->show_exchange = 0;
                            }

                            if ($detail->lucky_draw_no != '') {
                                if (strtotime($detail->start_time) <= strtotime($now.' 8hour')
                                    && strtotime($now.' 8hour') <= strtotime($detail->end_time)) {
                                    $row->title .= ' - ' . str_replace('{N}', $detail->lucky_draw_no, __('param.periods'));
                                    $row->prize_draw_date = $detail->prize_draw_date;
                                    $start_time = $detail->start_time;
                                    $end_time = $detail->end_time;
                                }
                            } else {
                                // 取得活動開始跟結束時間
                                if ($start_time == '' || $start_time > $detail->start_time) {
                                    $start_time = $detail->start_time;
                                }
                                if ($end_time == '' || $end_time < $detail->end_time) {
                                    $end_time = $detail->end_time;
                                }
                            }

                            $detail->vendor_maintenances = $this->getVendorMaintenance($detail->vendor_id, $member->id);
                        }

                        $row->start_time = $start_time;
                        $row->end_time = $end_time;

                        // 加上目前進度
                        $row->detail = $details;
                    }

                    //prize_content
                    $prize_content = PrizeContent::where('prize_id', $row->prize_id)
                        ->where('status', 1)
                        ->get();
                    $row->prize_content = $prize_content;

                    if ($show_exchange == 1) {
                        $is_redeem = 1;
                    }
                    $result[$sort][] = $row;
                }
            }

            // 活動排序為可兌換，不可兌換，已領取(TODO 近一個月)，已過期(TODO 近一個月)
            $data = array();
            ksort($result);
            foreach ($result as $row) {
                foreach ($row as $item) {
                    $data[] = $item;
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'is_reddeem' => $is_redeem,
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
     * event
     * - 活動詳情
     *
     * @bodyParam id integer required 活動系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *            "id": 1,
     *            "title": "活動標題,ex:儲值滿額送",
     *            "content": "活動內容,ex:端午假期。。。",
     *            "type_id": "類型編號(1:活動,2:任務),ex:1",
     *            "agent_id": "營運商系統編號,ex:1",
     *            "prize_id": "獎勵活動編號：ex:1",
     *            "prize_number": "取得獎勵數量,ex:1",
     *            "prize_is_repeatedly": "可否重覆領取0:不可, 1:可以, 2每日重覆領取,ex:1",
     *            "prize_expire_date": "獎勵兌換到期日,ex:0000-00-00 00:00:00",
     *            "prize_draw_date": "開獎日期,ex:0000-00-00 00:00:00",
     *            "count": "可抽獎次數,ex:10",
     *            "type": "遊戲(1:刮刮卡Scratch,2:輪盤Roulete,3:直接兌獎,4:抽獎券),ex:1",
     *            "start_time": "活動開始時間，ex:2019-06-14 00:00:00",
     *            "end_time": "活動結束時間，ex:2019-07-11 23:59:59",
     *            "show_exchange": "兌換按鈕0:不可按1:可按,ex:1",
     *            "show_exchange_text": "兌換按鈕文字,ex:立即兌換",
     *            "remaining_number": "可兌換獎勵的剩餘次數ex:5",
     *            "detail(需滿足的條件)": [
     *                {
     *                    "id": 1,
     *                    "action_id": "條件類型,ex:1",
     *                    "order_type_id": null,
     *                    "order_type_name": null,
     *                    "vendor_id": null,
     *                    "vendor_name": null,
     *                    "game_id": null,
     *                    "rate": "0.00",
     *                    "point": 100,
     *                    "start_time": "2019-06-14 00:00:00",
     *                    "end_time": "2019-07-11 23:59:59",
     *                    "now_point":"目前進度,ex:50",
     *                    "action_text":"指定動作文字,ex:首次儲值",
     *                    "games":[
     *                          {
     *                              "id": "系統編號,ex:1",
     *                              "vendor_id": "遊戲商編號,ex:1",
     *                              "vendor_name": "遊戲商名稱,ex:AG",
     *                              "type_id": "遊戲類型系統編號,ex:123456",
     *                              "type_name": "遊戲類型系統名稱,ex:slot",
     *                              "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:Diamond Queen",
     *                              "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
     *                              "image": "遊戲圖片,ex:http://xxx.xxx.xxx/xxx.jpg",
     *                              "is_new": "是否為新遊戲(0:否,1:是),1",
     *                              "is_hot": "是否為熱門遊戲(0:否,1:是),0",
     *                              "is_favourite": "是否為我的最愛(0:否,1:是),0"
     *                          }
     *                      ]
     *                }
     *            ],
     *            "prize(獎品列表)": [
     *                {
     *                    "id": 1,
     *                    "name": "獎項名稱,ex:紅利66",
     *                    "point": "獲得點數,ex:66",
     *                    "number": "獎項數量,ex:100"
     *                },
     *                {
     *                    "id": 2,
     *                    "name": "紅利888",
     *                    "point": 888,
     *                    "number": 10
     *                }
     *            ]
     *        }
     * }
     */
    public function view(Request $request)
    {
        try {
            $model = new Event();
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            $event = DB::table('events as a')
                ->leftJoin('prizes as p', 'p.event_id', 'a.id')
                ->leftJoin('prize_members as m', function ($join) use ($request) {
                    $join->on('m.prize_id', 'p.id')
                        ->where('m.status', 0)
                        ->where('m.member_id', $request->ID);
                })
                ->leftJoin('event_names as n', function ($join) use ($request) {
                    $join->on('n.event_id', 'a.id')
                        ->where('n.language_id', $request->LANGUAGE);
                })
                ->where('a.status', 1)
                ->where('a.id', $request->id)
                ->groupBy('a.id')
                ->select(
                    'a.id',
                    DB::raw("(CASE WHEN n.title is NULL THEN a.title ELSE n.title END) as title"),
                    DB::raw("(CASE WHEN n.content is NULL THEN a.content ELSE n.content END) as content"),
                    'a.type_id',
                    'a.agent_id',
                    'p.id as prize_id',
                    'p.number as prize_number',
                    'p.is_repeatedly as prize_is_repeatedly',
                    DB::raw('DATE_ADD(p.expire_date,INTERVAL 8 HOUR) as prize_expire_date'),
                    DB::raw('DATE_ADD(p.expire_date,INTERVAL 8 HOUR) as expire_date'),
                    DB::raw('DATE_ADD(p.draw_date,INTERVAL 8 HOUR) as prize_draw_date'),
                    DB::raw("count(m.id) as count"),
                    'p.type_id as type'
                )
                ->first();

            $data = array();
            if (is_object($event)) {
                // 活動條件
                $show_exchange = 0;
                $text = __('param.prize_button.exchange');
                $prize = DB::table('prizes as p')
                    ->leftJoin('prize_members as m', 'm.prize_id', 'p.id')
                    ->where('p.status', 1)
                    ->where('p.event_id', $event->id)
                    ->where('m.member_id', $request->ID)
                    ->select(
                        'p.expire_date',
                        DB::raw("SUM((CASE WHEN m.STATUS=0 THEN 1 ELSE 0 END)) as exchange"),
                        DB::raw("SUM((CASE WHEN m.STATUS=1 THEN 1 ELSE 0 END)) as exchanged")
                    )
                    ->first();
                if ($prize->expire_date < date('Y-m-d H:i:s') && $prize->expire_date != '') {
                    // 已過期
                    $text = __('param.prize_button.expired');
                } elseif ($prize->exchange > 0) {
                    // 可兌獎
                    $show_exchange = 1;
                } elseif ($prize->exchanged > 0) {
                    // 全部已兌獎
                    $text = __('param.prize_button.exchanged');
                }
                $event->show_exchange = $show_exchange;
                $event->show_exchange_text = $text;
                $event->remaining_number = $prize->exchange ? (int) $prize->exchange : 0;
                $start_time = '';
                $end_time = '';
                $mDetails = DB::table('event_details as d')
                    ->leftJoin('event_processes as p', function ($join) use ($request) {
                        $join->on('p.detail_id', 'd.id')
                            ->where('p.member_id', $request->ID);
                    })
                    ->leftJoin('order_type_names as on', function ($join) use ($request) {
                        $join->on('on.type_id', 'd.order_type_id')
                            ->where('on.language_id', $request->LANGUAGE);
                    })
                    ->leftJoin('game_types as gt', function ($join) {
                        $join->on('gt.id', 'd.game_type_id');
                    })
                    ->leftJoin('game_type_names as gtn', function ($join) use ($request) {
                        $join->on('gtn.game_type_id', 'd.game_type_id')
                            ->where('gtn.language_id', $request->LANGUAGE);
                    })
                    ->leftJoin('vendors as v', 'v.id', 'd.vendor_id')
                    ->where('d.event_id', $event->id)
                    ->where('d.status', 1)
                    ->select(
                        'd.id',
                        'd.action_id',
                        'd.order_type_id',
                        'on.name as order_type_name',
                        'd.vendor_id',
                        'v.name as vendor_name',
                        'd.game_id',
                        'd.game_type_id',
                        DB::raw('IFNULL(gtn.name, gt.name) as game_type_name'),
                        'd.rate',
                        'd.point',
                        DB::raw('DATE_ADD(d.start_time,INTERVAL 8 HOUR) as start_time'),
                        DB::raw('DATE_ADD(d.end_time,INTERVAL 8 HOUR) as end_time'),
                        DB::raw("(CASE WHEN p.point is null THEN 0 ELSE (p.point-p.received) END) as now_point")
                    );
                if ($event->type == 5) {
                    $mDetails->where('d.start_time', '<=', date('Y-m-d H:i:s'))
                        ->where('d.end_time', '>', date('Y-m-d H:i:s'));
                }
                $details = $mDetails->get();
                if (count($details) > 0) {
                    // 查看活動結果
                    foreach ($details as $detail) {
                        // 取得活動開始跟結束時間
                        if ($start_time == '' || $start_time > $detail->start_time) {
                            $start_time = $detail->start_time;
                        }
                        if ($end_time == '' || $end_time < $detail->end_time) {
                            $end_time = $detail->end_time;
                        }
                        $detail->action_name = __('param.event_action.' . $detail->action_id);
                        $detail->now_point = ($detail->now_point > 0) ? floor($detail->now_point) : 0;
                        //參與活動的遊戲列表
                        $model = $this->getGameByDetailId($request->ID, $detail->id, $request->LANGUAGE);
                        if (is_object($model)) {
                            $detail->games = $model->get();
                        }

                        $detail->vendor_maintenances = $this->getVendorMaintenance($detail->vendor_id, $request->ID);
                    }
                    $event->start_time = $start_time;
                    $event->end_time = $end_time;
                    // 加上目前進度
                    $event->detail = $details;
                    // 拿掉小數
                }
                // 獎項列表
                $prizes = DB::table('prize_contents as pc')
                    ->join('prizes as p', 'p.id', 'pc.prize_id')
                    ->join('events as e', function ($join) use ($event) {
                        $join->on('e.id', 'p.event_id')
                            ->where('e.id', $event->id);
                    })
                    ->leftJoin('prize_content_names as pcn', function ($join) use ($request) {
                        $join->on('pcn.prize_content_id', 'pc.id')
                            ->where('pcn.language_id', $request->LANGUAGE);
                    })
                    ->where('pc.status', 1)
                    ->orderBy('pc.id', 'ASC')
                    ->select(
                        'pc.id',
                        DB::raw('IFNULL(pcn.name, pc.name) as name'),
                        'pc.type',
                        'pc.point',
                        'pc.point_percentage',
                        'pc.number'
                    )
                    ->get();
                if (count($prizes) > 0) {
                    $event->prize = $prizes;
                }
                $data[] = $event;
            }
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
     * event/games
     * - 參與活動的遊戲列表
     *
     * @bodyParam detail_id integer 活動明細編號 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "vendor_id": "遊戲商編號,ex:1",
     *            "vendor_name": "遊戲商名稱,ex:AG",
     *            "type_id": "遊戲類型系統編號,ex:123456",
     *            "type_name": "遊戲類型系統編號,ex:slot",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:Diamond Queen",
     *            "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
     *            "image": "遊戲圖片,ex:http://xxx.xxx.xxx/xxx.jpg",
     *            "is_new": "是否為新遊戲(0:否,1:是),1",
     *            "is_hot": "是否為熱門遊戲(0:否,1:是),0",
     *            "is_favourite": "是否為我的最愛(0:否,1:是),0"
     *          },
     *          {
     *            "id": 2,
     *            "vendor_id": 1,
     *            "vendor_name": "Joker",
     *            "type_id": "1",
     *            "name": "Alibaba",
     *            "lottery_pool": "123456",
     *            "image": "http://xxx.xxx.xxx/xxx.jpg",
     *            "is_new": 0,
     *            "is_hot": 1,
     *            "is_favourite": 1
     *          }
     *    ],
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     */
    public function games(Request $request)
    {
        try {
            $model = new EventDetail();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'game',
                'list',
                $request->ID,
                $request->LANGUAGE,
                $request->detail_id,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'game',
                'list',
                $request->ID,
                $request->LANGUAGE,
                $request->detail_id,
                'total'
            );
            $redis = new REDIS();
            //因為活動沒有使用REDIS，所以過期的會自動隱藏了，遊戲列表判不判斷時間都無所謂
            if (!$redis->exists($key)) {
                $model = $this->getGameByDetailId($request->ID, $request->detail_id, $request->LANGUAGE);
                if (is_object($model)) {
                    $total = $model->count();
                    $data = $model->orderBy('f.status', 'desc')
                        ->orderBy('a.sort', 'desc')
                        ->orderBy('a.id', 'desc')
                        ->skip(($request->page - 1) * $request->per_page)
                        ->take($request->per_page)
                        ->get();
                    $redis->set($key, $data);
                    $redis->set($total_key, $total);
                } else {
                    return response()->json($model);
                }
            } else {
                $data = $redis->get($key);
                $total = $redis->get($total_key);
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
     * event/vendorGames
     * - 活動列表
     *
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": 1,
     *            "title": "活動標題,ex:儲值滿額送",
     *            "games":[
     *                {
     *                  "id": "系統編號,ex:1",
     *                  "vendor_id": "遊戲商編號,ex:1",
     *                  "vendor_name": "遊戲商名稱,ex:AG",
     *                  "type_id": "遊戲類型系統編號,ex:123456",
     *                  "type_name": "遊戲類型名稱,ex:slot",
     *                  "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:Diamond Queen",
     *                  "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
     *                  "image": "遊戲圖片,ex:http://xxx.xxx.xxx/xxx.jpg",
     *                  "is_new": "是否為新遊戲(0:否,1:是),1",
     *                  "is_hot": "是否為熱門遊戲(0:否,1:是),0",
     *                  "is_favourite": "是否為我的最愛(0:否,1:是),0"
     *                }
     *            ]
     *        }
     *    ]
     * }
     */
    public function gamesByVendor(Request $request)
    {
        try {
            $model = new Event();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'event',
                'list',
                $request->ID,
                $request->LANGUAGE,
                $request->vendor_id,
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                // 取自己的營運商
                $member = Member::find($request->ID);
                $parents = $this->listParent($member->parent);
                $vendors = $this->vendorList($request->ID);
                if (in_array($request->vendor_id, $vendors)) {
                    $events = DB::table('events as e')
                        ->join('event_details as d', function ($join) use ($request) {
                            $join->on('d.event_id', 'e.id')
                                ->where('d.status', 1)
                                ->where('d.start_time', '<', date('Y-m-d H:i:s'))
                                ->where('d.end_time', '>', date('Y-m-d H:i:s'))
                                ->where('d.vendor_id', $request->vendor_id);
                        })
                        ->leftJoin('event_names as n', function ($join) use ($request) {
                            $join->on('n.event_id', 'e.id')
                                ->where('n.language_id', $request->LANGUAGE);
                        })
                        ->where(function ($query) use ($parents) {
                            $query->whereIn('e.agent_id', $parents)
                                ->orWhere('e.agent_id', 0)
                                ->orWhereNull('e.agent_id');
                        })
                        ->where('e.status', 1)
                        ->select(
                            'e.id',
                            DB::raw("(CASE WHEN n.title is NULL THEN e.title ELSE n.title END) as title"),
                            'd.id as detail_id'
                        )
                        ->get();
                    $data = array();
                    foreach ($events as $row) {
                        //參與活動的遊戲列表
                        $model = $this->getGameByDetailId($request->ID, $row->detail_id, $request->LANGUAGE);
                        if (is_object($model)) {
                            $row->games = $model->get();
                        }
                        $data[] = $row;
                    }
                    $redis->set($key, $data);
                } else {
                    //沒有此遊戲商的權限
                    return response()->json([
                        'code' => 1426,
                        'message' => __('error_message.1426'),
                    ]);
                }
            } else {
                $data = $redis->get($key);
            }
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

    public function getGameByDetailId($memberId, $detailId, $language)
    {
        $model = new EventDetail();
        $detail = $model->where('id', $detailId)
            ->where('vendor_id', '>', 0)
            ->where('status', 1)
            ->first();
        if (is_object($detail)) {
            //查詢這個會員可以玩的遊戲商
            $vendors = $this->vendorList($memberId);
            //如果有設定CODE代表有錯誤，就直接拋錯誤訊息
            if (!isset($vendors['code'])) {
                //判斷這活動的遊戲商是否在會員可玩列表中
                if (in_array($detail->vendor_id, $vendors)) {
                    //查vendor_info_ids
                    $vendor_info_ids = $this->vendorList($memberId, 'a.vendor_info_id', $detail->vendor_id);

                    //查agent_games
                    $agent_game_lists = $this->agentGameList($memberId, $detail->vendor_id);
                    $data = DB::table('games as g')
                        ->join('vendor_info_games as vig', function ($join) use ($detail, $vendor_info_ids) {
                            $join->on('vig.game_id', 'g.id')
                                ->where('vig.status', 1)
                                ->whereIn('vig.vendor_info_id', $vendor_info_ids);
                            // 判斷遊戲是否可以參加這個類型的活動，2:投注，3:贏分
                            if ($detail->action_id == 2) {
                                $join->where('vig.join_bet_event', 1);
                            } elseif ($detail->action_id == 3) {
                                $join->where('vig.join_win_event', 1);
                            } else {
                                //查無此活動
                                return response()->json([
                                    'code' => 19013,
                                    'message' => __('error_message.1901'),
                                ]);
                            }
                        })
                        ->leftJoin('game_names as gn', function ($join) use ($language) {
                            $join->on('gn.game_id', 'g.id')
                                ->where('gn.language_id', $language);
                        })
                        ->join('vendors as v', 'v.id', 'g.vendor_id')
                        ->leftJoin('favourite_games as f', function ($join) use ($memberId) {
                            $join->on('f.game_id', 'g.id')
                                ->where('f.member_id', $memberId)
                                ->where('f.status', 1);
                        })
                        ->leftJoin('game_types as t', 't.id', 'g.type_id')
                        ->leftJoin('game_type_names as tn', function ($join) use ($language) {
                            $join->on('t.id', 'tn.game_type_id')
                                ->where('tn.language_id', $language);
                        });
                    if ($detail->game_type_id > 0) {
                        $data = $data->where('g.type_id', $detail->game_type_id);
                    }

                    $data = $data->whereIn('g.id', $agent_game_lists)
                        ->select(
                            'g.id',
                            'g.vendor_id',
                            'v.name as vendor_name',
                            'g.type_id',
                            DB::raw('IFNULL(tn.name, t.name) AS type_name'),
                            DB::raw('IFNULL(gn.name, g.name) as name'),
                            'g.lottery_pool',
                            'g.image',
                            'vig.is_new',
                            'vig.is_hot',
                            DB::raw("(CASE WHEN f.status is NULL THEN 0 ELSE 1 END) as is_favourite")
                        );
                    return $data;
                } else {
                    //查無此活動
                    return [
                        'code' => 19012,
                        'message' => __('error_message.1901'),
                    ];
                }
            } else {
                return $vendors;
            }
        } else {
            //此活動明細不存在
            return [
                'code' => 19011,
                'message' => __('error_message.1901'),
            ];
        }
    }

    /**
     * getLoginEvent
     * -取目前開放的登入活動
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *      "id": "系統編號，ex:1",
     *      "type": "類型1:活動開始後創立之新帳號，2:舊帳號, 且 [how_days] 日內無開洗分紀錄",
     *      "how_days": "幾日內無開洗分記錄，ex:30",
     *      "point": "贈與點數，ex:20",
     *      "times": "餘額為賺與點數的幾倍可以達成解鎖條件，ex:2020-03-15 21:53:07",
     *      "is_full": "是否還有活動參加名額0:尚未額滿、1:已額滿，ex:1",
     *      "start_time": "活動開始時間，ex:2020-03-15 21:53:07",
     *      "end_time": "活動結束時間，ex:2020-03-27 21:53:11",
     *      "repeat_hour": "重複參與時間間隔(最少1小時，0代表不可重複參加)，ex:1"
     *      }
     * }
     * @response {
     *  "code": "error code",
     *  "message": "error message"
     * }
     */
    public function getLoginEvent(Request $request)
    {
        try {
            $member = Member::find($request->ID); //取玩家資料
            if (is_object($member)) {
                $parents = $this->listParent($member->parent); //取上線及自已的AGENT ID
                $event = DB::table('login_events as le')
                    ->leftJoin('login_event_names as len', function ($join) use ($request) {
                        $join->on('len.login_event_id', 'le.id')
                            ->where('len.language_id', $request->LANGUAGE);
                    })
                    ->where('le.status', 1)
                    ->where('le.start_time', '<=', date('Y-m-d H:i:s'))
                    ->where('le.end_time', '>', date('Y-m-d H:i:s'))
                    ->where(function ($query) use ($parents) {
                        //只能取自己營運商、營運商上線及平台的活動
                        $query->where('le.agent_id', 0)
                            ->orWhereIn('le.agent_id', $parents);
                    })
                    ->select(
                        DB::raw('IFNULL(len.title, le.title) AS title'),
                        DB::raw('IFNULL(len.content, le.content) AS content'),
                        'le.id',
                        'le.type',
                        'le.how_days',
                        'le.point',
                        'le.times',
                        DB::raw('CASE WHEN (le.number_of_people > le.number_of_participants OR le.number_of_people=0) THEN 0 ELSE 1 END AS is_full'),
                        DB::raw('DATE_ADD(le.start_time,INTERVAL 8 HOUR) AS start_time'),
                        DB::raw('DATE_ADD(le.end_time,INTERVAL 8 HOUR) AS end_time'),
                        'le.repeat_hour'
                    )
                    ->first();
                if (is_object($event)) {
                    return [
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $event
                    ];
                } else {
                    // 活動不存在
                    return [
                        'code' => 1901,
                        'message' => __('error_message.1901')
                    ];
                }
            } else {
                // 玩家不存在
                return [
                    'code' => 1005,
                    'message' => __('error_message.1005')
                ];
            }
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
     * checkLoginEvent
     * -確認是否可以參加登入活動(code=1000以外都不能參加)
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *      "type": "類型1:活動開始後創立之新帳號，2:舊帳號, 且 [how_days] 日內無開洗分紀錄",
     *      "how_days": "幾日內無開洗分記錄，ex:30",
     *      "point": "贈與點數，ex:20",
     *      "times": "餘額為賺與點數的幾倍可以達成解鎖條件，ex:2020-03-15 21:53:07",
     *      "is_full": "是否還有活動參加名額0:尚未額滿、1:已額滿，ex:1",
     *      "start_time": "活動開始時間，ex:2020-03-15 21:53:07",
     *      "end_time": "活動結束時間，ex:2020-03-27 21:53:11",
     *      "repeat_hour": "重複參與時間間隔(最少1小時，0代表不可重複參加)，ex:1"
     *      }
     * }
     * @response {
     *  "code": "error code",
     *  "message": "error message"
     * }
     */
    public function checkLoginEvent(Request $request)
    {
        try {
            //確認玩家是否存在以及不在鎖分模式中, 在鎖分模試中就不需要去確認了
            if ($this->checkMemberEventLockByMemberId($request->ID) > 0) {
                //玩家在鎖分模式中
                return response()->json([
                    'code' => 1934,
                    'message' => __('error_message.1934')
                ]);
            } else {
                //驗證是否符合參加活動的條件(帳號內殘點(含所有遊戲)總和為0)
                $result = $this->checkLoginEventQualifications($request, false);
                return response()->json($result);
            }
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
     * joinLoginEvent
     * -參加登入活動
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *      "type": "類型1:活動開始後創立之新帳號，2:舊帳號, 且 [how_days] 日內無開洗分紀錄",
     *      "how_days": "幾日內無開洗分記錄，ex:30",
     *      "point": "贈與點數，ex:20",
     *      "times": "餘額為賺與點數的幾倍可以達成解鎖條件，ex:2020-03-15 21:53:07",
     *      "is_full": "是否還有活動參加名額0:尚未額滿、1:已額滿，ex:1",
     *      "start_time": "活動開始時間，ex:2020-03-15 21:53:07",
     *      "end_time": "活動結束時間，ex:2020-03-27 21:53:11",
     *      "repeat_hour": "重複參與時間間隔(最少1小時，0代表不可重複參加)，ex:1"
     *      }
     * }
     */
    public function joinLoginEvent(Request $request)
    {
        try {
            //驗證是否符合參加活動的條件(帳號內殘點(含所有遊戲)總和為0)，是就參加活動
            $result = $this->checkLoginEventQualifications($request, true);
            return response()->json($result);
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
     * unlockLoginEvent
     * -登入活動解鎖
     *
     * @bodyParam is_force boolean 是否強制解鎖(預設0)0:不強制，1:強制 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     * @response {
     *  "code": 10001,
     *  "message": "SUCCESS",
     *  "data": {
     *      "type": "類型1:活動開始後創立之新帳號，2:舊帳號, 且 [how_days] 日內無開洗分紀錄",
     *      "how_days": "幾日內無開洗分記錄，ex:30",
     *      "point": "贈與點數，ex:20",
     *      "times": "餘額為賺與點數的幾倍可以達成解鎖條件，ex:2020-03-15 21:53:07",
     *      "is_full": "是否還有活動參加名額0:尚未額滿、1:已額滿，ex:1",
     *      "start_time": "活動開始時間，ex:2020-03-15 21:53:07",
     *      "end_time": "活動結束時間，ex:2020-03-27 21:53:11",
     *      "repeat_hour": "重複參與時間間隔(最少1小時，0代表不可重複參加)，ex:1",
     *      "balance": "目前餘額，ex:30"
     *      }
     * }
     */
    public function unlockLoginEvent(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), ['is_force' => 'in:0,1'], $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            $member = Member::find($request->ID); //取玩家資料
            if (is_object($member)) {
                $event_mode = $this->checkMemberEventLockByMemberId($request->ID);
                if ($event_mode > 0) {
                    //取最後一筆參加活動的編號
                    $log = TransactionLog::where('member_id', $member->id)
                        ->where('type', 15)
                        ->where('status', 1000)
                        ->orderBy('created_at', 'desc')
                        ->first();
                    if (is_object($log)) {
                        //查詢活動資料
                        $event = LoginEvent::where('id', $event_mode)
                            ->select(
                                'id',
                                'title',
                                'content',
                                'type',
                                'how_days',
                                'point',
                                'times',
                                DB::raw('CASE WHEN (number_of_people > number_of_participants or number_of_people = 0) THEN 0 ELSE 1 END as is_full'),
                                DB::raw('DATE_ADD(start_time,INTERVAL 8 HOUR) as start_time'),
                                DB::raw('DATE_ADD(end_time,INTERVAL 8 HOUR) as end_time'),'repeat_hour'
                            )
                            ->first();
                        if (is_object($event)) {
                            //驗證是否達成活動的條件
                            $total_balance = $member->balance; //總餘額(玩家平台餘額)
                            $all_balance = [];
                            //取參與活動的遊戲商餘額(有任何一間維護中就無法解鎖)(活動檢查不分手機版或PC版都要檢查)
                            $vendors = app('App\Http\Controllers\VendorController')
                                ->vendors($member->id, 1, null, $member->event_mode);
                            Common::log($member->id, $vendors, 'event_vendors');
                            if (is_array($vendors) && $vendors['code'] == 1000) {
                                foreach ($vendors['data'] as $vendor) {
                                    if ($vendor->status != 1) {
                                        // 部份遊戲商維護中，請稍後再試
                                        return response()->json([
                                            'code' => 1938,
                                            'message' => __('error_message.1938')
                                        ]);
                                    }
                                }

                                //都沒有維護才取遊戲商餘額
                                foreach ($vendors['data'] as $vendor) {
                                    if ($vendor->is_single_wallet == 0) {
                                        $balance = app('App\Http\Controllers\VendorController')
                                            ->getBalanceByVendorId($member->id, $vendor->id);
                                        Common::log($member->id, $balance, 'event_vendor_balance');
                                        if (!is_array($balance) || $balance['code'] != 1000) {
                                            //只要有一個沒取到不可解鎖
                                            return response()->json([
                                                'code' => 1936,
                                                'message' => __('error_message.1936')
                                            ]);
                                        } else {
                                            if ($balance['data'] > 1) {
                                                //計算總餘額(大於1才算，避免有些無法投注也無法轉出，導致無法解鎖)
                                                $total_balance += floor($balance['data']);
                                            }
                                            if ($balance['data'] > 0) {
                                                //記錄各遊戲商餘額
                                                //$all_balance[$vendor->id] = $balance['data'];
                                                $all_balance[$vendor->id] = floatval($balance['data']); //因為pt 的餘額返回為 .39 不是浮點數.
                                            }
                                        }
                                    }
                                }
                            } else {
                                //沒有可參與的遊戲商
                                return response()->json([
                                    'code' => 1936,
                                    'message' => __('error_message.1936')
                                ]);
                            }

                            //餘額達標、餘額歸0、回收餘額強制解鎖
                            if ($total_balance == 0 || $total_balance >= $event->times || $request->is_force) {
                                //餘額歸0(回收小數)、回收餘額強制解鎖
                                $member_update = [];
                                $remark = '登入活動已達標';
                                if ($total_balance == 0 || $request->is_force) {
                                    if ($total_balance == 0) {
                                        $remark = '登入活動已失敗(歸0)';
                                    } elseif ($request->is_force) {
                                        $remark = '登入活動已放棄';
                                    }
                                    foreach ($all_balance as $vendor_id => $point) {
                                        $result = app('App\Http\Controllers\VendorController')
                                            ->vendorTransfer($vendor_id, $member->id, $point, 2);
                                        if (!is_array($result) || $result['code'] != 1000) {
                                            //回收失敗，請稍後再試
                                            return response()->json([
                                                'code' => 2004,
                                                'message' => __('error_message.2004'),
                                            ]);
                                        }
                                    }
                                    DB::beginTransaction();
                                    $member = Member::lockForUpdate()->find($request->ID); //取玩家資料
                                    $before_balance = $member->balance;
                                    //餘額為0就不寫回收LOG
                                    // if ($member->balance > 0) {
                                    // 寫入回收LOG
                                    $refund_log = array();
                                    $refund_log['agent_id'] = $member->parent;
                                    $refund_log['member_id'] = $member->id;
                                    $refund_log['type'] = 16; //登入活動餘額回收
                                    $refund_log['point'] = $member->balance;
                                    $refund_log['before_balance'] = $before_balance;
                                    $refund_log['balance'] = 0;
                                    $refund_log['status'] = 1000;
                                    $refund_log['remark'] = '登入活動餘額回收';
                                    $refund_log['operator'] = $event->id; //event_id
                                    $refund_log['admin'] = $member->id;
                                    app('App\Http\Controllers\TransactionLogController')
                                        ->insertLog($refund_log);
                                    $member_update['balance'] = 0; //平台餘額歸0
                                }
                                $log->update(['remark' => $remark]); //更新一下解鎖時間
                                //解除活動模式
                                $member_update['event_mode'] = 0;
                                $result = $member->update($member_update);
                                if ($result) {
                                    DB::commit();
                                    $key = array(
                                        'member'
                                    );
                                    $redis = new REDIS();
                                    $redis->delete($key);
                                    //成功
                                    return response()->json([
                                        'code' => 1000,
                                        'message' => __('error_message.1000')
                                    ]);
                                } else {
                                    //更新失敗
                                    return response()->json([
                                        'code' => 2001,
                                        'message' => __('error_message.2001')
                                    ]);
                                }
                            } else {
                                $event->balance = $total_balance;
                                //不符合解鎖條件
                                return response()->json([
                                    'code' => 10001,
                                    'message' => __('error_message.1936'),
                                    'data' => $event
                                ]);
                            }
                        } else {
                            //查無此活動
                            return response()->json([
                                'code' => 1901,
                                'message' => __('error_message.1901')
                            ]);
                        }
                    } else {
                        //查無參加記錄
                        return response()->json([
                            'code' => 2004,
                            'message' => __('error_message.2004')
                        ]);
                    }
                } else {
                    //玩家不在鎖分模式中
                    return response()->json([
                        'code' => 1935,
                        'message' => __('error_message.1935')
                    ]);
                }
            } else {
                //玩家不存在
                return response()->json([
                    'code' => 1005,
                    'message' => __('error_message.1005')
                ]);
            }
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

    public function checkEventCode($code)
    {
        $result = DB::table('event_codes as ec')
            ->join('members as m', function ($join) {
                $join->on('m.id', 'ec.member_id')
                    ->where('m.status', 1);
            })
            ->where('ec.code', $code)
            ->select(
                'm.parent',
                'ec.event_id',
                'ec.member_id'
            )
            ->first();
        return $result;
    }

    public function getEvent($event_id, $agent, $check_number = true)
    {
        $result = DB::table('event_details as ed')
            ->join('events as e', function ($join) use ($event_id, $agent) {
                $join->on('e.id', 'ed.event_id')
                    ->where('e.status', 1)
                    ->where('e.id', $event_id)
                    ->whereIn('e.agent_id', explode('/', substr($agent->hierarchy_path, 1)));
            })
            ->join('prizes as p', function ($join) {
                $join->on('p.event_id', 'e.id')
                    ->where('p.status', 1);
            })
            ->join('prize_contents as pc', function ($join) use ($check_number) {
                $join->on('pc.prize_id', 'p.id')
                    ->where('pc.status', 1);
                if ($check_number == true) {
                    $join->where(function ($query) {
                        $query->where('pc.number', -1)
                            ->orWhereRaw('(pc.number - pc.distribute) > 0');
                    });
                }
            })
            ->where('ed.status', 1)
            ->select(
                'ed.id as event_detail_id',
                'ed.bind_unlimited as event_bind_unlimited',
                'p.id as prize_id',
                'pc.id as prize_content_id',
                'pc.name as prize_content_name',
                'pc.number as prize_content_number',
                'pc.sent as prize_content_sent',
                'pc.point as prize_content_point'
            )
            ->first();
        return $result;
    }

    public function updateEventProcess($event_detail_id, $member_id)
    {
        //寫入派獎紀錄; 派獎狀態0:未達標1:已達標2:已派獎
        $processes = EventProcess::where('detail_id', $event_detail_id)
            ->where('member_id', $member_id)
            ->first();
        $result = null;
        if (is_object($processes)) {
            $processes->point = $processes->point + 1;
            $result = $processes->save();
        } else {
            $data = array();
            $data['detail_id'] = $event_detail_id;
            $data['member_id'] = $member_id;
            $data['point'] = 1;
            $data['status'] = 0;
            $data['admin'] = 0;
            $result = EventProcess::create($data);
        }
        return $result;
    }

    private function checkLoginEventQualifications($request, $isJoin = false)
    {
        DB::beginTransaction();
        $memberId = $request->ID;
        $member = Member::lockForUpdate()->find($memberId); //取玩家資料
        if (is_object($member)) {
            $parents = $this->listParent($member->parent); //取上線及自已的AGENT ID

            $event = LoginEvent::lockForUpdate()
                ->where('status', 1)
                ->where('start_time', '<=', date('Y-m-d H:i:s'))
                ->where('end_time', '>', date('Y-m-d H:i:s'))
                ->where(function ($query) {
                    //參與人數無上限或著是參加人數小於可參與人數
                    $query->where('number_of_people', 0)
                        ->orWhere('number_of_people', '>', DB::raw('number_of_participants'));
                })
                ->where(function ($query) use ($parents) {
                    //只能取自己營運商、營運商上線及平台的活動
                    $query->where('agent_id', 0)
                        ->orWhereIn('agent_id', $parents);
                })
                ->select(
                    'title',
                    'content',
                    'id',
                    'type',
                    'how_days',
                    'point',
                    'times',
                    DB::raw('CASE WHEN (number_of_people > number_of_participants or number_of_people=0) THEN 0 ELSE 1 END as is_full'),
                    DB::raw('DATE_ADD(start_time,INTERVAL 8 HOUR) as start_time'),
                    DB::raw('DATE_ADD(end_time,INTERVAL 8 HOUR) as end_time'),
                    'repeat_hour',
                    'vendor_id'
                )
                ->first();
            if (is_object($event)) {
                $event_name = LoginEventName::where('login_event_id', $event->id)
                    ->where('language_id', $request->LANGUAGE)
                    ->first();
                if (is_object($event_name)) {
                    $event->title = ($event_name->title != '') ? $event_name->title : $event->title;
                    $event->content = ($event_name->content != '') ? $event_name->content : $event->content;
                }

                //看是否符合條件
                switch ($event->type) {
                    case 1:
                        //活動開始後創立之新帳號
                        if ($member->created_at < $event->start_time) {
                            DB::rollback();
                            return [
                                'code' => 19371,
                                'message' => __('error_message.1937')
                            ];
                        }
                        break;
                    case 2:
                        //近幾日內無開洗分的玩家(TYPE 3:線上儲值，4:開分，5:洗分)
                        if ($event->how_days > 0) {
                            $logs = TransactionLog::where('member_id', $memberId)
                                ->where('status', 1000)
                                ->whereIn('type', [3, 4, 5])
                                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-' . $event->how_days . 'day')))
                                ->get();
                            if (count($logs) > 0) {
                                DB::rollback();
                                return [
                                    'code' => 19372,
                                    'message' => __('error_message.1937')
                                ];
                            }
                        }
                        break;
                }

                //查詢上次參加的時間
                $logs = TransactionLog::where('member_id', $memberId)
                    ->where('type', 15)
                    ->where('status', 1000)
                    ->where('operator', $event->id)
                    ->orderBy('id', 'DESC')
                    ->first();
                if (is_object($logs)) {
                    if ($event->repeat_hour > 0) {
                        //可重覆參加的時間到了沒，查詢上次參加的時間
                        if ($logs->updated_at > date('Y-m-d H:i:s', strtotime('-' . $event->repeat_hour . 'hour'))) {
                            DB::rollback();
                            return [
                                'code' => 19373,
                                'message' => __('error_message.1937')
                            ];
                        }
                    } elseif ($event->repeat_hour == 0) {
                        DB::rollback();
                        //不可重覆參加
                        return [
                            'code' => 19374,
                            'message' => __('error_message.1937')
                        ];
                    }
                }

                //檢查玩家餘額有沒有小於1; 原本設定為 == 0
                if ($member->balance < 1) {
                    //取遊戲商確認狀態是否沒有在維護的(活動檢查不分手機版或PC版都要檢查)
                    $vendors = app('App\Http\Controllers\VendorController')
                        ->vendors($memberId, 1, null, $member->event_mode);
                    if (is_array($vendors) && $vendors['code'] == 1000) {
                        foreach ($vendors['data'] as $vendor) {
                            if ($vendor->status != 1) {
                                DB::rollback();
                                // 部份遊戲商維護中，請稍後再試
                                return [
                                    'code' => 1938,
                                    'message' => __('error_message.1938')
                                ];
                            }
                        }
                        $vendor_balance = 0;
                        //都沒有維護才取遊戲商餘額
                        foreach ($vendors['data'] as $vendor) {
                            $balance = app('App\Http\Controllers\VendorController')
                                ->getBalanceByVendorId($memberId, $vendor->id);
                            if (!is_array($balance) || $balance['code'] != 1000 || $balance['data'] > 1) {
                                DB::rollback();
                                //只要有一個沒取到或大於1就不可參加
                                return [
                                    'code' => 19374,
                                    'message' => __('error_message.1937')
                                ];
                            } elseif (is_array($balance) && $balance['code'] == 1000 && $balance['data'] > 0) {
                                $vendor_balance += $balance['data'];
                            }
                        }
                    } else {
                        DB::rollback();
                        //沒有可參與的遊戲商
                        return [
                            'code' => 1937,
                            'message' => __('error_message.1937')
                        ];
                    }

                    if ($isJoin) {
                        $event->increment('number_of_participants');
                        //參加活動，進入活動模式，贈送點數，寫LOG
                        $before_balance = $member->balance;
                        //$balance = $member->balance + $event->point;
                        $balance = $event->point;  //改為𦠇送金額(不含原始金額)
                        // 寫入轉點LOG
                        $log = array();
                        $log['agent_id'] = $member->parent;
                        $log['member_id'] = $member->id;
                        $log['type'] = 15; //登入活動獎勵
                        $log['point'] = $event->point;
                        $log['before_balance'] = $before_balance;
                        $log['balance'] = $balance;
                        $log['vendor_point'] = $vendor_balance; //參加時有參加活動的遊戲商內小數餘額加總
                        $log['status'] = 1000;
                        $log['remark'] = '登入活動';
                        $log['operator'] = $event->id; //event_id
                        $log['admin'] = $member->id;
                        app('App\Http\Controllers\TransactionLogController')
                            ->insertLog($log);
                        $result = $member->update(['event_mode' => $event->id, 'balance' => $balance]);
                        if ($result) {
                            DB::commit();
                            $key = array(
                                'member'
                            );
                            $redis = new REDIS();
                            $redis->delete($key);
                            return [
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => $event
                            ];
                        } else {
                            DB::rollback();
                            return [
                                'code' => 2001,
                                'message' => __('error_message.2001')
                            ];
                        }
                    } else {
                        DB::rollback();
                        return [
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => $event
                        ];
                    }
                } else {
                    DB::rollback();
                    // 不符合参加活动的条件
                    return [
                        'code' => 1937,
                        'message' => __('error_message.1937')
                    ];
                }
            } else {
                DB::rollback();
                // 活動不存在
                return [
                    'code' => 1901,
                    'message' => __('error_message.1901')
                ];
            }
        } else {
            DB::rollback();
            // 玩家不存在
            return [
                'code' => 1005,
                'message' => __('error_message.1005')
            ];
        }
    }

    private function getVendorMaintenance($vendor_id, $member_id)
    {
        $agents = $this->getAgentHierarchyPath($member_id);
        $result = DB::table('vendor_maintenances')
            ->where('vendor_id', $vendor_id)
            ->where('status', 1)
            ->where('start_time', '<=', date('Y-m-d H:i:s'))
            ->where('end_time', '>=', date('Y-m-d H:i:s'))
            ->whereIn('agent_id', $agents)
            ->whereNotIn('exclude_agent_id', $agents)
            ->get();
        return (count($result) > 0) ? 1 : 0;
    }

    private function messages()
    {
        return [
            'id.required' => __('error_message.1902'),
            'id.digits_between' => __('error_message.1903'),

            'detail_id.required' => __('error_message.1902'),
            'detail_id.digits_between' => __('error_message.1903'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),
            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}
