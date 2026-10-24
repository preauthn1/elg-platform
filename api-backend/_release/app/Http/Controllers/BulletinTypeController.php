<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use DB;
use Validator;
use App\BulletinType;
use App\BulletinTypeName;

class BulletinTypeController extends Controller
{

    /**
     * bulletinType/list
     * -公告類型列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "預設名稱,ex:最新消息",
     *            "created_at": "新增時間,ex:2019-04-19 16:25:07",
     *            "updated_at": "最後更新時間,ex:2019-04-19 16:25:07",
     *            "admin": "最後更新者系統編號,ex:1"
     *        },
     *        {
     *            "id": 2,
     *            "name": "優惠消息",
     *            "created_at": "2019-04-19 16:25:07",
     *            "updated_at": "2019-04-19 16:25:07",
     *            "admin": 1
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $key = array(
                'bulletinType',
                'list',
                $request->LANGUAGE
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $name = DB::raw("(CASE WHEN n.name is NULL THEN c.name ELSE n.name END) as name");
                $data = DB::table('bulletin_types as c')->leftJoin('bulletin_type_names as n', function ($join) use ($request) {
                    $join->on('n.type_id', '=', 'c.id')
                        ->where('n.language_id', '=', $request->LANGUAGE);
                })
                    ->select('c.id', 'c.name', 'c.created_at', 'c.updated_at', 'c.admin')
                    ->where('status', 1)
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
     * bulletinType/view
     * -公告類型詳細資料
     *
     * @bodyParam id integer required 系統編號 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data":{
     *            "id": "系統編號,ex:1",
     *            "name": "預設名稱,ex:最新消息",
     *            "status": "狀態0:停用1:正常,ex:1",
     *            "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *            "updated_at": "最後更新時間,ex:2019-03-08 17:43:28",
     *            "admin": "最後更新者,ex:1",
     *            "names": [{"language_id":1,"name":"News"},{"language_id":2,"name":"最新消息"}]
     *        }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new BulletinType();
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
                'bulletinType',
                'show',
                $request->id
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                // 先確認資料是否存在
                if ($data = $model->find($request->id)) {
                    // 取其他語系
                    $data['names'] = BulletinTypeName::select('language_id', 'name')->where('type_id', $request->id)->get();
                    $redis->set($key, $data);
                } else {
                    return response()->json([
                        'code' => 1607,
                        'message' => __('error_message.1607')
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
     * bulletinType/add
     * -新增公告類型
     *
     * @bodyParam name string required 預設公告類型名稱 Example: News
     * @bodyParam names array option 多語公告類型名稱 Example: [{"language_id":1,"name":"News"},{"language_id":2,"name":"新聞"}]
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
            $model = new BulletinType();
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
            $data['status'] = 1;
            $data['admin'] = $request->ID;
            if ($result = $model->create($data)) {
                $names = array();
                $names['type_id'] = $result->id;
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
                    BulletinTypeName::create($names);
                }
                DB::commit();
                $key = array(
                    'bulletinType',
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
     * bulletinType/edit
     * -編輯公告類型
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam name string required 預設公告類型名稱 Example: News
     * @bodyParam names array option 多語公告類型名稱 Example: [{"language_id":1,"name":"News"},{"language_id":2,"name":"新聞"}]
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new BulletinType();
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
                $data['name'] = $request->name ? $request->name : $row->name;
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    $names = array();
                    $names['type_id'] = $request->id;
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
                        $bulletinType_name = BulletinTypeName::where('type_id', $request->id)->where('language_id', $row['language_id'])->get();
                        if (count($bulletinType_name) > 0) {
                            BulletinTypeName::where('type_id', $request->id)->where('language_id', $row['language_id'])->update($names);
                        } else {
                            BulletinTypeName::create($names);
                        }
                    }
                    DB::commit();
                    $key = array(
                        'bulletinType'
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
                    'code' => 1607,
                    'message' => __('error_message.1607')
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
     * bulletinType/delete
     * -停用公告類型
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
            $model = new BulletinType();
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
                        'bulletinType'
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
                    'code' => 1607,
                    'message' => __('error_message.1607')
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

            'name.required' => __('error_message.1619'),
            'name.between' => __('error_message.1620'),

            'names.array' => __('error_message.1618'),

            'language_id.required' => __('error_message.2019'),
            'language_id.digits_between' => __('error_message.2020'),

            'status.boolean' => __('error_message.2009'),

            'page.required' => __('error_message.2005'),
            'page.integer' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.integer' => __('error_message.2008')
        ];
    }
}
