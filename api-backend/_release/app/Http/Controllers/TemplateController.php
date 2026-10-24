<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Template;
use App\REDIS;
use Validator;

class TemplateController extends Controller
{

    /**
     * template/list
     * -模版列表
     *
     * @bodyParam name string 模版名稱 Example: index
     * @bodyParam status boolean  狀態 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "name": "模版名稱,ex:pink",
     *            "thumbnail": "模版縮圖,ex:http://xxx.xxx.xxx/xxx.jpg",
     *            "status": "狀態,ex:1",
     *            "created_at": "建立時間,ex:2019-01-01 00:00:00",
     *            "updated_at": "更新時間,ex:2019-12-31 23:59:59",
     *            "admin": 1
     *          },
     *          {
     *            "id": 2,
     *            "name": "blue",
     *            "thumbnail": "http://xxx.xxx.xxx/xxx.jpg",
     *            "status": 0,
     *            "created_at": "2019-01-01 00:00:00",
     *            "updated_at": "2019-12-31 23:59:59",
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
     *
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new Template();
            $sort = $request->sort ? $request->sort : 'updated_at';
            // 驗證排序欄位是存在的
            if (! in_array($sort, $model->getFillable())) {
                return response()->json([
                    'code' => 9002,
                    'message' => __('error_message.9002')
                ]);
            }
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // Redis名稱
            $key = array(
                'template',
                'list',
                $request->name,
                $request->status,
                $sort,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'template',
                'list',
                $request->name,
                $request->status,
                'total'
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                // Redis不存在就重新查詢
                if (isset($request->name)) {
                    $model = $model->where('name', 'like', '%' . $request->name . '%');
                }
                if (isset($request->status)) {
                    $model = $model->where('status', $request->status);
                }
                $total = $model->count();
                $data = $model->orderBy($sort, 'desc')
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
     * template/view
     * -模版明細
     *
     * @bodyParam id integer required 模版編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *        "id": "系統編號,ex:1",
     *        "name": "模版名稱,ex:pink",
     *        "thumbnail": "模版縮圖,ex:http://xxx.xxx.xxx/xxx.jpg",
     *        "status": "狀態,ex:1",
     *        "created_at": "建立時間,ex:2019-01-01 00:00:00",
     *        "updated_at": "更新時間,ex:2019-12-31 23:59:59",
     *        "admin": 1
     *   }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Template();
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
                'template',
                'show',
                $request->id
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = $model->where('id', $request->id)->First();
                if (! $data) {
                    return response()->json([
                        'code' => 1203,
                        'message' => __('error_message.1203')
                    ]);
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
     * template/add
     * -新增模版
     *
     * @bodyParam name string required 類型名稱 Example: template2
     * @bodyParam thumbnail string required base64 file 模版縮圖
     * @bodyParam status boolean 狀態:0停用,1正常(預設0) Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "系統編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $data = array();
            $model = new Template();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $data['name'] = $request->name;
            // 移動圖檔至本地及上傳到GCP
            if (isset($request->thumbnail) && $request->thumbnail != '') {
                $path = $this->uploadBase64File($request->thumbnail);
                if ($path['code'] != 1000) {
                    return response()->json([
                        'code' => $path['code'],
                        'message' => $path['message']
                    ]);
                }
                $data['thumbnail'] = $path['message'];
            }
            $data['status'] = isset($request->status) ? $request->status : 0;
            $data['admin'] = $request->ID;
            if ($result = $model->create($data)) {
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'template'
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
     * template/edit
     * -修改模版
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam name string 類型名稱 Example: template2
     * @bodyParam thumbnail string base64 file 模版縮圖
     * @bodyParam status boolean 狀態:0停用,1正常 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new Template();
            // 驗證POST資料
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
                $data['name'] = isset($request->name) ? $request->name : $row->name;
                // 移動圖檔至本地及上傳到GCP
                if (isset($request->thumbnail) && $request->thumbnail != '') {
                    $path = $this->uploadBase64File($request->thumbnail);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $data['thumbnail'] = $path['message'];
                }
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'template'
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
                    'code' => 1203,
                    'message' => __('error_message.1203')
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
     * template/delete
     * -停用模版
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function delete(Request $request)
    {
        try {
            $model = new Template();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_delete, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 先確認記錄是否存在
            if ($model->find($request->id)) {
                // 更新記錄狀態
                if ($model->find($request->id)->update(array(
                    'status' => 0,
                    'admin' => $request->ID
                ))) {
                    $key = array(
                        'template'
                    );
                    $redis = new REDIS();
                    $redis->delete($key);
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
                    'code' => 1203,
                    'message' => __('error_message.1203')
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
            'id.required' => __('error_message.1206'),
            'id.digits_between' => __('error_message.1207'),

            'name.required' => __('error_message.1201'),
            'name.between' => __('error_message.1214'),

            'thumbnail.required' => __('error_message.2023'),
            'thumbnail.base64image' => __('error_message.2023'),

            'status.boolean' => __('error_message.1202'),

            'page.required' => __('error_message.2005'),
            'page.integer' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.integer' => __('error_message.2008')
        ];
    }
}
