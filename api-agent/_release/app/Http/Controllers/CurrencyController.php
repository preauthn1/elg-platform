<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use DB;

class CurrencyController extends Controller
{

    /**
     * currency/list
     * -匯率列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "code": "代碼,ex:TWD",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:台幣",
     *            "rate": "匯率,ex:1.00000"
     *        },
     *        {
     *            "id": 2,
     *            "code": "THB",
     *            "name": "泰銖",
     *            "rate": "0.90000"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $key = array(
                'currency',
                'list',
                $request->LANGUAGE
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $name = DB::raw("(CASE WHEN n.name is NULL THEN c.name ELSE n.name END) as name");
                $data = DB::table('currencies as c')->leftJoin('currency_names as n', function ($join) use ($request) {
                    $join->on('n.currency_id', '=', 'c.id')
                        ->where('n.language_id', '=', $request->LANGUAGE);
                })
                    ->select('c.id', 'c.code', $name, 'rate')
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
