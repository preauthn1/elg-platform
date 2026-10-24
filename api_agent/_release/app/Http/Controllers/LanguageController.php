<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Language;
use App\Models\Agent;
use App\REDIS;

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
     * translate
     * - 語系個性配置
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *            "1000": "value",
     *            "2000": "value",
     *    }
     * }
     */
    public function translate(Request $request)
    {
        try {
            $data = $this->getTranslate($request);
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

    public function getTranslate($request)
    {
        $model = new Agent();

        $translates = $model->from('agents as a')
            ->join('agent_customized_settings as acs', function ($join) use ($request) {
                $join->on('a.customized_setting_group_id', 'acs.agent_group_id')
                    ->whereRaw('3 = floor(acs.setting_key / 1000)')
                    ->where(function ($query) use ($request) {
                        $query->whereRaw("acs.sub_category = ''")
                            ->orWhere('acs.sub_category', $request->LANGUAGE);
                    });
            })
            ->where('a.id', $request->ID)
            ->select(
                'acs.setting_key as key',
                'acs.value',
                'acs.memo'
            )
            ->get();

        $data = array();
        foreach ($translates as $row) {
            $data[$row['key']] = $row['value'];
        }
        return $data;
    }
}
