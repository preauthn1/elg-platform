<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use App\REDIS;

class CountryController extends Controller
{

    /**
     * country/list
     * -國家列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "code": "代碼,ex:TW",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:台灣"
     *        },
     *         {
     *            "id": 3,
     *            "code": "HK",
     *            "name": "香港"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $key = array(
                'country',
                'list',
                $request->LANGUAGE
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $name = DB::raw("(CASE WHEN n.name is NULL THEN c.name ELSE n.name END) as name");
                $data = DB::table('countries as c')->leftJoin('country_names as n', function ($join) use ($request) {
                    $join->on('n.country_id', '=', 'c.id')
                        ->where('n.language_id', '=', $request->LANGUAGE);
                })
                    ->select('c.id', 'c.code', 'c.country_code', $name)
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
