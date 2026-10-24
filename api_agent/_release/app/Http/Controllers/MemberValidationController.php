<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Member;
use App\Models\MemberValidation;
use App\Models\AgentValidationItem;
use App\REDIS;
use Validator;
use DB;

class MemberValidationController extends Controller
{

    /**
     * memberValidation/list
     * -會員驗證列表
     *
     * @bodyParam member_id integer required 會員編號 Example:1
     * @bodyParam status integer 認證狀態0:未通過,1:通過,2:拒絕 Example:1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *        "id": "系統編號,ex:1",
     *        "type_id": "認證類型系統編號,ex:1",
     *        "image": "圖片位置,ex:http://xxx.xxx.xx/xxxxx.jpg",
     *        "remark": "備註,ex:圖片不清楚",
     *        "status": 2
     *        },
     *        {
     *        "id": 2,
     *        "type_id": 2,
     *        "image": "http://xxx.xxx.xx/xxxxx.jpg",
     *        "remark": "",
     *        "status": 0
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new MemberValidation();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $name = DB::raw("(CASE WHEN n.name is NULL THEN t.name ELSE n.name END) as name");
            $result = DB::table('agent_validation_items as a')->leftJoin('member_validation_types as t', 't.id', '=', 'a.validation_type_id')
                ->leftJoin('member_validations as m', function ($join) use ($request) {
                $join->on('m.type_id', '=', 'a.validation_type_id')
                    ->where('member_id', $request->member_id);
            })
                ->leftJoin('member_validation_type_names as n', function ($join) use ($request) {
                $join->on('n.type_id', '=', 't.id')
                    ->where('n.language_id', '=', $request->LANGUAGE);
            })
                ->where('a.status', 1)
                ->where('a.agent_id', $request->ID)
                ->select('m.id', $name, 't.id as type_id', 'image', 'remark', 'm.status')
                ->orderBy('t.id', 'asc')
                ->get();

            if (count($result) > 0) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result
                ]);
            } else {
                return response()->json([
                    'code' => 1045,
                    'message' => __('error_message.1045')
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
     * memberValidation/view
     * -會員驗證詳情
     *
     * @bodyParam id integer required 會員認證項目編號 Example:1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data":{
     *        "id": 2,
     *        "name": "身份證正面",
     *        "type_id": 2,
     *        "image": "http://xxx.xxx.xx/xxxxx.jpg",
     *        "remark": "",
     *        "status": 0
     *    }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new MemberValidation();
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $name = DB::raw("(CASE WHEN n.name is NULL THEN t.name ELSE n.name END) as name");
            $result = DB::table('member_validations as m')->leftJoin('member_validation_types as t', 't.id', '=', 'm.type_id')
                ->leftJoin('member_validation_type_names as n', function ($join) use ($request) {
                $join->on('n.type_id', '=', 't.id')
                    ->where('n.language_id', '=', $request->LANGUAGE);
            })
                ->select('m.id', $name, 't.id as type_id', 'image', 'remark', 'm.status')
                ->where('m.id', $request->id)
                ->first();

            if (is_object($result)) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result
                ]);
            } else {
                return response()->json([
                    'code' => 1045,
                    'message' => __('error_message.1045')
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
     * memberValidation/update
     * -更新會員驗證狀態
     *
     * @bodyParam id integer required 會員驗證檔案系統編號 Example: 1
     * @bodyParam status integer required 狀態：1:認證成功，2:認證失敗 Example:1
     * @bodyParam reamrk string 備註 Example:照片不清楚
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new MemberValidation();
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 確認是否存在
            $row = MemberValidation::leftJoin('members as m', 'm.id', '=', 'member_validations.member_id')->where('member_validations.id', $request->id)
                ->where('m.parent', $request->ID)
                ->first();
            if (is_object($row)) {
                $member_id = $row['member_id'];
                // 更新記錄
                $data = array();
                $data['status'] = $request->status;
                $data['remark'] = $request->remark;
                $data['admin'] = $request->ID;
                if (MemberValidation::find($request->id)->update($data)) {
                    // 如果全通過了，就更新會員帳號
                    $validatations = DB::table('agent_validation_items as a')->leftJoin('member_validations as m', function ($join) use ($request) {
                        $join->on('m.type_id', '=', 'a.validation_type_id')
                            ->where('member_id', $request->ID);
                    })
                        ->where('a.status', 1)
                        ->where(function ($query) {
                        $query->whereNull('m.status')
                            ->orWhere('m.status', '!=', '1');
                    })
                        ->where('a.agent_id', $request->ID)
                        ->get();
                    $data = array();
                    if (count($validatations) < 1) {
                        $data['is_validate'] = 1;
                    } else {
                        $data['is_validate'] = 0;
                    }
                    $data['admin'] = $request->ID;
                    if (! Member::where('id', $member_id)->update($data)) {
                        return response()->json([
                            'code' => 2001,
                            'message' => __('error_message.2001')
                        ]);
                    }
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
                    'code' => 1045,
                    'message' => __('error_message.1045')
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
     * validation/setting
     * -設定營運商使用何種驗證
     *
     * @bodyParam type_id integer required 模版編號 Example: 1
     * @bodyParam status boolean 狀態0:停用,1:啟用  Example:1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function setting(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new AgentValidationItem();
            // 驗證資料是否正確
            $validator = Validator::make($request->all(), $model->rules, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 寫入新的
            $data = array();
            $data['agent_id'] = $request->ID;
            $data['validation_type_id'] = $request->type_id;
            $data['status'] = $request->status;
            $data['admin'] = $request->ID;
            // 查詢是否存在
            $rows = $model->where('agent_id', $request->ID)
                ->where('validation_type_id', $request->type_id)
                ->get();
            if (count($rows) > 0) {
                // 更新
                $result = $model->where('agent_id', $request->ID)
                    ->where('validation_type_id', $request->type_id)
                    ->update($data);
            } else {
                // 新增
                $result = $model->create($data);
            }
            if ($result) {
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'validation'
                ));
                DB::commit();
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
            'id.required' => __('error_message.1068'),
            'id.digits_between' => __('error_message.1069'),

            'member_id.required' => __('error_message.1066'),
            'member_id.digits_between' => __('error_message.1067'),

            'type_id.required' => __('error_message.1504'),
            'type_id.digits_between' => __('error_message.1505'),

            'status.in' => __('error_message.1049'),
            'status.required' => __('error_message.2009'),
            'status.boolean' => __('error_message.2009'),

            'remark.between' => __('error_message.1044')
        ];
    }
}
