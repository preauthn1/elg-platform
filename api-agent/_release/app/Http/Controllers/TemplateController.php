<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use App\REDIS;
use Validator;

class TemplateController extends Controller
{

    /**
     * template/list
     * -模版列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "name": "模版名稱,ex:pink",
     *            "thumbnail": "模版縮圖,ex:http://xxx.xxx.xxx/xxx.jpg",
     *            "status": "狀態,ex:1",
     *            "created_at": "建立時間,ex:2019-01-01 00:00:00",
     *            "updated_at": "更新時間,ex:2019-12-31 23:59:59",
     *            "admin": 1
     *          },
     *          {
     *            "id": 2,
     *            "name": "blue",
     *            "thumbnail": "http://xxx.xxx.xxx/xxx.jpg",
     *            "status": 0,
     *            "created_at": "2019-01-01 00:00:00",
     *            "updated_at": "2019-12-31 23:59:59",
     *            "admin": 1
     *          }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            // Redis名稱
            $key = array(
                'template',
                'list',
                $request->ID
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = DB::table('agent_templates as a')->leftJoin('templates as t', 'a.template_id', 't.id')
                    ->select('t.id', 't.name', 't.thumbnail')
                    ->where('a.status', 1)
                    ->where('t.status', 1)
                    ->where('a.agent_id', $request->ID)
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
}
