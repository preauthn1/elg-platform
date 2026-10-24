<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use DB;
use Validator;
use App\PrizeType;
use App\PrizeTypeName;

class PrizeTypeController extends Controller
{

    /**
     * prizeType/list
     * -獎勵類型列表
     *
     * @bodyParam status boolean required 狀態 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "預設名稱,ex:Scratch",
     *            "status": "狀態0停用1正常,ex:1",
     *            "created_at": "建立時間,ex:2019-03-08 17:55:41",
     *            "updated_at": "最後編輯時間,ex:2019-03-08 17:55:41",
     *            "admin": "最後編輯者,ex:1"
     *        },
     *        {
     *            "id": 2,
     *            "name": "Roulette",
     *            "status": 1,
     *            "created_at": "2019-03-08 17:55:41",
     *            "updated_at": "2019-03-08 17:55:41",
     *            "admin": 1
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new PrizeType();
            // 驗證POST資料
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
                'prizeType',
                'list',
                $request->status,
                $request->LANGUAGE
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $name = DB::raw("(CASE WHEN n.name is NULL THEN c.name ELSE n.name END) as name");
                $data = DB::table('prize_types as c')->leftJoin('prize_type_names as n', function ($join) use ($request) {
                    $join->on('n.type_id', '=', 'c.id')
                        ->where('n.language_id', '=', $request->LANGUAGE);
                })
                    ->select('c.id', $name, 'c.status', 'c.created_at', 'c.updated_at', 'c.admin');
                if (isset($request->status)) {
                    $data->where('status', 1);
                }
                $data = $data->get();
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
     * prizeType/view
     * -獎勵類型詳細資料
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "name": "預設名稱,ex:Scratch",
     *            "status": "狀態0停用1正常,ex:1",
     *            "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *            "updated_at": "最後更新時間,ex:2019-03-08 17:43:28",
     *            "admin": "最後更新者,ex:1",
     *            "names":[{"language_id":1,"name":"Scratch"},{"language_id":2,"name":"刮刮卡"}]
     *        }
     *    ]
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new PrizeType();
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
                'prizeType',
                'show',
                $request->id
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                // 先確認資料是否存在
                if ($data = $model->find($request->id)) {
                    // 取其他語系
                    $names = PrizeTypeName::select('language_id', 'name')->where('type_id', $request->id)->get();
                    $data['names'] = $names;
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $data
                    ]);
                } else {
                    return response()->json([
                        'code' => 1408,
                        'message' => __('error_message.1408')
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
     * prizeType/add
     * -新增獎勵類型
     *
     * @bodyParam name string required 預設獎勵類型名稱 Example: Scratch
     * @bodyParam names array option 多語獎勵類型名稱 Example: [{"language_id":1,"name":"Scratch"},{"language_id":2,"name":"刮刮卡"}]
     * @bodyParam status boolean required 狀態(0:停用,1:啟用) Example: 1
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
            $model = new PrizeType();
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
            $data['status'] = $request->status;
            $data['admin'] = $request->ID;
            if ($result = $model->create($data)) {
                $names = array();
                $names['type_id'] = $result->id;
                if (is_array($request->names)) {
                    foreach ($request->names as $row) {
                        // 驗證資料
                        $validator = Validator::make($row, $model->rules_name, $this->messages());
                        if (! $validator->passes()) {
                            return response()->json([
                                'code' => 2002,
                                'message' => __('error_message.2002'),
                                'data' => $validator->errors()
                                    ->all()
                            ]);
                        }
                        $names['language_id'] = $row['language_id'];
                        $names['name'] = $row['name'];
                        PrizeTypeName::create($names);
                    }
                }
                DB::commit();
                $key = array(
                    'prizeType',
                    'list'
                );
                $redis = new REDIS();
                $redis->delete($key);
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
     * prizeType/edit
     * -編輯獎勵類型
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam name string 預設獎勵類型名稱 Example: Scratch
     * @bodyParam names array 多語獎勵類型名稱 Example: [{"language_id":1,"name":"Scratch"},{"language_id":2,"name":"刮刮卡"}]
     * @bodyParam status boolean 狀態(0:停用,1:啟用) Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new PrizeType();
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
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    $names = array();
                    $names['type_id'] = $request->id;
                    if (is_array($request->names)) {
                        foreach ($request->names as $row) {
                            // 驗證資料
                            $validator = Validator::make($row, $model->rules_name, $this->messages());
                            if (! $validator->passes()) {
                                return response()->json([
                                    'code' => 2002,
                                    'message' => __('error_message.2002'),
                                    'data' => $validator->errors()
                                        ->all()
                                ]);
                            }
                            $names['language_id'] = $row['language_id'];
                            $names['name'] = $row['name'];
                            $prizeType_name = PrizeTypeName::where('type_id', $request->id)->where('language_id', $row['language_id'])->get();
                            if (count($prizeType_name) > 0) {
                                PrizeTypeName::where('type_id', $request->id)->where('language_id', $row['language_id'])->update($names);
                            } else {
                                PrizeTypeName::create($names);
                            }
                        }
                    }
                    DB::commit();
                    $key = array(
                        'prizeType',
                        'list'
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
                    'code' => 1408,
                    'message' => __('error_message.1408')
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
     * prizeType/delete
     * -刪除獎勵類型
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
            $model = new PrizeType();
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
                $result = $model->find($request->id)->update(array(
                    'status' => 0,
                    'admin' => $request->ID
                ));
                if ($result) {
                    $key = array(
                        'prizeType'
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
                    'code' => 1408,
                    'message' => __('error_message.1408')
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
            'id.required' => __('error_message.1409'),
            'id.digits_between' => __('error_message.1410'),

            'name.required' => __('error_message.1411'),
            'name.between' => __('error_message.1412'),

            'names.array' => __('error_message.1413'),

            'language_id.required' => __('error_message.2019'),
            'language_id.digits_between' => __('error_message.2020'),

            'status.required' => __('error_message.2009'),
            'status.boolean' => __('error_message.2009')
        ];
    }
}
