<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use App\Language;
use DB;
use Validator;

class LanguageController extends Controller
{

    /**
     * languages
     * -語系列表(不需要token)
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "code": "代碼,ex:en",
     *            "name": "名稱,ex:English"
     *        },
     *         {
     *            "id": 2,
     *            "code": "zh-tw",
     *            "name": "繁體中文"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $key = array(
                'language',
                'list'
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = Language::select('id', 'code', 'name')->where('status', 1)->get();
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
     * language/edit
     * -編輯語系狀態
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam status boolean 語系狀態 Example: 0
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new Language();
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
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    $key = array(
                        'language',
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
                    'code' => 1751,
                    'message' => __('error_message.1751')
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
            'id.required' => __('error_message.2019'),
            'id.digits_between' => __('error_message.2020'),

            'status.required|' => __('error_message.2009'),
            'status.boolean' => __('error_message.2009')
        ];
    }
}
