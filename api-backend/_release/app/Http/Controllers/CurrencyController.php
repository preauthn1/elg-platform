<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use Validator;
use App\Currency;
use App\CurrencyName;
use App\REDIS;

class CurrencyController extends Controller
{

    /**
     * currency/list
     * -幣種列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "code": "代碼,ex:TWD",
     *            "name": "名稱,ex:New Taiwan dollar",
     *            "rate": "匯率,ex:1.00000",
     *            "status": "狀態0停用1正常,ex:1",
     *            "created_at": "2019-03-08 17:55:41",
     *            "updated_at": "2019-03-08 17:55:41",
     *            "admin": 1
     *        },
     *        {
     *            "id": 2,
     *            "code": "THB",
     *             "name": "Thai baht",
     *            "rate": "0.90000",
     *            "status": 1,
     *            "created_at": "2019-03-08 17:55:41",
     *            "updated_at": "2019-03-08 17:55:41",
     *            "admin": 1
     *        }
     *    ]
     * }
     */
    public function list()
    {
        try {
            $key = array(
                'currency',
                'list'
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = DB::table('currencies as c')->leftJoin('currency_names as n', 'n.currency_id', '=', 'c.id')
                    ->select('c.id', 'c.code', 'c.name', 'c.rate', 'c.status', 'c.created_at', 'c.updated_at', 'c.admin')
                    ->groupBy('c.id')
                    ->get();
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
     * currency/view
     * -國家詳細資料
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "code": "代碼,ex:TWD",
     *            "name": "預設名稱,New Taiwan dollar",
     *            "rate": "匯率,ex:1",
     *            "status": "狀態0停用1正常,ex:1",
     *            "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *            "updated_at": "最後更新時間,ex:2019-03-08 17:43:28",
     *            "admin": "最後更新者,ex:1",
     *            "names":[{"language_id":1,"name":"New Taiwan dollar"},{"language_id":2,"name":"台幣"}]
     *        }
     *    ]
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Currency();
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
                'currency',
                'show',
                $request->id
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                // 先確認資料是否存在
                if ($data = $model->find($request->id)) {
                    // 取其他語系
                    $names = CurrencyName::select('language_id', 'name')->where('currency_id', $request->id)->get();
                    $data['names'] = $names;
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $data
                    ]);
                } else {
                    return response()->json([
                        'code' => 1701,
                        'message' => __('error_message.1701')
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
     * currency/add
     * -新增幣種
     *
     * @bodyParam code string required 貨幣代碼 Example: TWD
     * @bodyParam name string required 預設貨幣名稱 Example: 台幣
     * @bodyParam rate float required 匯率 Example: 1
     * @bodyParam names array option 多語貨幣名稱 Example: [{"language_id":1,"name":"New Taiwan Dollar"},{"language_id":2,"name":"台幣"}]
     * @bodyParam status boolean required 狀態(0:停用,1:啟用) Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "系統編號"
     * }
     */
    public function create(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new Currency();
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
            $data = array();
            $data['code'] = $request->code;
            $data['name'] = $request->name;
            $data['rate'] = $request->rate;
            $data['status'] = $request->status;
            $data['admin'] = $request->ID;
            if ($result = Currency::create($data)) {
                $names = array();
                $names['currency_id'] = $result->id;
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
                        CurrencyName::create($names);
                    }
                }
                DB::commit();
                $key = array(
                    'currency'
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
     * currency/edit
     * -編輯幣種
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam code string option 貨幣代碼 Example: TWD
     * @bodyParam name string option 貨幣名稱 Example: 台幣
     * @bodyParam rate float 匯率 Example: 1
     * @bodyParam names array option 多語貨幣名稱 Example: [{"language_id":1,"name":"New Taiwan Dollar"},{"language_id":2,"name":"台幣"}]
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
            $model = new Currency();
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
                $data['code'] = $request->code ? $request->code : $row->code;
                $data['name'] = $request->name ? $request->name : $row->name;
                $data['rate'] = $request->rate ? $request->rate : $row->rate;
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    $names = array();
                    $names['currency_id'] = $request->id;
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
                            $names['name'] = $row['name'];
                            $names['language_id'] = $row['language_id'];
                            $currency_name = CurrencyName::where('currency_id', $request->id)->where('language_id', $row['language_id'])->get();
                            if (count($currency_name) > 0) {
                                CurrencyName::where('currency_id', $request->id)->where('language_id', $row['language_id'])->update($names);
                            } else {
                                CurrencyName::create($names);
                            }
                        }
                    }
                    DB::commit();
                    $key = array(
                        'currency'
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
                    'code' => 1701,
                    'message' => __('error_message.1701')
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
     * country/delete
     * -停用幣種
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
            $model = new Currency();
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
                        'currency'
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
                    'code' => 1701,
                    'message' => __('error_message.1701')
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
            'id.required' => __('error_message.1712'),
            'id.digits_between' => __('error_message.1720'),

            'code.required' => __('error_message.1714'),
            'code.between' => __('error_message.1715'),
            'code.r_alpha_num' => __('error_message.1716'),

            'name.required' => __('error_message.1717'),
            'name.between' => __('error_message.1718'),

            'rate.required' => __('error_message.1721'),
            'rate.float' => __('error_message.1722'),

            'names.array' => __('error_message.1719'),

            'language_id.required' => __('error_message.2019'),
            'language_id.digits_between' => __('error_message.2020'),

            'status.required' => __('error_message.2009'),
            'status.boolean' => __('error_message.2009'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}
