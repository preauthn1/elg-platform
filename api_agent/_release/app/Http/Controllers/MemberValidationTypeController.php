<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use DB;

class MemberValidationTypeController extends Controller
{

    /**
     * validation/list
     * -驗證類型列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:ID frontend",
     *            "status": "狀態(0:未啟用1:啟用),ex:1"
     *        },
     *        {
     *            "id": 2,
     *            "name": "ID backend",
     *            "status": 0
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $key = array(
                'validation',
                'list',
                $request->LANGUAGE
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $name = DB::raw("(CASE WHEN n.name is NULL THEN c.name ELSE n.name END) as name");
                $data = DB::table('member_validation_types as c')->leftJoin('member_validation_type_names as n', function ($join) use ($request) {
                    $join->on('n.type_id', '=', 'c.id')
                        ->where('n.language_id', '=', $request->LANGUAGE);
                })
                    ->leftJoin('agent_validation_items as a', function ($join) use ($request) {
                    $join->on('a.validation_type_id', '=', 'c.id')
                        ->where('a.agent_id', '=', $request->ID);
                })
                    ->select('c.id', $name, 'a.status')
                    ->where('c.status', 1)
                    ->orderBy('c.id', 'ASC')
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
