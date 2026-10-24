<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Member;
use App\Models\MemberValidation;
use App\Models\MemberValidationType;
use Validator;
use REDIS;
use DB;

/**
 * @group Member Validations
 */
class MemberValidationController extends Controller
{

    /**
     * validations
     * - 會員驗證列表
     *
     * @bodyParam status integer 狀態0:未通過,1:通過,2:拒絕 Example:1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "type_id": "認證類型系統編號,ex:1",
     *            "name": "認證類型名稱,ex:身份證",
     *            "image": "圖片位置,ex:http://xxx.xxx.xx/xxxxx.jpg",
     *            "remark": "備註,ex:圖片不清楚",
     *            "status": 2
     *        },
     *        {
     *            "id": 2,
     *            "type_id": 2,
     *            "name": "護照",
     *            "image": "http://xxx.xxx.xx/xxxxx.jpg",
     *            "remark": "",
     *            "status": 0
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
                    'message' => $validator->errors()->all()
                ]);
            }
            
            if ($member = Member::find($request->ID)) {
                $name = DB::raw("(CASE WHEN n.name is NULL THEN t.name ELSE n.name END) as name");
                $data = DB::table('agent_validation_items as a')
                    ->leftJoin('member_validation_types as t', 't.id', 'a.validation_type_id')
                    ->leftJoin('member_validations as m', function ($join) use ($request) {
                        $join->on('m.type_id', 'a.validation_type_id')
                            ->where('member_id', $request->ID);
                    })
                    ->leftJoin('member_validation_type_names as n', function ($join) use ($request) {
                        $join->on('n.type_id', 't.id')
                            ->where('n.language_id', $request->LANGUAGE);
                    })
                    ->select('m.id', 't.id as type_id', $name, 'm.image', 'm.remark', 'm.status')
                    ->where('a.status', 1)
                    ->where('a.agent_id', $member->parent);
                if (isset($request->status) && $request->status != '') {
                    $data = $data->where('m.status', $request->status);
                }
                $result = $data->orderBy('t.id', 'asc')->get();

                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result
                ]);
            } else {
                return response()->json([
                    'code' => 1005,
                    'message' => __('error_message.1005')
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
     * validation
     * - 會員驗證明細
     *
     * @bodyParam id integer required 會員認證系統編號 Example:1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *        "id": "系統編號,ex:1",
     *        "type_id": "認證類型系統編號,ex:1",
     *        "image": "圖片位置,ex:http://xxx.xxx.xx/xxxxx.jpg",
     *        "remark": "備註,ex:圖片不清楚",
     *        "status": 2
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
                    'message' => $validator->errors()->all()
                ]);
            }
            $data = MemberValidation::select('id', 'type_id', 'image', 'remark', 'status')->where('member_id', $request->ID)->find($request->id);
            if ($data) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $data
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
     * validation/create
     * - 新增會員驗證
     *
     * @bodyParam type_id integer required 會員驗證類型系統編號 Example: 1
     * @bodyParam image string base64 file 驗證用圖片
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new MemberValidation();
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }
            $data = array();
            $data['type_id'] = $request->type_id;
            // 移動圖檔至本地及上傳到GCP
            if (isset($request->image)) {
                $path = $this->uploadBase64File($request->image);
                if ($path['code'] != 1000) {
                    return response()->json([
                        'code' => $path['code'],
                        'message' => $path['message']
                    ]);
                }
                $data['image'] = $path['message'];
            }
            $data['member_id'] = $request->ID;
            $data['admin'] = $request->ID;
            if ($model->create($data)) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000')
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
     * validation/update
     * - 修改會員驗證
     *
     * @bodyParam id string required 會員驗證檔案系統編號 Example: 1
     * @bodyParam image string base64 file 驗證用圖片
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
                    'message' => $validator->errors()->all()
                ]);
            }
            $data = array();
            // 移動圖檔至本地及上傳到GCP
            if (isset($request->image)) {
                $path = $this->uploadBase64File($request->image);
                if ($path['code'] != 1000) {
                    return response()->json([
                        'code' => $path['code'],
                        'message' => $path['message']
                    ]);
                }
                $data['image'] = $path['message'];
            }
            $data['status'] = '0';
            $data['admin'] = $request->ID;
            // 確認是否存在,更新記錄
            if (MemberValidation::find($request->id)) {
                if (MemberValidation::find($request->id)->update($data)) {
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

    private function messages()
    {
        return [
            'id.required' => __('error_message.1084'),
            'id.digits_between' => __('error_message.1085'),

            'type_id.required' => __('error_message.1046'),
            'type_id.numeric' => __('error_message.1047'),

            'image.required' => __('error_message.1086'),
            'image.base64image' => __('error_message.2023'),

            'status.in' => __('error_message.1049')
        ];
    }
}
