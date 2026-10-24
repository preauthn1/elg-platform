<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Bulletin;
use App\REDIS;
use Validator;

class BulletinController extends Controller
{

    /**
     * bulletin/list
     * -公告列表
     *
     * @bodyParam type_id integer 類型編號 Example: 1
     * @bodyParam language_id integer 語系編號 Example: 1
     * @bodyParam country_id integer 國家編號 Example: 1
     * @bodyParam agent_id integer 代理編號 Example: 1
     * @bodyParam title string 公告標題 Example: Hello
     * @bodyParam content string 公告標題 Example: Hello
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "type_id": "類型編號,ex:1",
     *            "language_id": "語系編號,ex:1",
     *            "country_id": "國家編號,ex:1",
     *            "agent_id": "代理編號,ex:1",
     *            "title": "標題,ex:Hello World",
     *            "content": "內容,ex:Test",
     *            "start_time": "開始時間,ex:2019-04-01 00:00:00",
     *            "end_time": "結束時間,ex:2019-04-01 23:59:59",
     *            "created_at": "新增時間,ex:2019-03-01 23:59:59",
     *            "updated_at": "最後更新時間,ex:2019-04-01 00:00:00",
     *            "admin": "最後更新者,ex:1"
     *          },
     *          {
     *            "id": 2,
     *            "type_id": 1,
     *            "language_id": 2,
     *            "country_id": 0,
     *            "agent_id": 0,
     *            "title": "Hi",
     *            "content": "Test 2",
     *            "start_time": "2019-04-01 00:00:00",
     *            "end_time": "2019-04-01 23:59:59",
     *            "created_at": "2019-03-01 23:59:59",
     *            "updated_at": "2019-04-01 00:00:00",
     *            "admin": 1
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
            $model = new Bulletin();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'bulletin',
                'list',
                $request->type_id,
                $request->language_id,
                $request->country_id,
                $request->agent_id,
                $request->title,
                $request->content,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'bulletin',
                'list',
                $request->type_id,
                $request->language_id,
                $request->country_id,
                $request->agent_id,
                $request->title,
                $request->content,
                'total'
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                if ($request->type_id) {
                    $model = $model->where('type_id', $request->type_id);
                }
                if ($request->language_id) {
                    $model = $model->where('language_id', $request->language_id);
                }
                if ($request->country_id) {
                    $model = $model->where('country_id', $request->country_id);
                }
                if ($request->agent_id) {
                    $model = $model->where('agent_id', $request->agent_id);
                }
                if ($request->title) {
                    $model = $model->where('title', 'like', '%' . $request->title . '%');
                }
                if ($request->content) {
                    $model = $model->where('content', 'like', '%' . $request->content . '%');
                }
                $model = $model->where('is_public', 1);
                $total = $model->count();
                $data = $model->select('id', 'type_id', 'language_id', 'country_id', 'agent_id', 'title', 'content', 'start_time', 'end_time', 'created_at', 'updated_at', 'admin')
                    ->orderBy('updated_at', 'desc')
                    ->orderBy('id', 'desc')
                    ->skip(($request->page - 1) * $request->per_page)
                    ->take($request->per_page)
                    ->get();
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
     * bulletin/view
     * -公告詳細資料
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *            "id": "系統編號,ex:1",
     *            "type_id": "類型編號,ex:1",
     *            "language_id": "語系編號,ex:1",
     *            "country_id": "國家編號,ex:1",
     *            "agent_id": "代理編號,ex:1",
     *            "title": "標題,ex:Hello World",
     *            "content": "內容,ex:Test",
     *            "start_time": "開始時間,ex:2019-04-01 00:00:00",
     *            "end_time": "結束時間,ex:2019-04-01 23:59:59",
     *            "created_at": "新增時間,ex:2019-03-01 23:59:59",
     *            "updated_at": "最後更新時間,ex:2019-04-01 00:00:00",
     *            "admin": "最後更新者,ex:1"
     *          }
     * }
     */
    function show(Request $request)
    {
        try {
            $model = new Bulletin();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'bulletin',
                'show',
                $request->id
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = $model->select('id', 'type_id', 'language_id', 'country_id', 'agent_id', 'title', 'content', 'start_time', 'end_time', 'created_at', 'updated_at', 'admin')
                    ->where('id', $request->id)
                    ->where('is_public', 1)
                    ->First();
                if ($data != '') {
                    $redis->set($key, $data);
                } else {
                    return response()->json([
                        'code' => 1601,
                        'message' => __('error_message.1601')
                    ]);
                }
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
     * bulletin/add
     * -新增公告
     *
     * @bodyParam type_id integer required 類型系統編號 Example: 1
     * @bodyParam language_id integer required 語系系統編號 Example: 1
     * @bodyParam country_id integer 國家系統編號 Example: 1
     * @bodyParam title string required 標題 Example: Hello World
     * @bodyParam content string required 內容 Example: test
     * @bodyParam start_time datetime required 公告開始時間 Example: 2019-03-01 23:59:59,
     * @bodyParam end_time datetime required 公告結束時間 Example: 2019-04-01 23:59:59,
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "系統編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new Bulletin();
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $data = array();
            $data['type_id'] = $request->type_id;
            $data['language_id'] = $request->language_id;
            $data['country_id'] = $request->country_id;
            $data['agent_id'] = 0;
            $data['title'] = $request->title;
            $data['content'] = $request->content;
            $data['start_time'] = $request->start_time;
            $data['end_time'] = $request->end_time;
            $data['is_public'] = 1;
            $data['admin'] = $request->ID;
            if ($result = $model->create($data)) {
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'bulletin',
                    'list'
                ));
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result->id
                ]);
            } else {
                return response()->json([
                    'code' => 2001,
                    'message' => __('error_message.2001')
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

    /**
     * bulletin/edit
     * -編輯公告
     *
     * @bodyParam id integer required 公告系統編號 Example: 1
     * @bodyParam type_id integer  類型系統編號 Example: 1
     * @bodyParam language_id integer  語系系統編號 Example: 1
     * @bodyParam country_id integer 國家系統編號 Example: 1
     * @bodyParam title string required 標題 Example: Hello World
     * @bodyParam content string required 內容 Example: test
     * @bodyParam start_time datetime required 公告開始時間 Example: 2019-03-01 23:59:59,
     * @bodyParam end_time datetime required 公告結束時間 Example: 2019-04-01 23:59:59,
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new Bulletin();
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            if ($row = $model->find($request->id)) {
                $data = array();
                $data['type_id'] = ($request->type_id != null) ? $request->type_id : $row->type_id;
                $data['language_id'] = ($request->language_id != null) ? $request->language_id : $row->language_id;
                $data['country_id'] = ($request->country_id != null) ? $request->country_id : $row->country_id;
                $data['title'] = ($request->title != null) ? $request->title : $row->title;
                $data['content'] = ($request->content != null) ? $request->content : $row->content;
                $data['start_time'] = ($request->start_time != null) ? $request->start_time : $row->start_time;
                $data['end_time'] = ($request->end_time != null) ? $request->end_time : $row->end_time;
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'bulletin',
                        'list'
                    ));
                    $redis->delete(array(
                        'bulletin',
                        'show',
                        $request->id
                    ));
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
                return response()->json([
                    'code' => 1601,
                    'message' => __('error_message.1601')
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
            'id.required' => __('error_message.1608'),
            'id.digits_between' => __('error_message.1606'),

            'type_id.required' => __('error_message.1609'),
            'type_id.digits_between' => __('error_message.1602'),

            'language_id.required' => __('error_message.2019'),
            'language_id.digits_between' => __('error_message.2020'),

            'country_id.digits_between' => __('error_message.2022'),

            'agent_id.digits_between' => __('error_message.2018'),

            'title.required' => __('error_message.1611'),
            'title.between' => __('error_message.1621'),

            'content.required' => __('error_message.1612'),

            'start_time.required' => __('error_message.1613'),
            'start_time.date' => __('error_message.1615'),

            'end_time.required' => __('error_message.1614'),
            'end_time.date' => __('error_message.1616'),
            'end_time.after' => __('error_message.1617'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}
