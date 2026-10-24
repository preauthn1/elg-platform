<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use App\Models\Timezone;

/**
 * @group Dropdown
 */
class TimezoneController extends Controller
{

    /**
     * timezones
     * - 時區列表(Header不需要token)
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "time_offset": "時間偏移,ex:UTC-12:00"
     *        },
     *         {
     *            "id": 2,
     *            "time_offset": "UTC-11:00"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $key = array(
                'timezone',
                'list'
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = Timezone::select('id', 'time_offset')->where('status', 1)->get();
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
