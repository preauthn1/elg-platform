<?php
namespace App\Http\Controllers;

use App\Helpers\SQLHelper;
use Illuminate\Http\Request;
use App\Models\AdvertisementPath;
use App\REDIS;
use DB;
use Validator;

class AdvertisementPathController extends Controller
{

    /**
     * advertisementPath/list
     * -廣告版位列表
     *
     * @bodyParam id int  類型編號 Example: 1
     * @bodyParam status boolean 狀態 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "name": "位置名稱,ex:index_top",
     *            "column": "欄位數量,ex:2",
     *            "width": "圖片寬度,ex:1280",
     *            "height": "圖片高度,ex:300",
     *            "status": "狀態,ex:1",
     *            "created_at": "建立時間,ex:2019-01-01 00:00:00",
     *            "updated_at": "更新時間,ex:2019-12-31 23:59:59",
     *            "admin": 1
     *          }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new AdvertisementPath();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $model = DB::table('advertisement_paths as ap')
                ->leftJoin('advertisement_path_names as apn', function ($join) use ($request) {
                    $join->on('ap.id', 'apn.advertisement_path_id')
                        ->where('apn.language_id', $request->LANGUAGE);
                });
            if ($request->id) {
                $model = $model->where('ap.id', $request->id);
            }
            if (isset($request->status)) {
                $model = $model->where('ap.status', $request->status);
            }
            $data = $model->orderBy('ap.id', 'desc')
                ->select(
                    'ap.id',
                    DB::raw('IFNULL(apn.name, ap.name) as name'),
                    'ap.column',
                    'ap.width',
                    'ap.height',
                    'ap.status',
                    DB::raw('DATE_ADD(ap.created_at, INTERVAL 8 HOUR) AS created_at'),
                    DB::raw('DATE_ADD(ap.updated_at, INTERVAL 8 HOUR) AS updated_at')
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
     * advertisementPath/view
     * -廣告版位明細
     *
     * @bodyParam id integer required 版位編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *        "id": "系統編號,ex:1",
     *        "name": "位置名稱,ex:index_top",
     *        "column": "欄位數量,ex:2",
     *        "width": "圖片寬度,ex:1280",
     *        "height": "圖片高度,ex:300",
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
            $model = new AdvertisementPath();
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
                'advertisement_path',
                'show',
                $request->id
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = $model->where('id', $request->id)->First();
                if (! $data) {
                    return response()->json([
                        'code' => 1116,
                        'message' => __('error_message.1116')
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
     * advertisementPath/add
     * -新增廣告版位
     *
     * @bodyParam name string required 類型名稱 Example: index_top
     * @bodyParam column integer required 欄位數量 Example: 2
     * @bodyParam width integer required 圖片寬度 Example: 1280
     * @bodyParam height integer required 圖片高度 Example: 300
     * @bodyParam status boolean required 狀態 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "系統編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new AdvertisementPath();
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
            $data['name'] = $request->name;
            $data['column'] = $request->column;
            $data['width'] = $request->width;
            $data['height'] = $request->height;
            $data['status'] = $request->status;
            $data['admin'] = $request->ID;
            if ($result = $model->create($data)) {
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'advertisement_path'
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
     * advertisementPath/edit
     * -編輯廣告版位
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam name string  類型名稱 Example: index_top
     * @bodyParam column integer 欄位數量 Example: 2
     * @bodyParam width integer 圖片寬度 Example: 1280
     * @bodyParam height integer 圖片高度 Example: 300
     * @bodyParam status boolean  類型系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new AdvertisementPath();
            if ($row = $model->find($request->id)) {
                $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
                if (! $validator->passes()) {
                    return response()->json([
                        'code' => 2002,
                        'message' => __('error_message.2002'),
                        'data' => $validator->errors()
                            ->all()
                    ]);
                }
                $data = array();
                $data['name'] = isset($request->name) ? $request->name : $row->name;
                $data['column'] = isset($request->column) ? $request->column : $row->column;
                $data['width'] = isset($request->width) ? $request->width : $row->width;
                $data['height'] = isset($request->height) ? $request->height : $row->height;
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'advertisement_path'
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
                    'code' => 1116,
                    'message' => __('error_message.1116')
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
     * advertisementPath/delete
     * -停用廣告版位
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
            if (! AdvertisementPath::find($request->id)) {
                return response()->json([
                    'code' => 1116,
                    'message' => __('error_message.1116')
                ]);
            }
            $result = AdvertisementPath::find($request->id)->update(array(
                'status' => 0,
                'admin' => $request->ID
            ));
            if ($result) {
                $key = array(
                    'advertisement_path'
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
            'id.required' => __('error_message.1103'),
            'id.digits_between' => __('error_message.1104'),

            'name.required' => __('error_message.1117'),
            'name.r_alpha_dash' => __('error_message.1118'),
            'name.between' => __('error_message.1119'),

            'column.required' => __('error_message.1121'),
            'column.digits_between' => __('error_message.1122'),

            'width.required' => __('error_message.1112'),
            'width.digits_between' => __('error_message.1113'),

            'height.required' => __('error_message.1114'),
            'height.digits_between' => __('error_message.1115'),

            'status.required' => __('error_message.2009'),
            'status.boolean' => __('error_message.2009'),

            'page.required' => __('error_message.2005'),
            'page.integer' => __('error_message.2006'),
            'per_page.required' => __('error_message.2007'),
            'per_page.integer' => __('error_message.2008')
        ];
    }
}
