<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use DB;

/**
 * @group Dropdown
 */
class MemberValidationTypeController extends Controller
{

    /**
     * validationTypes
     * - 會員驗證類型列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:ID Frontend"
     *        },
     *        {
     *            "id": 2,
     *            "name": "ID Backend"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $key = array(
                'memberValidationType',
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
                    ->select('c.id', $name)
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
}
