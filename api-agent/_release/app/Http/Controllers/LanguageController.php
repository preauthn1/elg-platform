<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use App\Language;

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
}
