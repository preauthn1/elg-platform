<?php

namespace App\Http\Controllers;

use App\Helpers\SQLHelper;
use Illuminate\Http\Request;
use App\Models\LoginEvent;
use App\Models\LoginEventName;
use DB;
use App\REDIS;
use Validator;

class LoginEventController extends Controller
{
    /**
     * loginEvent/list
     * -登入獎勵列表
     *
     * @bodyParam title string 登入獎勵標題 Example: title
     * @bodyParam status boolean 狀態 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "title": "登入獎勵標題,ex:title",
     *            "content": "登入獎勵內容,ex:Content",
     *            "agent_id": "代理商編號,ex:1",
     *            "type": "登入獎勵類型 (1:新會員,2:全部會員),ex:1",
     *            "how_days": "幾日內無開洗分紀錄,ex:0",
     *            "point": "參與活動贈送的點數,ex:1",
     *            "times": "解鎖條件; 達到贈送點數的幾倍,ex:1",
     *            "number_of_people": "可參與的人數(0代表無上限),ex:0",
     *            "start_time": "開始時間,ex:2019-03-08 17:55:41",
     *            "end_time": "結束時間,ex:2019-03-08 17:55:41",
     *            "repeat_hour": "重複參與時間間隔(最少1小時，0代表不可重複參加),ex:1",
     *            "vendor_id": "試玩廠商,ex:1,2,3,4",
     *            "number_of_participants": "已參與的人數,ex:0",
     *            "status": "狀態0停用1正常,ex:1",
     *            "created_at": "建立時間,ex:2019-03-08 17:55:41",
     *            "updated_at": "最後編輯時間,ex:2019-03-08 17:55:41",
     *            "admin": "最後編輯者,ex:1",
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
    function list(Request $request)
    {
        try {
            $model = new LoginEvent();
            $agents = explode('/', substr($request->HIERARCHY_PATH, 1));
            $model = DB::table('login_events as le')
                ->join('agents as a', function ($join) use ($request, $agents){
                    $join->on('a.id', 'le.agent_id')
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                });
            if ($request->title != '') {
                $model = $model->where('le.title', 'like', '%' . $request->title . '%');
            }
            if ($request->status != 'all') {
                $model = $model->where('le.status', $request->status);
            }
            $model = $model->select(
                    'le.id',
                    'le.title',
                    'le.content',
                    'le.agent_id',
                    'le.type',
                    'le.how_days',
                    'le.point',
                    'le.times',
                    'le.number_of_people',
                    'le.number_of_participants',
                    DB::raw('DATE_ADD(le.start_time, INTERVAL 8 HOUR) AS start_time'),
                    DB::raw('DATE_ADD(le.end_time, INTERVAL 8 HOUR) AS end_time'),
                    'le.repeat_hour',
                    'le.vendor_id',
                    'le.status',
                    'le.admin',
                    'a.username as agent_username'
                );
            $total = $model->count();
            $data = $model->get();
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
     * loginEvent/view
     * -登入獎勵詳細資料
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "title": "登入獎勵標題,ex:title",
     *            "content": "登入獎勵內容,ex:Content",
     *            "agent_id": "代理商編號,ex:1",
     *            "type": "登入獎勵類型 (1:新會員,2:全部會員),ex:1",
     *            "how_days": "幾日內無開洗分紀錄,ex:0",
     *            "point": "參與活動贈送的點數,ex:1",
     *            "times": "解鎖條件; 達到贈送點數的幾倍,ex:1",
     *            "number_of_people": "可參與的人數(0代表無上限),ex:0",
     *            "start_time": "開始時間,ex:2019-03-08 17:55:41",
     *            "end_time": "結束時間,ex:2019-03-08 17:55:41",
     *            "repeat_hour": "重複參與時間間隔(最少1小時，0代表不可重複參加),ex:1",
     *            "vendor_id": "試玩廠商,ex:1,2,3,4",
     *            "number_of_participants": "已參與的人數,ex:0",
     *            "status": "狀態0停用1正常,ex:1",
     *            "created_at": "建立時間,ex:2019-03-08 17:55:41",
     *            "updated_at": "最後編輯時間,ex:2019-03-08 17:55:41",
     *            "admin": "最後編輯者,ex:1",
     *            "names":[{"language_id":1,"title":"title 1", "content":"content 1"},{"language_id":2,"name":"title 2","content":"content 2"}]
     *        }
     *    ]
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new LoginEvent();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            // 先確認資料是否存在
            if ($data = $model->find($request->id)) {
                $data['start_time'] = date('Y-m-d H:i:s', strtotime($data['start_time'] . ' +8hour'));
                $data['end_time'] = date('Y-m-d H:i:s', strtotime($data['end_time'] . ' +8hour'));
                // 取其他語系
                $names = LoginEventName::where('login_event_id', $request->id)
                    ->select('language_id', 'title', 'content')
                    ->get();
                $data['names'] = $names;
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $data
                ]);
            } else {
                return response()->json([
                    'code' => 1406,
                    'message' => __('error_message.1406')
                ]);
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
     * loginEvent/add
     * - 新增登入獎勵
     *
     * @bodyParam title string required 登入獎勵標題 Example: Title
     * @bodyParam content string required 登入獎勵內容 Example: Content
     * @bodyParam agent_id integer required 代理商編號  Example: 1
     * @bodyParam type integer 登入獎勵類型 (1:新會員,2:全部會員) Example: 1
     * @bodyParam how_days integer required 幾日內無開洗分紀錄 Example: 1
     * @bodyParam point integer required 參與活動贈送的點數 Example: 1
     * @bodyParam times integer 解鎖條件; 達到贈送點數的幾倍 Example: 1
     * @bodyParam number_of_people integer required 可參與的人數(0代表無上限) Example: 0
     * @bodyParam number_of_participants boolean 已參與的人數 Example: 1
     * @bodyParam start_time datetime 開始時間 Example: 0
     * @bodyParam end_time datetime 結束時間 Example: 0
     * @bodyParam repeat_hour datetime 重複參與時間間隔(最少1小時，0代表不可重複參加) Example: 0
     * @bodyParam vendor_id array 試玩廠商 Example: [1,2,3,4,5]
     * @bodyParam names array Example:  [{"language_id":1,"title":"title 1", "content":"content 1"},{"language_id":2,"name":"title 2","content":"content 2"}]
     * @bodyParam status boolean required 狀態(0:停用,1:正常) Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "系統編號"
     * }
     */
    public function create(Request $request)
    {
        $begin_transaction = false;
        try {
            $model = new LoginEvent();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            DB::beginTransaction();
            $begin_transaction = true;

            $data = array();
            $data['title'] = $request->title;
            $data['content'] = $request->content;
            $data['agent_id'] = isset($request->agent_id) ? $request->agent_id : $request->HOST;
            $data['type'] = $request->type;
            $data['how_days'] = $request->how_days;
            $data['point'] = $request->point;
            $data['times'] = $request->times;
            $data['number_of_people'] = $request->number_of_people;
            $data['start_time'] = date('Y-m-d H:i:s', strtotime($request->start_time . ' -8hour'));
            $data['end_time'] = date('Y-m-d H:i:s', strtotime($request->end_time . ' -8hour'));
            $data['repeat_hour'] = $request->repeat_hour;
            $data['vendor_id'] = $request->vendor_id;
            $data['status'] = isset($request->status) ? $request->status : 1;
            $data['admin'] = $request->ID;

            if ($result = $model->create($data)) {
                $names = array();
                $names['login_event_id'] = $result->id;

                if (is_array($request->languages)) {
                    foreach ($request->languages as $row) {
                        // 驗證資料
                        $validator = Validator::make($row, $model->rules_name, $this->messages());
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
                        LoginEventName::create($names);
                    }
                }

                DB::commit();
                $redis = new REDIS();
                $redis->delete([
                    'login_event'
                ]);

                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result->id
                ]);
            } else {
                DB::rollback();
                return response()->json([
                    'code' => 2001,
                    'message' => __('error_message.2001')
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            if ($begin_transaction) DB::rollback();
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            if ($begin_transaction) DB::rollback();
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * loginEvent/edit
     * -編輯登入獎勵
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam title string required 登入獎勵標題 Example: Title
     * @bodyParam content string required 登入獎勵內容 Example: Content
     * @bodyParam agent_id integer required 代理商編號  Example: 1
     * @bodyParam type integer 登入獎勵類型 (1:新會員,2:全部會員) Example: 1
     * @bodyParam how_days integer required 幾日內無開洗分紀錄 Example: 1
     * @bodyParam point integer required 參與活動贈送的點數 Example: 1
     * @bodyParam times integer 解鎖條件; 達到贈送點數的幾倍 Example: 1
     * @bodyParam number_of_people integer required 可參與的人數(0代表無上限) Example: 0
     * @bodyParam number_of_participants boolean 已參與的人數 Example: 1
     * @bodyParam start_time datetime 開始時間 Example: 0
     * @bodyParam end_time datetime 結束時間 Example: 0
     * @bodyParam repeat_hour datetime 重複參與時間間隔(最少1小時，0代表不可重複參加) Example: 0
     * @bodyParam vendor_id array 試玩廠商 Example: [1,2,3,4,5]
     * @bodyParam names array Example:  [{"language_id":1,"title":"title 1", "content":"content 1"},{"language_id":2,"name":"title 2","content":"content 2"}]
     * @bodyParam status boolean required 狀態(0:停用,1:正常) Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new LoginEvent();
            // 驗證POST資料
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
                $data['title'] = isset($request->title) ? $request->title : $row->title;
                $data['content'] = isset($request->content) ? $request->content : $row->content;
                $data['type'] = isset($request->type) ? $request->type : $row->type;
                $data['how_days'] = isset($request->how_days) ? $request->how_days : $row->how_days;
                $data['point'] = isset($request->point) ? $request->point : $row->point;
                $data['times'] = isset($request->times) ? $request->times : $row->times;
                $data['number_of_people'] = isset($request->number_of_people) ? $request->number_of_people : $row->number_of_people;
                $data['start_time'] = isset($request->start_time)
                    ? date('Y-m-d H:i:s', strtotime($request->start_time . ' -8hour'))
                    : $row->start_time;
                $data['end_time'] = isset($request->end_time)
                    ? date('Y-m-d H:i:s', strtotime($request->end_time . ' -8hour'))
                    : $row->end_time;
                $data['repeat_hour'] = isset($request->repeat_hour) ? $request->repeat_hour : $row->repeat_hour;
                $data['vendor_id'] = isset($request->vendor_id) ? $request->vendor_id : $row->vendor_id;
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    // 寫入不同語系名稱
                    $names = array();
                    $names['login_event_id'] = $request->id;
                    if (is_array($request->languages)) {
                        foreach ($request->languages as $row) {
                            // 驗證資料
                            $validator = Validator::make($row, $model->rules_name, $this->messages());
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
                            $name = LoginEventName::where('login_event_id', $request->id)
                                ->where('language_id', $row['language_id'])
                                ->get();
                            if (count($name) > 0) {
                                LoginEventName::where('login_event_id', $request->id)
                                    ->where('language_id', $row['language_id'])
                                    ->update($names);
                            } else {
                                LoginEventName::create($names);
                            }
                        }
                    }
                    DB::commit();
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                } else {
                    DB::rollback();
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001')
                    ]);
                }
            } else {
                DB::rollback();
                return response()->json([
                    'code' => 1406,
                    'message' => __('error_message.1406')
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
     * loginEvent/status
     * - 編輯狀態
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam status integer 狀態 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function modifyStatus(Request $request)
    {
        try {
            $model = new LoginEvent();
            $validator = Validator::make($request->all(), $model->rules_status, $this->messages());
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
                $data['status'] = ($request->status != '') ? $request->status : $row->status;
                if ($row->update($data)) {
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ]);
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
            'id.required' => __('error_message.1407'),
            'id.digits_between' => __('error_message.1414'),

            'vendor_id.required' => __('error_message.1415'),
            'vendor_id.digits_between' => __('error_message.1416'),

            'type_id.required' => __('error_message.1409'),
            'type_id.digits_between' => __('error_message.1410'),

            'title.required' => __('error_message.1906'),
            'title.between' => __('error_message.1907'),

            'content.required' => __('error_message.1908'),
            'content.between' => __('error_message.1909'),

            'type.required' => __('error_message.1942'),

            'how_days.required' => __('error_message.1943'),
            'how_days.digits_between' => __('error_message.1944'),

            'point.required' => __('error_message.1945'),
            'point.digits_between' => __('error_message.1946'),

            'times.required' => __('error_message.1947'),
            'times.digits_between' => __('error_message.1948'),

            'repeat_hour.required' => __('error_message.1949'),
            'repeat_hour.digits_between' => __('error_message.1950'),

            'number_of_people.required' => __('error_message.1951'),
            'number_of_people.digits_between' => __('error_message.1952'),

            'vendor_id.required' => __('error_message.1953'),

            'language_id.required' => __('error_message.2019'),
            'language_id.digits_between' => __('error_message.2020'),

            'status.required' => __('error_message.1403'),
            'status.boolean' => __('error_message.1403'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}