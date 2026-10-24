<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use App\Param;
use Validator;

class ParamController extends Controller
{

    /**
     * param/list
     * -參數列表
     *
     * @bodyParam key string required 鍵值 Example: event_type
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "parameter": "代碼,ex:mission",
     *            "value": "值,ex:1"
     *        },
     *         {
     *            "parameter": "activity",
     *            "value": "2"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new Param();
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
                'param',
                'list',
                $request->key
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = $model->select('parameter', 'value')
                    ->where('type', $request->key)
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

    private function messages()
    {
        return [
            'key.required' => __('error_message.9997')
        ];
    }
}
