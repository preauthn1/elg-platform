<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TelegramService;
use Validator;
use DB;

class VJackpotController extends Controller
{
    protected $tgService;

    public function __construct()
    {
        $this->tgService = New TelegramService();
    }

    /**
     * v_jackpot/period
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
            $model = DB::table('lucky_draws as ld')
                ->join('event_details as ed', function ($join) {
                    $join->on('ed.id', 'ld.event_detail_id')
                        ->where('ed.start_time', '<=', now());
                });
            if ($request->period_id > 0) {
                $model = $model->where('ld.id', $request->period_id);
            }
            $main = $model->groupBy('ld.id')
                ->orderBy('ld.no', 'DESC')
                ->select('ld.id as id')
                ->take($request->per_page)
                ->get();
            $lucky_draw_ids = [];
            foreach ($main as $row) {
                array_push($lucky_draw_ids, $row->id);
            }
            $sub = DB::table('lucky_draw_tickets as ldt')
                ->join('lucky_draws as ld', 'ld.id', 'ldt.lucky_draw_id')
                ->join('members as m', 'm.id', 'ldt.member_id')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                })
                ->leftJoin('reward_items as ri', 'ri.id', 'ldt.reward_item_id')
                ->whereIn('ldt.lucky_draw_id', $lucky_draw_ids)
                ->groupBy('ldt.lucky_draw_id')
                ->select(
                    'ldt.lucky_draw_id',
                    DB::raw('DATE_ADD(ld.draw_time, INTERVAL 8 HOUR) AS draw_time'),
                    DB::raw('COUNT(0) as tickets'),
                    DB::raw('SUM(ldt.point) as point'),
                    DB::raw('SUM(CASE when ri.level = 1 THEN 1 ELSE 0 END) AS reward_item_1'),
                    DB::raw('SUM(CASE when ri.level = 2 THEN 1 ELSE 0 END) AS reward_item_2'),
                    DB::raw('SUM(CASE when ri.level = 3 THEN 1 ELSE 0 END) AS reward_item_3'),
                    DB::raw('SUM(CASE when ri.level = 4 THEN 1 ELSE 0 END) AS reward_item_4'),
                    DB::raw('SUM(CASE when ri.level = 5 THEN 1 ELSE 0 END) AS reward_item_5')
                );
            $model = DB::table('lucky_draws as ld')
                ->join('event_details as ed', function ($join) {
                    $join->on('ed.id', 'ld.event_detail_id')
                        ->where('ed.start_time', '<=', now());
                })
                ->leftJoin(DB::raw('(' . $sub->toSql() . ') as ldt'), 'ldt.lucky_draw_id', 'ld.id')
                ->mergeBindings($sub)
                ->whereIn('ld.id', $lucky_draw_ids)
                ->groupBy('ld.id')
                ->select(
                    'ld.id',
                    'ld.no',
                    DB::raw('DATE_ADD(ed.start_time, INTERVAL 8 HOUR) AS start_time'),
                    DB::raw('DATE_ADD(ed.end_time, INTERVAL 8 HOUR) AS end_time'),
                    DB::raw('DATE_ADD(ld.draw_time, INTERVAL 8 HOUR) AS draw_time'),
                    DB::raw("CASE WHEN ld.draw_time <= NOW() AND draw_number IS NULL THEN 1 ELSE 0 END AS manual_draw"),
                    'ld.draw_number',
                    'ld.status',
                    DB::raw('IFNULL(SUM(ldt.tickets), 0) AS tickets'),
                    DB::raw('IFNULL(SUM(ldt.point), 0) AS point'),
                    DB::raw("(SELECT SUM(v_jackpot) " .
                        "FROM summary_bet_records as b " .
                        "JOIN members as m ON m.id = b.member_id " .
                        "JOIN agents as a ON a.id = m.parent and a.hierarchy like '%," . $request->HOST . ",%' " .
                        "WHERE b.summary_time >= ed.start_time and b.summary_time < ed.end_time) AS v_jackpot"),
                    DB::raw('SUM(IFNULL(ldt.reward_item_1, 0)) as reward_item_1'),
                    DB::raw('SUM(IFNULL(ldt.reward_item_2, 0)) as reward_item_2'),
                    DB::raw('SUM(IFNULL(ldt.reward_item_3, 0)) as reward_item_3'),
                    DB::raw('SUM(IFNULL(ldt.reward_item_4, 0)) as reward_item_4'),
                    DB::raw('SUM(IFNULL(ldt.reward_item_5, 0)) as reward_item_5')
                );
            if ($request->period_id > 0) {
                $model = $model->where('ld.id', $request->period_id);
            }

            $total = DB::table(DB::raw('(' . $model->toSql() . ') as z'))
                ->mergeBindings($model)
                ->count();

            $data = $model->orderBy('ld.no', 'DESC')
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
     *v_jackpot/member
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
                ->join('lucky_draw_tickets as ldt', 'ldt.member_id', 'm.id')
                ->leftJoin('reward_items as ri', 'ri.id', 'ldt.reward_item_id')
                ->leftJoin('reward_item_names as rin', function ($join) use ($request) {
                    $join->on('rin.type_id', 'ri.id')
                        ->where('rin.language_id', $request->LANGUAGE);
                })
                ->join('lucky_draws as ld', function($join) use ($request) {
                    $join->on('ld.id', 'ldt.lucky_draw_id')
                        ->where('ld.id', $request->period_id);
                })
                ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%')
                ->orderBy('ld.no', 'DESC')
                ->orderBy('ldt.type', 'DESC')
                ->orderBy('ri.level', 'ASC')
                ->select(
                    'a.username as agent_username',
                    'm.username as member_username',
                    'ld.no',
                    'ld.status as lucky_draw_status',
                    'ldt.ticket_number',
                    'ldt.reward_item_id',
                    'ri.type as prize_type',
                    DB::raw("CASE ri.level WHEN 1 THEN 'ultimate_pot' WHEN 2 THEN 'first_prize' WHEN 3 THEN 'second_prize' WHEN 4 THEN 'third_prize' WHEN 5 THEN 'fourth_prize' ELSE '' END AS prize"),
                    DB::raw('CASE WHEN ri.type = 2 THEN IFNULL(rin.prize_name, ri.prize_name) ELSE TRUNCATE(ldt.point, 2) END as point'),
                    'ldt.type',
                    'ldt.status',
                    DB::raw('DATE_ADD(ldt.time, INTERVAL 8 HOUR) AS time'),
                    DB::raw('DATE_ADD(ldt.created_at, INTERVAL 8 HOUR) AS created_at')
                );
            if ($request->agent_username != '') {
                $model = $model->where('a.username', $request->agent_username);
            }
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
     * v_jackpot/ticket
     * - 獎券查詢(輸入獎券號碼(可以模糊查詢例如前四與後四，最少四個數字)，獎券號碼、編號與獲得會員等等)
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
            $model = DB::table('lucky_draw_tickets as ldt')
                ->join('lucky_draws as ld', function ($join) use ($request) {
                    $join->on('ld.id', 'ldt.lucky_draw_id');
                    if ($request->period_id > 0) {
                        $join->where('ld.id', $request->period_id);
                    }
                })
                ->join('members as m', function ($join) use ($request) {
                    $join->on('m.id', 'ldt.member_id');
                })
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                })
                ->orderBy('ldt.reward_item_id', 'ASC')
                ->select(
                    'ldt.id',
                    'a.username as agent_username',
                    'm.username as member_username',
                    'ld.no',
                    'ldt.ticket_number',
                    'ldt.reward_item_id',
                    'ldt.point',
                    'ldt.type',
                    'ldt.status',
                    DB::raw('DATE_ADD(ldt.time, INTERVAL 8 HOUR) AS time'),
                    DB::raw('DATE_ADD(ldt.created_at, INTERVAL 8 HOUR) AS created_at')
                );
            if (strlen($request->ticket_number) == 6) {
                $model = $model->where('ldt.ticket_number', 'like', $request->ticket_number . '-%');
            } else if (strlen($request->ticket_number) >= 4 && strlen($request->ticket_number) < 6) {
                $model = $model->where(function($query) use ($request) {
                    $query->where('ldt.ticket_number', 'like', $request->ticket_number . '%-%')
                        ->orWhere('ldt.ticket_number', 'like', '%'.$request->ticket_number . '-%');
                });
            } else {
                $model = $model->where('ldt.ticket_number', $request->ticket_number);
            }
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
     * v_jackpot/winningList
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
            $model = DB::table('lucky_draws as ld')
                ->join('lucky_draw_tickets as ldt', function ($join) use ($request) {
                    $join->on('ldt.lucky_draw_id', 'ld.id')
                        ->where('ldt.type', 1);
                })
                ->join('reward_items as ri', 'ri.id', 'ldt.reward_item_id')
                ->leftJoin('reward_item_names as rin', function ($join) use ($request) {
                    $join->on('rin.type_id', 'ri.id')
                        ->where('rin.language_id', $request->LANGUAGE);
                })
                ->join('members as m', 'm.id', 'ldt.member_id')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                })
                ->where('ld.id', $request->period_id)
                ->orderBy('ri.level', 'ASC')
                ->select(
                    'a.username as agent_username',
                    'm.username as member_username',
                    'ldt.ticket_number',
                    'ri.type as prize_type',
                    DB::raw('CASE WHEN ri.type = 2 THEN IFNULL(rin.prize_name, ri.prize_name) ELSE TRUNCATE(ldt.point, 2) END as point')
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
     * v_jackpot/manualDraw
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
            //宣告output parameter
            $paramErrorCode = '@o_error_code';
            $paramTgMessage = '@o_tg_message';

            DB::update('CALL SP_set_lottery_ticket_number(?, ?, ' . $paramErrorCode . ', ' . $paramTgMessage . ')',
                array(
                    $request->period_id,
                    $request->period_no
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
                    'message' => __('error_message.1000')
                ]);
            } else {
                return response()->json([
                    'code' => $error_code,
                    'message' => __('error_message.' . $error_code)
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
