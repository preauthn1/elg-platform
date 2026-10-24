<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use App\Models\Analytics;
use Validator;

class AnalyticsController extends Controller
{

    /**
     * analytics
     * -取得營運商的資料分析代碼(Header不需要token)
     *
     * @bodyParam agent_id integer required 代理系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "type": "代碼,ex:1",
     *            "code": "名稱,ex:45121196152222635"
     *        },
     *         {
     *            "type": 2,
     *            "code": "86458966411"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new Analytics();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }
            $key = array(
                'analytics',
                'list',
                $request->agent_id
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = $model->select('type', 'code')
                    ->where('agent_id', $request->agent_id)
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
            'agent_id.required' => __('error_message.2017'),
            'agent_id.digits_between' => __('error_message.2018')
        ];
    }
}
