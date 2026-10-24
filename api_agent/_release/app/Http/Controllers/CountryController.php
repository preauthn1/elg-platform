<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Country;
use App\Models\CountryName;
use App\REDIS;
use DB;
use Validator;

class CountryController extends Controller
{

    /**
     * country/list
     * -國家列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "code": "代碼,ex:TW",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:台灣"
     *        },
     *         {
     *            "id": 3,
     *            "code": "HK",
     *            "name": "香港"
     *        }
     *    ]
     * }
     */
    public function dropdown(Request $request)
    {
        try {
            $key = array(
                'country',
                'list',
                $request->LANGUAGE
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = DB::table('countries as c')
                    ->leftJoin('country_names as n', function ($join) use ($request) {
                        $join->on('n.country_id', 'c.id')
                            ->where('n.language_id', $request->LANGUAGE);
                    })
                    ->where('status', 1)
                    ->select(
                        'c.id',
                        'c.code',
                        'c.country_code',
                        DB::raw("(CASE WHEN n.name is NULL THEN c.name ELSE n.name END) as name")
                    )
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
     * country/list
     * -國家列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "code": "代碼,ex:TW",
     *            "name": "預設名稱,ex:Taiwan",
     *            "country_code": "國碼,ex:886",
     *            "phone_rule": "手機驗證正規式,ex:^9\d{8}$",
     *            "status": "狀態0停用1正常,ex:1",
     *            "created_at": "2019-03-06 16:55:22",
     *            "updated_at": "2019-03-08 17:43:28",
     *            "admin": 1
     *        },
     *         {
     *            "id": 3,
     *            "code": "HK",
     *            "name": "Hong Kong",
     *            "country_code": "886",
     *            "phone_rule": "^9\d{8}$",
     *            "status": 1,
     *            "created_at": "2019-03-06 16:56:05",
     *            "updated_at": "2019-03-06 16:56:05",
     *            "admin": 1
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $key = array(
                'country',
                'list',
                $request->LANGUAGE
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = DB::table('countries as c')
                    ->leftJoin('country_names as n', function ($join) use ($request) {
                        $join->on('n.country_id', 'c.id')
                            ->where('n.language_id', $request->LANGUAGE);
                    })
                    ->select(
                        'c.id',
                        'c.code',
                        DB::raw("(CASE WHEN n.name is NULL THEN c.name ELSE n.name END) as name"),
                        'c.country_code',
                        'c.phone_rule',
                        'c.status',
                        'c.created_at',
                        'c.updated_at',
                        'c.admin'
                    )
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
     * country/view
     * -國家詳細資料
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data":
     *          {
     *            "id": "系統編號,ex:1",
     *            "code": "代碼,ex:TW",
     *            "name": "預設名稱,ex:Taiwan",
     *            "country_code": "國碼,ex:886",
     *            "phone_rule": "手機驗證正規式,ex:^9\d{8}$",
     *            "status": "狀態0停用1正常,ex:1",
     *            "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *            "updated_at": "最後更新時間,ex:2019-03-08 17:43:28",
     *            "admin": "最後更新者,ex:1",
     *            "names":[{"language_id":1,"name":"Taiwan"},{"language_id":2,"name":"台灣"}]
     *        }
     *
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Country();
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
                'country',
                'show',
                $request->id
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                // 先確認資料是否存在
                if ($data = $model->find($request->id)) {
                    // 取其他語系
                    $names = CountryName::select('language_id', 'name')->where('country_id', $request->id)->get();
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
     * country/add
     * -新增國家
     *
     * @bodyParam code string required 國家代碼 Example: TW
     * @bodyParam name string required 預設值國家名稱 Example: 台灣
     * @bodyParam names array option 多語國家名稱 Example: [{"language_id":1,"name":"Taiwan"},{"language_id":2,"name":"台灣"}]
     * @bodyParam country_code integer required 國碼 Example: 886
     * @bodyParam phone_rule string required 手機驗證正規表示式 Example: ^9\d{8}$
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
            $model = new Country();
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
            $data['country_code'] = $request->country_code;
            $data['phone_rule'] = $request->phone_rule;
            $data['status'] = $request->status;
            $data['admin'] = $request->ID;
            if ($result = Country::create($data)) {
                $names = array();
                $names['country_id'] = $result->id;
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
                        CountryName::create($names);
                    }
                }
                DB::commit();
                $key = array(
                    'country'
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
     * country/edit
     * -編輯國家
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam code string 國家代碼 Example: TW
     * @bodyParam name string 國家名稱 Example: 台灣
     * @bodyParam names array 多語國家名稱 Example: [{"language_id":1,"name":"Hello"},{"language_id":2,"name":"你好"}]
     * @bodyParam country_code integer  國碼 Example: 886
     * @bodyParam phone_rule string  手機驗證正規表示式 Example: ^9\d{8}$
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
            $model = new Country();
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
                $data['code'] = isset($request->code) ? $request->code : $row->code;
                $data['name'] = isset($request->name) ? $request->name : $row->name;
                $data['country_code'] = isset($request->country_code) ? $request->country_code : $row->country_code;
                $data['phone_rule'] = isset($request->phone_rule) ? $request->phone_rule : $row->phone_rule;
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    $names = array();
                    $names['country_id'] = $request->id;
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
                            $country_name = CountryName::where('country_id', $request->id)->where('language_id', $row['language_id'])->get();
                            if (count($country_name) > 0) {
                                CountryName::where('country_id', $request->id)->where('language_id', $row['language_id'])->update($names);
                            } else {
                                CountryName::create($names);
                            }
                        }
                    }
                    DB::commit();
                    $key = array(
                        'country'
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
     * -停用國家
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
            $model = new Country();
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
                        'country'
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

    public function moblie_list(Request $request)
    {
        try {
            $model = DB::table('sms_gateways as sg')
                ->join('sms_gateway_countries as sgc', function ($join) {
                    $join->on('sgc.sms_gateway_id', 'sg.id')
                        ->where('sgc.status', 1);
                })
                ->join('countries as c', function ($join) {
                    $join->on('c.id', 'sgc.country_id')
                        ->where('c.status', 1);
                })
                ->leftJoin('country_names as cn', function ($join) use ($request) {
                    $join->on('cn.country_id', 'c.id')
                        ->where('cn.language_id', $request->LANGUAGE);
                })
                ->where('sg.id', $request->id);

            $data = $model->select(
                'c.id',
                'c.code',
                'c.country_code',
                DB::raw('IFNULL(cn.name, c.name) as name')
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

    private function messages()
    {
        return [
            'id.required' => __('error_message.2021'),
            'id.digits_between' => __('error_message.2022'),

            'code.required' => __('error_message.1704'),
            'code.between' => __('error_message.1705'),
            'code.r_alpha_num' => __('error_message.1706'),

            'name.required' => __('error_message.1707'),
            'name.between' => __('error_message.1708'),

            'names.array' => __('error_message.1709'),

            'country_code.required' => __('error_message.1710'),
            'country_code.digits_between' => __('error_message.1741'),

            'phone_rule.required' => __('error_message.1742'),
            'phone_rule.between' => __('error_message.1743'),

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
