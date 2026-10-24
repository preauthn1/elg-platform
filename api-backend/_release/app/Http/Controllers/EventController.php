<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Event;
use App\EventName;
use App\EventDetail;
use DB;
use App\REDIS;
use Validator;
use App\PrizeContent;
use App\Prize;
use App\PrizeAssign;

class EventController extends Controller
{

    /**
     * event/list
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
     *            "prize_type_id": "獎勵活動類型：ex:1",
     *            "prize_number": "取得獎勵數量,ex:1",
     *            "prize_is_repeatedly": "可否重覆領取0:不可，1:可以,ex:1", 
     *            "prize_expire_date": "獎勵兌換到期日,ex:0000-00-00 00:00:00",
     *            "detail(需滿足的條件)": [
     *                {
     *                    "id": 1,
     *                    "action_id": "條件類型,ex:1",
     *                    "order_type_id": null,
     *                    "vendor_id": null,
     *                    "game_id": null,
     *                    "rate": "0.00",
     *                    "point": 100,
     *                    "start_time": "2019-06-14 00:00:00",
     *                    "end_time": "2019-07-11 23:59:59"
     *                },
     *                {
     *                    "id": 2,
     *                    "action_id": 2,
     *                    "order_type_id": null,
     *                    "vendor_id": null,
     *                    "game_id": null,
     *                    "rate": "0.00",
     *                    "point": 500,
     *                    "start_time": "2019-06-14 00:00:00",
     *                    "end_time": "2019-07-15 23:59:59"
     *                }
     *            ]
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
    function list(Request $request)
    {
        try {
            $model = new Event();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'event',
                'list',
                $request->LANGUAGE,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'event',
                'list',
                $request->LANGUAGE,
                'total'
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $title = DB::raw("(CASE WHEN n.title is NULL THEN a.title ELSE n.title END) as title");
                $content = DB::raw("(CASE WHEN n.content is NULL THEN a.content ELSE n.content END) as content");
                $data = DB::table('events as a')->leftJoin('prizes as p', 'p.event_id', 'a.id')
                    ->leftJoin('event_names as n', function ($join) use ($request) {
                        $join->on('n.event_id', '=', 'a.id')
                            ->where('n.language_id', '=', $request->LANGUAGE);
                    })
                    ->select('a.id', $title, $content, 'a.type_id', 'a.agent_id', 'p.id as prize_id', 'p.type_id as prize_type_id', 'p.number as prize_number', 'p.is_repeatedly as prize_is_repeatedly', 'p.expire_date');
                $data = $data->whereNull('agent_id')->where('a.status', 1);
                $total = $data->count();
                $event = $data->groupBy('a.id')
                    ->orderBy('a.created_at', 'desc')
                    ->orderBy('a.id', 'desc')
                    ->skip(($request->page - 1) * $request->per_page)
                    ->take($request->per_page)
                    ->get();
                $data = array();
                if (is_object($event)) {
                    foreach ($event as $row) {
                        $parent = EventDetail::where('event_id', $row->id)->where('status', 1)
                            ->select('id', 'action_id', 'order_type_id', 'vendor_id', 'game_id', 'rate', 'point', 'start_time', 'end_time')
                            ->get();
                        if (count($parent) > 0) {
                            $row->detail = $parent;
                        }
                        $data[] = $row;
                    }
                }
                $redis->set($key, $data);
                $redis->set($total_key, $total);
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
     * event/view
     * - 活動詳情
     *
     * @bodyParam id integer required 活動編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data":{
     *        "id": 16,
     *        "title": "first deposit",
     *        "content": "first deposit",
     *        "type_id": 1,
     *        "status": 1,
     *        "prize_id": 15,
     *        "prize_type_id": 2,
     *        "prize_name": "首儲大輪盤",
     *        "prize_background": "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156144124375.png",
     *        "prize_number": 1,
     *        "prize_is_repeatedly": "可否重覆領取0:不可，1:可以,ex:1", 
     *        "prize_expire_date": "2019-07-31 23:59:59",
     *        "languages": [
     *            {
     *                "id": 3,
     *                "language_id": 1,
     *                "event_id": 17,
     *                "title": "AAABBBCCC",
     *                "content": "Axd",
     *                "created_at": "2019-06-25 05:57:57",
     *                "updated_at": "2019-06-26 01:26:00",
     *                "admin": 0
     *            },
     *            {
     *                "id": 4,
     *                "language_id": 2,
     *                "event_id": 17,
     *                "title": "AAABBBCCCDDDEEE",
     *                "content": "Asd",
     *                "created_at": "2019-06-25 05:57:57",
     *                "updated_at": "2019-06-26 01:26:00",
     *                "admin": 0
     *            }
     *        ],
     *        "details": [
     *            {
     *                "id": 17,
     *                "event_id": 16,
     *                "action_id": 4,
     *                "order_type_id": 1,
     *                "vendor_id": 0,
     *                "game_id": null,
     *                "rate": "1.00",
     *                "point": 500,
     *                "start_time": "2019-06-25 00:00:00",
     *                "end_time": "2019-07-25 23:59:59",
     *                "created_at": "2019-06-25 05:40:43",
     *                "updated_at": "2019-06-25 05:50:51",
     *                "admin": 1
     *            }
     *        ],
     *        "prizes": [
     *            {
     *                "id": 73,
     *                "prize_id": 15,
     *                "name": "不無小補50",
     *                "number": 50,
     *                "percentage": 10,
     *                "point": 0,
     *                "status": 1,
     *                "created_at": "2019-06-25 05:40:44",
     *                "updated_at": "2019-06-25 05:50:51",
     *                "admin": 0
     *            },
     *            {
     *                "id": 81,
     *                "prize_id": 15,
     *                "name": "銘謝惠顧",
     *                "number": 0,
     *                "percentage": 70,
     *                "point": 0,
     *                "status": 1,
     *                "created_at": "2019-06-25 05:50:51",
     *                "updated_at": "2019-06-25 05:50:51",
     *                "admin": 0
     *            }
     *        ],
     *        "assign": [
     *            {
     *                "id": 1,
     *                "event_id": 16,
     *                "agent_id": 2
     *                "percentage": 10,
     *                "created_at": "2019-06-25 05:40:44",
     *                "updated_at": "2019-06-25 05:50:51",
     *                "admin": 0
     *            },
     *            {
     *                "id": 2,
     *                "event_id": 16,
     *                "agent_id": 3
     *                "percentage": 10,
     *                "created_at": "2019-06-25 05:40:44",
     *                "updated_at": "2019-06-25 05:50:51",
     *                "admin": 0
     *            }
     *        ]
     *    }
     *}
     */
    function show(Request $request)
    {
        try {
            $model = new Event();
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'event',
                $request->id
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $data = $model->leftJoin('prizes as p', 'p.event_id', 'events.id')
                    ->where('events.status', 1)
                    ->whereNull('agent_id')
                    ->where('p.status', 1)
                    ->select('events.id', 'title', 'content', 'events.type_id', 'events.status', 'p.id as prize_id', 'p.type_id as prize_type_id', 'p.name as prize_name', 'p.background as prize_background', 'p.number as prize_number', 'p.is_repeatedly as prize_is_repeatedly', 'p.expire_date as prize_expire_date')
                    ->find($request->id);

                $languages = EventName::where('event_id', $request->id)->get();
                if (count($languages) > 0) {
                    $data->languages = $languages;
                }
                $details = EventDetail::where('event_id', $request->id)->where('status', 1)->get();
                if (count($details) > 0) {
                    $data->details = $details;
                }
                $prizes = PrizeContent::where('prize_id', $data->prize_id)->where('status', 1)->get();
                if (count($prizes) > 0) {
                    $data->prizes = $prizes;
                }
                $assign = PrizeAssign::where('event_id', $data->id)->where('status', 1)->get();
                if (count($assign) > 0) {
                    $data->assign = $assign;
                }

                $redis->set($key, $data);
            } else {
                $data = $redis->get($key);
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
     * event/add
     * -新增活動
     *
     * @bodyParam type_id integer required 類型(1:任務,2:活動) Example: 1
     * @bodyParam title string required 活動標題 Example: 儲值滿額送
     * @bodyParam content string required 預設內容 Example: 新年限定活動xxxxxxx
     * @bodyParam languages array option 多語活動標題/內容 Example: [{"language_id":"*語系編號","title":"活動標題","content":"活動說明"},{"language_id":2,"name":"儲值滿額送","content":"新年限定活動xxxxxxx"}]
     * @bodyParam details array required 獎勵內容 Example: [{"action_id":"*獎勵條件類型","order_type_id":"支付方式編號","vendor_id":"遊戲商編號","point":"*需大於等於多少點數","start_time":"*開始時間","end_time":"*結束時間"}]
     * @bodyParam prize_type_id integer required 獎勵類型 Example: 1
     * @bodyParam prize_name string required 獎勵遊戲名稱
     * @bodyParam prize_background string required 獎勵遊戲背景圖(base64 file)
     * @bodyParam prize_number integer required 獲得遊戲次數
     * @bodyParam prize_is_repeatedly integer required 可否重覆領取0:不可，1:可以
     * @bodyParam prize_expire_date dateTime required 遊戲次數到期時間
     * @bodyParam prizes array required 獎勵內容 Example: [{"name":"*獎勵名稱","point":"*獎勵平台點數","percentage":"*中獎機率","number":"*獎品教量"}]
     * @bodyParam assign array required 指定開獎 Example: [{"agent_id":"*營運商系統編號","percentage":"*中獎比例"}]
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "系統編號"
     * }
     */
    public function create(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new Event();
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $data = array();
            $data['type_id'] = $request->type_id;
            $data['title'] = $request->title;
            $data['content'] = $request->content;
            $data['status'] = 1;
            // event
            if ($result = $model->create($data)) {
                $event_id = $result->id;
                // event names
                $names = array();
                $names['event_id'] = $event_id;
                if (is_array($request->languages)) {
                    foreach ($request->languages as $row) {
                        // 驗證資料
                        $validator = Validator::make($row, $model->rules_lang, $this->messages());
                        if (!$validator->passes()) {
                            return response()->json([
                                'code' => 2002,
                                'message' => __('error_message.2002'),
                                'data' => $validator->errors()
                                    ->all()
                            ]);
                        }
                        $names['language_id'] = $row['language_id'];
                        $names['title'] = $row['title'];
                        $names['content'] = $row['content'];
                        EventName::create($names);
                    }
                }

                // event detail
                $details = array();
                $details['event_id'] = $event_id;
                if (is_array($request->details)) {
                    foreach ($request->details as $row) {
                        // 驗證資料
                        $validator = Validator::make($row, $model->rules_detail, $this->messages());
                        if (!$validator->passes()) {
                            return response()->json([
                                'code' => 2002,
                                'message' => __('error_message.2002'),
                                'data' => $validator->errors()
                                    ->all()
                            ]);
                        }
                        $details['action_id'] = $row['action_id'];
                        $details['order_type_id'] = $row['order_type_id'];
                        $details['vendor_id'] = $row['vendor_id'];
                        $details['rate'] = 1;
                        $details['point'] = $row['point'];
                        $details['start_time'] = $row['start_time'];
                        $details['end_time'] = $row['end_time'];
                        $details['status'] = 1;
                        $details['admin'] = $request->ID;
                        EventDetail::create($details);
                    }
                }

                // prize
                $data = array();
                $data['event_id'] = $event_id;
                $data['type_id'] = $request->prize_type_id;
                $data['name'] = $request->prize_name;
                // 移動圖檔至本地及上傳到GCP
                if (isset($request->prize_background) && $request->prize_background != '') {
                    $path = $this->uploadBase64File($request->prize_background);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $data['background'] = $path['message'];
                }
                $data['number'] = $request->prize_number;
                $data['is_repeatedly'] = $request->prize_is_repeatedly;
                $data['expire_date'] = $request->prize_expire_date;
                $data['status'] = 1;
                if ($result = Prize::create($data)) {
                    // prize content
                    $prizes = array();
                    $prizes['prize_id'] = $result->id;
                    if (is_array($request->prizes)) {
                        foreach ($request->prizes as $row) {
                            // 驗證資料
                            $validator = Validator::make($row, $model->rules_prize, $this->messages());
                            if (!$validator->passes()) {
                                return response()->json([
                                    'code' => 2002,
                                    'message' => __('error_message.2002'),
                                    'data' => $validator->errors()
                                        ->all()
                                ]);
                            }
                            $prizes['name'] = $row['name'];
                            $prizes['number'] = $row['number'];
                            $prizes['percentage'] = $row['percentage'];
                            $prizes['point'] = $row['point'];
                            $prizes['status'] = 1;
                            PrizeContent::create($prizes);
                        }
                    }
                    // prize assign
                    $assign = array();
                    $assign['event_id'] = $event_id;
                    if (is_array($request->assign)) {
                        foreach ($request->assign as $row) {
                            // 驗證資料
                            $validator = Validator::make($row, $model->rules_assign, $this->messages());
                            if (!$validator->passes()) {
                                return response()->json([
                                    'code' => 2002,
                                    'message' => __('error_message.2002'),
                                    'data' => $validator->errors()
                                        ->all()
                                ]);
                            }
                            $assign['agent_id'] = $row['agent_id'];
                            $assign['percentage'] = $row['percentage'];
                            $assign['status'] = 1;
                            PrizeAssign::create($assign);
                        }
                    }

                    DB::commit();
                    $key = array(
                        'event'
                    );
                    $redis = new REDIS();
                    $redis->delete($key);
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $event_id
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 2001,
                    'message' => __('error_message.2001')
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollback();
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
     * event/edit
     * -編輯活動
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam type_id integer 類型(1:任務,2:活動) Example: 1
     * @bodyParam title string 活動標題 Example: 儲值滿額送
     * @bodyParam content string 預設內容 Example: 新年限定活動xxxxxxx
     * @bodyParam languages array option 多語活動標題/內容 Example: [{"language_id":"*語系編號","title":"活動標題","content":"活動說明"},{"language_id":2,"name":"儲值滿額送","content":"新年限定活動xxxxxxx"}]
     * @bodyParam details array required 獎勵內容 Example: [{"action_id":"*獎勵條件類型","order_type_id":"支付方式編號","vendor_id":"遊戲商編號","point":"*需大於等於多少點數","start_time":"*開始時間","end_time":"*結束時間"}]
     * @bodyParam prize_type_id integer 獎勵類型 Example: 1
     * @bodyParam prize_name string 獎勵遊戲名稱
     * @bodyParam prize_background string  獎勵遊戲背景圖(base64 file)
     * @bodyParam prize_number integer 獲得遊戲次數
     * @bodyParam prize_is_repeatedly integer required 可否重覆領取0:不可，1:可以
     * @bodyParam prize_expire_date dateTime 遊戲次數到期時間
     * @bodyParam prizes array 獎勵內容 Example: [{"name":"*獎勵名稱","point":"*獎勵平台點數","percentage":"*中獎機率","number":"*獎品數量"}]
     * @bodyParam assign array required 指定開獎 Example: [{"agent_id":"*營運商系統編號","percentage":"*中獎比例"}]
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new Event();
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            if ($row = $model->find($request->id)) {
                $data = array();
                $data['type_id'] = ($request->type_id != '') ? $request->type_id : $row->type_id;
                $data['title'] = ($request->title != '') ? $request->title : $row->title;
                $data['content'] = ($request->content != '') ? $request->title : $row->content;
                $data['status'] = 1;
                // event
                if ($row->update($data)) {
                    // event names
                    $names = array();
                    if (is_array($request->languages)) {
                        foreach ($request->languages as $row) {
                            // 驗證資料
                            $validator = Validator::make($row, $model->rules_lang, $this->messages());
                            if (!$validator->passes()) {
                                return response()->json([
                                    'code' => 2002,
                                    'message' => __('error_message.2002'),
                                    'data' => $validator->errors()
                                        ->all()
                                ]);
                            }
                            $names['language_id'] = $row['language_id'];
                            $names['title'] = $row['title'];
                            $names['content'] = $row['content'];
                            $event_names = EventName::where('event_id', $request->id)->where('language_id', $row['language_id'])->get();
                            if (count($event_names) > 0) {
                                EventName::where('event_id', $request->id)->where('language_id', $row['language_id'])->update($names);
                            } else {
                                EventName::create($names);
                            }
                        }
                    }
                    EventDetail::where('event_id', $request->id)->update([
                        'status' => 0
                    ]);
                    // event detail
                    $details = array();
                    $details['event_id'] = $request->id;
                    if (is_array($request->details)) {
                        foreach ($request->details as $row) {
                            // 驗證資料
                            $validator = Validator::make($row, $model->rules_detail, $this->messages());
                            if (!$validator->passes()) {
                                return response()->json([
                                    'code' => 2002,
                                    'message' => __('error_message.2002'),
                                    'data' => $validator->errors()
                                        ->all()
                                ]);
                            }
                            $details['action_id'] = $row['action_id'];
                            $details['order_type_id'] = $row['order_type_id'];
                            $details['vendor_id'] = $row['vendor_id'];
                            $details['point'] = $row['point'];
                            $details['start_time'] = $row['start_time'];
                            $details['end_time'] = $row['end_time'];
                            $details['status'] = 1;
                            $details['admin'] = $request->ID;
                            EventDetail::create($details);
                        }
                    }
                    $row = Prize::where('event_id', $request->id)->first();
                    // prize
                    $data = array();
                    $data['type_id'] = ($request->prize_type_id != '') ? $request->prize_type_id : $row->type_id;
                    $data['name'] = ($request->prize_name != '') ? $request->prize_name : $row->name;
                    // 移動圖檔至本地及上傳到GCP
                    if (isset($request->prize_background) && $request->prize_background != '') {
                        $path = $this->uploadBase64File($request->prize_background);
                        if ($path['code'] != 1000) {
                            return response()->json([
                                'code' => $path['code'],
                                'message' => $path['message']
                            ]);
                        }
                        $data['background'] = $path['message'];
                    }
                    $data['number'] = ($request->prize_number != '') ? $request->prize_number : $row->number;
                    $data['is_repeatedly'] = ($request->prize_is_repeatedly != '') ? $request->prize_is_repeatedly : $row->is_repeatedly;
                    $data['expire_date'] = ($request->prize_expire_date != '') ? $request->prize_expire_date : $row->expire_date;
                    $data['status'] = 1;
                    if ($row->update($data)) {
                        PrizeContent::where('prize_id', $row->id)->update([
                            'status' => 0
                        ]);
                        // prize content
                        $prizes = array();
                        $prizes['prize_id'] = $row->id;
                        if (is_array($request->prizes)) {
                            foreach ($request->prizes as $row) {
                                // 驗證資料
                                $validator = Validator::make($row, $model->rules_prize, $this->messages());
                                if (!$validator->passes()) {
                                    return response()->json([
                                        'code' => 2002,
                                        'message' => __('error_message.2002'),
                                        'data' => $validator->errors()
                                            ->all()
                                    ]);
                                }
                                $prizes['name'] = $row['name'];
                                $prizes['number'] = $row['number'];
                                $prizes['percentage'] = $row['percentage'];
                                $prizes['point'] = $row['point'];
                                $prizes['status'] = 1;
                                PrizeContent::create($prizes);
                            }
                        }
                        // prize assign
                        PrizeAssign::where('event_id', $request->id)->update([
                            'status' => 0
                        ]);
                        $assign = array();
                        $assign['event_id'] = $request->id;
                        if (is_array($request->assign)) {
                            foreach ($request->assign as $row) {
                                // 驗證資料
                                $validator = Validator::make($row, $model->rules_assign, $this->messages());
                                if (!$validator->passes()) {
                                    return response()->json([
                                        'code' => 2002,
                                        'message' => __('error_message.2002'),
                                        'data' => $validator->errors()
                                            ->all()
                                    ]);
                                }
                                $assign['agent_id'] = $row['agent_id'];
                                $assign['percentage'] = $row['percentage'];
                                $assign['status'] = 1;
                                PrizeAssign::create($assign);
                            }
                        }

                        DB::commit();
                        $key = array(
                            'event'
                        );
                        $redis = new REDIS();
                        $redis->delete($key);
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000')
                        ]);
                    }
                } else {
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001')
                    ]);
                }
            } else {
                // 查無記錄
                return response()->json([
                    'code' => 1901,
                    'message' => __('error_message.1901')
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollback();
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
            'id.required' => __('error_message.1902'),
            'id.digits_between' => __('error_message.1903'),

            'type_id.required' => __('error_message.1904'),
            'type_id.digits_between' => __('error_message.1905'),

            'title.required' => __('error_message.1906'),
            'title.between' => __('error_message.1907'),

            'content.required' => __('error_message.1908'),

            'languages.array' => __('error_message.1909'),
            'language_id.required' => __('error_message.2019'),
            'language_id.digits_between' => __('error_message.2020'),

            'details.array' => __('error_message.1933'),

            'action_id.required' => __('error_message.1910'),
            'action_id.digits_between' => __('error_message.1911'),

            'order_type_id.digits_between' => __('error_message.1912'),

            'vendor_id.digits_between' => __('error_message.1416'),

            'point.required' => __('error_message.1913'),
            'point.digits_between' => __('error_message.1914'),

            'start_time.required' => __('error_message.2027'),
            'start_time.date_format' => __('error_message.2029'),

            'end_time.required' => __('error_message.2028'),
            'end_time.date_format' => __('error_message.2030'),
            'end_time.after' => __('error_message.2031'),

            'prize_type_id.required' => __('error_message.1915'),
            'prize_type_id.digits_between' => __('error_message.1916'),

            'prize_name.required' => __('error_message.1917'),
            'prize_name.between' => __('error_message.1918'),

            'prize_background.required' => __('error_message.2023'),
            'prize_background.base64image' => __('error_message.2023'),

            'prize_number.required' => __('error_message.1919'),
            'prize_number.digits_between' => __('error_message.1920'),

            'prize_expire_date.required' => __('error_message.1921'),
            'prize_expire_date.date_format' => __('error_message.1922'),

            'prizes.required' => __('error_message.1923'),
            'prizes.array' => __('error_message.1924'),

            'agent_id.required' => __('error_message.2017'),
            'agent_id.digits_between' => __('error_message.2018'),

            'name.required' => __('error_message.1925'),
            'name.between' => __('error_message.1926'),

            'number.required' => __('error_message.1931'),
            'number.digits_between' => __('error_message.1932'),

            'percentage.required' => __('error_message.1929'),
            'percentage.float' => __('error_message.1930'),

            'point.required' => __('error_message.1927'),
            'point.digits_between' => __('error_message.1928'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),
            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}