<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventProcess;
use App\Models\Member;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Models\TransactionLog;
use App\Models\VendorHourRecord;
use App\Models\FlowLimit;
use App\Enums\AgentCustomizedSettingKeyEnum;
use DB;
use App\REDIS;
use Validator;

/**
 * @group Prize
 */
class PrizeController extends Controller
{

    /**
     * prizes
     * - 取得中獎列表
     *
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "event_name": "活動名稱,ex:首儲獎勵",
     *            "name": "獎項名稱,ex:紅利66",
     *            "point": "取得點數,ex:66",
     *            "updated_at": "取得時間,ex:2019-01-01 00:00:00"
     *          },
     *          {
     *            "id": 2,
     *            "event_name": "活動名稱,ex:首儲獎勵",
     *            "name": "銘謝惠顧",
     *            "point": 0,
     *            "updated_at": "2019-01-01 00:00:00"
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
    public function list(Request $request)
    {
        try {
            $model = new PrizeMember();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            $title = DB::raw("(CASE WHEN n.title is NULL THEN e.title ELSE n.title END) as event_name");
            $data = DB::table('prize_members as m')
                ->leftJoin('prize_contents as c', 'c.id', 'm.content_id')
                ->leftJoin('prizes as p', 'p.id', 'c.prize_id')
                ->leftJoin('events as e', 'e.id', 'p.event_id')
                ->leftJoin('event_names as n', function ($join) use ($request) {
                    $join->on('n.event_id', 'e.id')
                        ->where('n.language_id', $request->LANGUAGE);
                })
                ->where('m.status', 1)
                ->where('member_id', $request->ID)
                ->select(
                    'm.id',
                    $title,
                    'c.name',
                    'c.point',
                    DB::raw('DATE_ADD(m.updated_at,INTERVAL 8 HOUR) as updated_at')
                );
            $total = $data->count();
            $data = $data->groupBy('m.id')
                ->orderBy('m.updated_at', 'desc')
                ->orderBy('m.id', 'desc')
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
     * lotteryNumbers
     * - 取得抽獎活動的中獎編號
     *
     * @bodyParam event_id integer required 活動系統編號 Example: 1
     * 
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *           "id": 149,
     *           "name": "iphone 12",
     *           "lottery_numbers": [
     *               {
     *                   "lottery_number": "6368143185"
     *               },
     *               {
     *                   "lottery_number": "5345416331"
     *               },
     *               {
     *                   "lottery_number": "4864166743"
     *               }
     *           ]
     *       },
     *       {
     *           "id": 150,
     *           "name": "iphone 6s",
     *           "lottery_numbers": [
     *               {
     *                   "lottery_number": "5416348631"
     *               }
     *           ]
     *       }
     *    ]
     * }
     */
    function lotteryNumbers(Request $request)
    {
        try {
            $model = new Event();
            $validator = Validator::make($request->all(), $model->rules_event, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            $prizes = DB::table('prizes as p')
                ->leftJoin('prize_contents as c', function ($join) {
                    $join->on('p.id', '=', 'c.prize_id')
                        ->where('c.status', 1);
                })
                ->select('c.id', 'c.name')
                ->where('p.event_id', $request->event_id)
                ->where('p.draw_date', '<', DB::raw('Now()'))
                ->get();
            $data = array();
            foreach ($prizes as $row) {
                //參與活動的遊戲列表
                $model = PrizeMember::where('content_id', $row->id)->where('status', 1)->select('lottery_number');
                if (is_object($model)) {
                    $row->lottery_numbers = $model->get();
                }
                $data[] = $row;
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

    /**
     * myLotteryNumbers
     * - 取得抽獎卷列表
     *
     * @bodyParam event_id integer required 活動系統編號 Example: 1
     * 
     * @response {
     * "code": 1000,
     * "message": "Success",
     * "data": [
     *     {
     *         "lottery_number": 12384635546,
     *         "status": "狀態：0:未開獎，1:中獎，2:沒中獎,ex:1"
     *     },
     *     {
     *         "lottery_number": 46854638874,
     *         "status": 2
     *     }
     * ]
     * }
     */
    function myLotteryNumbers(Request $request)
    {
        try {
            $model = new Event();
            $validator = Validator::make($request->all(), $model->rules_event, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            $data = DB::table('prizes as p')
                ->leftJoin('prize_members as m', 'p.id', 'm.prize_id')
                ->select(
                    'm.lottery_number',
                    DB::raw('CASE WHEN (p.draw_date < Now()) THEN m.status ELSE 0 END as status')
                )
                ->where('p.event_id', $request->event_id)
                ->whereNotNull('m.lottery_number')
                ->get();

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
     * getPrize
     * - 取得中獎項目
     *
     * @bodyParam event_id integer required 活動系統編號 Example: 1
     * 
     * @response {
     *    "code": 1000,
     *    "message": "成功",
     *    "data": {
     *        "id": 15,
     *        "event_id": 16,
     *        "type_id": 2,
     *        "name": "首儲大輪盤",
     *        "background": "輪盤背景圖片，刮刮卡刮卡圖層",
     *        "number": 1,
     *        "expire_date": "2019-07-31 23:59:59",
     *        "prizes(獎項列表，輪盤才會用到)": [
     *            {
     *                "id": "獎項編號，ex:73",
     *                "name": "獎項名稱，ex:不無小補50",
     *                "point": "獲得點數，ex:10"
     *            },
     *            {
     *                "id": 81,
     *                "name": "銘謝惠顧",
     *                "point": 0
     *            }
     *        ],
     *        "prize(獲得獎項)": {
     *            "id": "獎項編號，ex:81",
     *            "name": "獎項名稱，ex:銘謝惠顧",
     *            "point": "獲得點數，ex:10",
     *            "prize_member": "兌獎記錄系統編號 ,ex:7"
     *        }
     *    }
     *}
     */
    function getPrize(Request $request)
    {
        try {
            $model = new Prize();
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            DB::beginTransaction();
            // 取獎勵資料
            $data = $model->where('event_id', $request->event_id)
                ->where('status', 1)
                ->where('expire_date', '>=', date('Y-m-d H:i:s'))
                ->select(
                    'id',
                    'event_id',
                    'type_id',
                    'name',
                    'background',
                    'number',
                    'expire_date',
                    'is_repeatedly'
                )
                ->first();
            if (is_object($data)) {
                // 取獎項內容
                $prizes = PrizeContent::select('id', 'name', 'point', 'percentage')
                    ->where('prize_id', $data->id)
                    ->where('status', 1)
                    ->where(function ($query) {
                        $query->where('number', '>', DB::raw('sent'))
                            ->orWhere('number', -1);
                    })
                    ->get();
                if (count($prizes) > 0) {
                    $data->prizes = $prizes;
                }
                // 取出還沒兌換的獎勵
                $effective = PrizeMember::lockForUpdate()
                    ->where('prize_id', $data->id)
                    ->where('member_id', $request->ID)
                    ->where('status', 0)
                    ->select(
                        'id',
                        DB::raw('IFNULL(content_id, 0) as content_id')
                    )
                    ->first();

                if (is_object($effective)) {
                    if ($effective->content_id > 0) {
                        // 之前抽出，但還沒領走的
                        $row = PrizeContent::select('id', 'name', 'point')
                            ->find($effective->content_id);
                        // 加上兌獎流水號
                        $row->prize_member = $effective->id;
                        $data->prize = $row;
                        $content_id = $effective->content_id;
                    } else {
                        // 如果還沒抽出獎品，抽取獎品寫入
                        $total = 0;
                        foreach ($prizes as $row) {
                            $total += $row['percentage'];
                        }
                        $result = rand(1, $total); //抽出一個編號
                        $count = 0;
                        foreach ($prizes as $row) {
                            $count += $row['percentage'];
                            if ($count >= $result) {
                                // 加上已發送
                                PrizeContent::where('id', $row->id)
                                    ->update([
                                        'sent' => DB::raw("sent+1")
                                    ]);

                                // 將獎項寫入
                                PrizeMember::find($effective->id)
                                    ->update([
                                        'content_id' => $row->id
                                    ]);
                                $row->prize_member = $effective->id;
                                $data->prize = $row;
                                $content_id = $row->id;
                                break;
                            }
                        }
                    }

                    if (isset($content_id)) {
                        //檢查是是否合法領取，並刷新進度條(只能單次兌換的不刷新)
                        $is_legal = $this->refreshProcess($request->event_id, $request->ID, $data->is_repeatedly);
                        //檢查是否達成領取條件
                        if ($is_legal) {
                            //領獎
                            $this->sendPrize($effective->id, $content_id, $request->ID);
                            DB::commit();
                            return response()->json([
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => $data
                            ]);
                        } else {
                            //不合法
                            DB::rollback();
                            return response()->json([
                                'code' => 20041,
                                'message' => __('error_message.2004')
                            ]);
                        }
                    } else {
                        //取不到獎項
                        DB::rollback();
                        return response()->json([
                            'code' => 2004,
                            'message' => __('error_message.2004')
                        ]);
                    }
                } else {
                    //沒有可以領取的獎勵
                    DB::rollback();
                    return response()->json([
                        'code' => 9003,
                        'message' => __('error_message.9003')
                    ]);
                }
            } else {
                //查不到活動獎項
                DB::rollback();
                return response()->json([
                    'code' => 1901,
                    'message' => __('error_message.1901')
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            DB::rollback();
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
     * setPrize
     * - 設定中獎項目為已領取
     *
     * @bodyParam prize_member_id integer required 兌獎記錄系統編號 Example: 1
     * 
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    function setPrize(Request $request)
    {
        //打開就直接領取了，所以這個沒用 -- 2019-11-25
        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000')
        ]);
        try {
            DB::beginTransaction();
            $row = PrizeMember::lockForUpdate()->find($request->prize_member_id);
            if (is_object($row)) {
                $data = [
                    'status' => 1
                ];
                // 修改獎項為已領取
                $row->update($data);
                $this->sendPrize($row->id, $row->content_id, $request->ID);
                DB::commit();
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000')
                ]);
            } else {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003')
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

    private function sendPrize($prizeMemberId, $contentId, $memberId)
    {
        $type = 13;
        // 更新狀態
        PrizeMember::find($prizeMemberId)
            ->update([
                'status' => 1
            ]);
        $content = PrizeContent::find($contentId);
        // 獲獎點數大於0，增加玩家餘額
        if (is_object($content) && $content->point > 0) {
            $member = Member::where('id', $memberId)->first();

            // 取代理資料
            $agent = DB::table('agents as a')
                ->leftJoin('agent_customized_settings as s', function ($join) {
                    $join->on('s.agent_group_id', 'a.customized_setting_group_id')
                        ->where('s.setting_key', AgentCustomizedSettingKeyEnum::EVENT_FLOW_LIMIT_RATE);
                })
                ->where('a.id', $member->parent)
                ->select(
                    'a.id',
                    'a.transaction_all',
                    DB::raw('IFNULL(s.value, 0) AS event_flow_limit_rate'),
                    'a.customized_setting_group_id'
                )
                ->first();

            Member::where('id', $memberId)
                ->update([
                    'balance' => DB::raw('balance+' . $content->point)
                ]);

            //寫入轉點記錄
            $log = [
                'transaction_no' => $prizeMemberId,
                'agent_id' => $member->parent,
                'member_id' => $member->id,
                'type' => $type,
                'rate' => 1,
                'point' =>  $content->point,
                'before_balance' => $member->balance,
                'balance' => ($member->balance + $content->point),
                'status' => 1000,
                'remark' => '活動獎勵',
                'operator' => 'system',
            ];
            $result = TransactionLog::create($log);

            //增加稽核流水log
            if (floatval($agent->event_flow_limit_rate) > 0) {
                $flow = array();
                $flow['member_id'] = $member->id;
                $flow['type'] = $type;
                $flow['reference_id'] = $result->id;
                $flow['flow_limit'] = strval($content->point * floatval($agent->event_flow_limit_rate));
                $flow['reach'] = 0;
                $flow['admin'] = 0;
                FlowLimit::create($flow);
            }

            // 刪除Redis中相關的資料
            $redis = new REDIS();
            $redis->delete(array(
                'member'
            ));
            $redis->delete(array(
                'event'
            ));
        }
    }

    // 已兌獎的點數要記錄，不可重覆兌換其他活動的獎項
    private function refreshProcess($eventId, $memberId, $isRepeatedly)
    {
        //取此活動的processes
        $details = DB::table('event_details as d')
            ->leftJoin('event_processes as p', 'p.detail_id', 'd.id')
            ->where('d.event_id', $eventId)
            ->where('d.status', 1)
            ->where('p.member_id', $memberId)
            ->select('p.id', 'd.point')
            ->get();
        if (is_object($details) && count($details) > 0) {
            foreach ($details as $row) {
                $process = EventProcess::find($row->id);
                //檢查是否有進度未到確又領取的人
                if ($process->point < ($process->received + $row->point)) {
                    return false;
                }
                //可重覆領取的要刷新進度條
                if ($isRepeatedly == 1) {
                    $process->increment('received', $row->point);
                    break;
                }
            }
            return true;
        } else {
            //沒有記錄也是FALSE
            return false;
        }
    }
    
    private function messages()
    {
        return [
            'event_id.required' => __('error_message.1902'),
            'event_id.numeric' => __('error_message.1903'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),
            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}