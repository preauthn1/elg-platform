<?php

namespace App\Http\Controllers;

use App\Helpers\SQLHelper;
use Illuminate\Http\Request;
use App\Services\WJackpotService;
use App\Models\WJackpot;
use App\Models\WJackpotTicket;
use Hash;
use Validator;
use DB;

class WJackpotController extends Controller
{
    protected $service;
    protected $tgService;

    /**
     * ReportController constructor.
     */
    public function __construct()
    {
        $this->service = new WJackpotService();
        //$this->tgService = New TelegramService();
    }

    public function dropdownList(Request $request)
    {
        try {
            $data = $this->service->dropdownList();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * w_jackpot/period
     * - 期數(可以查每一期的統計資料，含開獎號碼、總彩池數量(當期的累積金額)、發出獎券數、發出獎金、獎項數量統計等等)
     *
     * @bodyParam period_id integer 期數ID Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "period": "期數,ex:1",
     *            "username": "開獎號碼,ex:test",
     *            "order_type_id": "總彩池數量,ex:2",
     *            "currency_id": "發出獎券數,ex:1",
     *            "member_id": "發出獎金,ex:1",
     *            "amount": "獎項數量統計,ex:100"
     *        }
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
    public function getPeriod(Request $request)
    {
        try {
            //取得查詢期數
            $model = DB::table('w_jackpots as wj')
                ->join('event_details as ed', function ($join) {
                    $join->on('ed.id', 'wj.event_detail_id')
                        ->where('ed.start_time', '<=', now());
                });
            if ($request->period_id > 0) {
                $model = $model->where('wj.id', $request->period_id);
            }
            $total = $model->count();
            $main = $model->groupBy('wj.id')
                ->orderBy('wj.no', 'DESC')
                ->select('wj.id as id')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();
            $w_jackpot_ids = [];
            foreach ($main as $row) {
                array_push($w_jackpot_ids, $row->id);
            }

            $sub = DB::table('w_jackpot_tickets as wjt')
                ->join('w_jackpots as wj', 'wj.id', 'wjt.w_jackpot_id')
                ->join('members as m', 'm.id', 'wjt.member_id')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy_path', 'LIKE', $request->HIERARCHY_PATH . '%');
                })
                ->whereIn('wjt.w_jackpot_id', $w_jackpot_ids)
                ->groupBy('wjt.w_jackpot_id')
                ->select(
                    'wjt.w_jackpot_id',
                    DB::raw('DATE_ADD(wj.draw_time, INTERVAL 8 HOUR) AS draw_time'),
                    DB::raw('COUNT(0) as tickets'),
                    DB::raw('SUM(wjt.point) as point'),
                    DB::raw("IFNULL(SUM(JSON_EXTRACT(example.invalid, '$.r1')), 0) as reward_item_1"),
                    DB::raw("IFNULL(SUM(JSON_EXTRACT(example.invalid, '$.r2')), 0) as reward_item_2"),
                    DB::raw("IFNULL(SUM(JSON_EXTRACT(example.invalid, '$.r3')), 0) as reward_item_3"),
                    DB::raw("IFNULL(SUM(JSON_EXTRACT(example.invalid, '$.r4')), 0) as reward_item_4"),
                    DB::raw("IFNULL(SUM(JSON_EXTRACT(example.invalid, '$.r5')), 0) as reward_item_5")
                );
            $model = DB::table('w_jackpots as wj')
                ->join('event_details as ed', function ($join) {
                    $join->on('ed.id', 'wj.event_detail_id')
                        ->where('ed.start_time', '<=', now());
                })
                ->leftJoin(DB::raw('(' . $sub->toSql() . ') as wjt'), 'wjt.w_jackpot_id', 'wj.id')
                ->mergeBindings($sub)
                ->whereIn('wj.id', $w_jackpot_ids)
                ->groupBy('wj.id')
                ->select(
                    'wj.id',
                    'wj.no',
                    DB::raw('DATE_ADD(ed.start_time, INTERVAL 8 HOUR) AS start_time'),
                    DB::raw('DATE_ADD(ed.end_time, INTERVAL 8 HOUR) AS end_time'),
                    DB::raw('DATE_ADD(wj.draw_time, INTERVAL 8 HOUR) AS draw_time'),
                    DB::raw("CASE WHEN wj.draw_time <= NOW() AND draw_number IS NULL THEN 1 ELSE 0 END AS manual_draw"),
                    'wj.draw_number',
                    'wj.draw_number_2',
                    'wj.draw_number_3',
                    'wj.draw_number_4',
                    'wj.draw_number_5',
                    'wj.status',
                    DB::raw('IFNULL(SUM(wjt.tickets), 0) AS tickets'),
                    DB::raw('IFNULL(SUM(wjt.point), 0) AS point'),
                    DB::raw("(SELECT SUM(v_jackpot) " .
                        "FROM summary_bet_records as b " .
                        "JOIN members as m ON m.id = b.member_id " .
                        "JOIN agents as a ON a.id = m.parent and a.hierarchy_path LIKE '" . $request->HIERARCHY_PATH . "%' " .
                        "WHERE b.summary_time >= ed.start_time and b.summary_time < ed.end_time) AS v_jackpot"),
                    DB::raw('SUM(IFNULL(wjt.reward_item_1, 0)) as reward_item_1'),
                    DB::raw('SUM(IFNULL(wjt.reward_item_2, 0)) as reward_item_2'),
                    DB::raw('SUM(IFNULL(wjt.reward_item_3, 0)) as reward_item_3'),
                    DB::raw('SUM(IFNULL(wjt.reward_item_4, 0)) as reward_item_4'),
                    DB::raw('SUM(IFNULL(wjt.reward_item_5, 0)) as reward_item_5')
                );
            if ($request->period_id > 0) {
                $model = $model->where('wj.id', $request->period_id);
            }
            $data = $model->orderBy('wj.no', 'DESC')->get();

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
     *w_jackpot/member
     * - 會員查詢(可以根據會員帳號或代理帳號查到會員的詳細資料，如獎券數量與明細，中獎總額與明細等等)
     *
     * @bodyParam period_id integer 期數ID Example: 1
     * @bodyParam member_username string 會員名稱 Example: 1
     * @bodyParam agent_username string 代理名稱 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [{
     *          "member_username": "會員名稱,ex:member",
     *          "agent_username": "代理名稱,ex:agent",
     *          "currency_id": "幣種系統編號,ex:1",
     *          "member_id": "會員系統編號,ex:1",
     *          "amount": "金額,ex:100",
     *          "point": "平台點數,ex:100",
     *          "status": "狀態0建立1成功2失敗,ex:1",
     *          "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *          "updated_at": "更新時間,ex:2019-03-08 17:43:28"
     *    }
     *   ],
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
    public function memberSearch(Request $request)
    {
        try {
            $model = DB::table('agents as a')
                ->join('members as m', function ($join) use ($request) {
                    $join->on('m.parent', 'a.id');
                    if ($request->member_username != '') {
                        $join->where('m.username', $request->member_username);
                    }
                })
                ->join('w_jackpot_tickets as wjt', 'wjt.member_id', 'm.id')
                ->leftJoin('w_jackpot_reward_items as wjri', 'wjri.id', 'wjt.reward_item_id')
                ->leftJoin('w_jackpot_reward_item_names as wjrin', function ($join) use ($request) {
                    $join->on('wjrin.w_jackpot_reward_item_id', 'wjri.id')
                        ->where('wjrin.language_id', $request->LANGUAGE);
                })
                ->join('w_jackpots as wj', function($join) use ($request) {
                    $join->on('wj.id', 'wjt.w_jackpot_id')
                        ->where('wj.id', $request->period_id);
                })
                ->where('a.hierarchy_path', 'LIKE', $request->HIERARCHY_PATH . '%');
            if ($request->agent_username != '') {
                $model = $model->where('a.username', $request->agent_username);
            }
            $model = $model->orderBy('wj.no', 'DESC')
                ->orderBy('wjt.type', 'DESC')
                ->orderBy('wjri.level', 'ASC')
                ->select(
                    'a.username as agent_username',
                    'm.username as member_username',
                    'wj.no',
                    'wj.status as w_jackpot_status',
                    'wjt.ticket_number',
                    'wjt.reward_item_id',
                    'wjri.type as prize_type',
                    DB::raw("CASE wjri.level WHEN 1 THEN 'first_prize' WHEN 2 THEN 'second_prize' WHEN 3 THEN 'third_prize' WHEN 4 THEN 'special_prize' WHEN 5 THEN 'consolation_prize' ELSE '' END AS prize"),
                    DB::raw('CASE WHEN wjri.type = 2 THEN IFNULL(wjrin.prize_name, wjri.prize_name) ELSE TRUNCATE(wjt.point, 2) END as point'),
                    'wjt.type',
                    'wjt.status',
                    DB::raw('DATE_ADD(wjt.time, INTERVAL 8 HOUR) AS time'),
                    DB::raw('DATE_ADD(wjt.created_at, INTERVAL 8 HOUR) AS created_at')
                );
            $total = $model->count();
            $data = $model->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();
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
     * w_jackpot/ticket
     * - 獎券查詢(輸入獎券號碼，獎券號碼、編號與獲得會員等等)
     *
     * @bodyParam period_id integer required 期數 Example:1
     * @bodyParam ticket_number string 獎券號碼 Example:1234
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [{
     *          "member_username": "會員名稱,ex:member",
     *          "agent_username": "代理名稱,ex:agent",
     *          "currency_id": "幣種系統編號,ex:1",
     *          "member_id": "會員系統編號,ex:1",
     *          "amount": "金額,ex:100",
     *          "point": "平台點數,ex:100",
     *          "status": "狀態0建立1成功2失敗,ex:1",
     *          "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *          "updated_at": "更新時間,ex:2019-03-08 17:43:28"
     *    }
     *   ],
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
    public function ticketSearch(Request $request)
    {
        try {
            $model = DB::table('w_jackpot_tickets as wjt')
                ->join('w_jackpots as wj', function ($join) use ($request) {
                    $join->on('wj.id', 'wjt.w_jackpot_id');
                    if ($request->period_id > 0) {
                        $join->where('wj.id', $request->period_id);
                    }
                })
                ->join('members as m', function ($join) use ($request) {
                    $join->on('m.id', 'wjt.member_id');
                })
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy_path', 'LIKE', $request->HIERARCHY_PATH . '%');
                });
            if ($request->ticket_number != '') {
                $model = $model->where('wjt.ticket_number', $request->ticket_number);
            }
            $total = $model->count();
            $data = $model->orderBy('wjt.reward_item_id', 'ASC')
                ->select(
                    'wjt.id',
                    'a.username as agent_username',
                    'm.username as member_username',
                    'wj.no',
                    'wjt.ticket_number',
                    'wjt.reward_item_id',
                    'wjt.point',
                    'wjt.type',
                    'wjt.status',
                    DB::raw('DATE_ADD(wjt.time, INTERVAL 8 HOUR) AS time'),
                    DB::raw('DATE_ADD(wjt.created_at, INTERVAL 8 HOUR) AS created_at')
                )
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();
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
     * w_jackpot/winningList
     * - 中獎名單
     *
     * @bodyParam period_id integer required 期數 Example:1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [{
     *          "agent_username": "代理名稱,ex:agent",
     *          "member_username": "會員名稱,ex:member",
     *          "ticket_number": "抽獎券號碼,ex:123456-7",
     *          "bonus": "發出獎金,ex:888.00",
     *    }
     *   ]
     * }
     */
    public function winningList(Request $request)
    {
        try {
            $model = DB::table('w_jackpots as wj')
                ->join('w_jackpot_tickets as wjt', function ($join) use ($request) {
                    $join->on('wjt.w_jackpot_id', 'wj.id')
                        ->where('wjt.type', 1);
                })
                ->join('w_jackpot_reward_items as wjri', 'wjri.id', 'wjt.reward_item_id')
                ->leftJoin('w_jackpot_reward_item_names as wjrin', function ($join) use ($request) {
                    $join->on('wjrin.w_jackpot_reward_item_id', 'wjri.id')
                        ->where('wjrin.language_id', $request->LANGUAGE);
                })
                ->join('members as m', 'm.id', 'wjt.member_id')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy_path', 'LIKE', $request->HIERARCHY_PATH . '%');
                })
                ->where('wj.id', $request->period_id)
                ->orderBy('wjri.level', 'ASC')
                ->select(
                    'a.username as agent_username',
                    'm.username as member_username',
                    'wjt.ticket_number',
                    'wjri.type as prize_type',
                    DB::raw('CASE WHEN wjri.type = 2 THEN IFNULL(wjrin.prize_name, wjri.prize_name) ELSE TRUNCATE(wjt.point, 2) END as point')
                );
            $data = $model->get();
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
     * w_jackpot/manualDraw
     * - 抽獎券手動開獎
     *
     * @bodyParam period_id integer required 期數 Example:1
     * @bodyParam period_no string required 期數 Example:123456-7
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */
    public function manualDraw(Request $request)
    {
        try {
            $rules = array(
                'period_id' => 'required|digits_between:0,10',
                'period_no' => 'required|lucky_ticket'
            );
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            # 取得最後一筆已超過開獎時間且未開獎的紀錄
            $w_jackpot = WJackpot::find($request->period_id);

            if ($w_jackpot->draw_number != '') {
                // 查無當期獎號
                return response()->json([
                    'code' => 1935,
                    'message' => __('error_message.1935')
                ]);
            } elseif ($w_jackpot->status != 0) {
                // 狀態不為0
                return response()->json([
                    'code' => 1936,
                    'message' => __('error_message.1936')
                ]);
            } elseif ($w_jackpot->draw_time >= date('Y-m-d H:i:s')) {
                // 開獎時間未到
                return response()->json([
                    'code' => 1003,
                    'message' => __('error_message.1003')
                ]);
            } else {
                // 寫入當期獎號
                $w_jackpot->draw_number = $request->period_no;
                $w_jackpot->draw_number_2 = $request->period_no_2;
                $w_jackpot->draw_number_3 = $request->period_no_3;
                $w_jackpot->draw_number_4 = $request->period_no_4;
                $w_jackpot->draw_number_5 = $request->period_no_5;
                $w_jackpot->save();

                //宣告output parameter
                $paramErrorCode = '@o_error_code';
                $paramTgMessage = '@o_tg_message';

                DB::update('CALL SP_payout_w_jackpot_ticket_reward(?, ' . $paramErrorCode . ', ' . $paramTgMessage . ')',
                    array(
                        $request->period_id
                    ));

                //取筆數
                $output = DB::select(DB::raw('select ' . $paramErrorCode . ' as error_code, ' . $paramTgMessage . ' as tg_message'));
                $error_code = (is_null($output[0]->error_code)) ? 0 :$output[0]->error_code;
                $tg_message = (is_null($output[0]->tg_message)) ? 0 :$output[0]->tg_message;

                if ($error_code == 0) {
                    if ($tg_message != '') {
                        $this->tgService->sendMessage($tg_message, 'info');
                    }
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => [
                            'period_id' => $request->period_id,
                            'error_code' => $error_code,
                            'tg_message' => $tg_message
                        ]
                    ]);
                } else {
                    return response()->json([
                        'code' => $error_code,
                        'message' => __('error_message.' . $error_code)
                    ]);
                }
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

    private function messages()
    {
        return [
            'period_id.required' => __('error_message.1066'),
            'period_id.digits_between' => __('error_message.1067'),

            'period_no.required' => __('error_message.1305'),
            'period_no.regex' => __('error_message.1305'),
        ];
    }
}