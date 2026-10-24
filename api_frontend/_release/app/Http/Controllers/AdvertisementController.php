<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Member;
use App\Models\Advertisement;
use App\REDIS;
use Validator;
use DB;

/**
 * @group Advertisements
 */
class AdvertisementController extends Controller
{

    /**
     * advertisements
     * - 廣告列表
     * - Header不需要token
     *
     * @bodyParam path_id integer required 類型編號 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data":  [
     *        {
     *            "image": "圖片位置,ex:http://xxx.xxx.xxx/xxx.jpg",
     *            "image_pc": "電腦版圖片位置,ex:http://xxx.xxx.xxx/xxx.jpg",
     *            "url": "連結網址,ex:http://xxx.xxx.xxx",
     *            "game_id": "遊戲編號,ex:1",
     *            "game_type_id": "遊戲類型編號,ex:0",
     *            "vendor_id": "遊戲商編號,ex:0",
     *            "event_id": "活動系統編號,ex:0"
     *        },
     *        {
     *            "image": "http://xxx.xxx.xxx/xxx.jpg",
     *            "image_pc": "http://xxx.xxx.xxx/xxx.jpg",
     *            "url": "",
     *            "game_id": 0,
     *            "game_type_id": 1,
     *            "vendor_id":0,
     *            "event_id":0
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new Advertisement();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // Redis 相同的條件10分鐘更新一次
            $key = array(
                'advertisement',
                'list',
                $request->path_id,
                $request->ID,
                $request->LANGUAGE
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                // 取圖片
                $model = DB::table('advertisements as a')
                    ->where('a.language_id', $request->LANGUAGE)
                    ->where('a.start_time', '<=', date('Y-m-d H:i:s'))
                    ->where('a.end_time', '>=', date('Y-m-d H:i:s'));

                $member = DB::table('members as m')
                    ->join('agents as a', 'a.id', 'm.parent')
                    ->where('m.id', $request->ID)
                    ->select(
                        'a.id',
                        'a.hierarchy',
                        'm.parent'
                    )
                    ->first();
                // 看這廣告是不是專屬營遲商, 沒登入的就不理他
                if (is_object($member)) {
                    $vendors = $this->vendorList($request->ID);
                    $events = $this->eventList($request->ID);
                    $model = $model->where(function ($query) use ($member) {
                        $query->whereNull('a.agent_id')
                            ->orWhere('a.agent_id', '')
                            ->orWhereRaw("'" . $member->hierarchy . "' LIKE CONCAT('%,', a.agent_id, ',%')");
                    })
                        ->leftJoin('games as g', function ($join) {
                            $join->on('g.id', 'a.game_id')
                                ->on('g.vendor_id', 'a.vendor_id')
                                ->where('g.status', 1);
                        })
                        ->where(function ($query) use ($vendors) {
                            $query->Where('a.vendor_id', 0)
                                ->orWhereNull('a.vendor_id')
                                ->orWhereIn('a.vendor_id', $vendors);
                        })
                        ->where(function ($query) use ($events) {
                            $query->Where('a.event_id', 0)
                                ->orWhereNull('a.event_id')
                                ->orwhereIn('a.event_id', $events);
                        });
                } else {
                    $model = $model->where(function ($query) {
                        $query->whereNull('a.agent_id')
                            ->orWhere('a.agent_id', '');
                    });
                }
                $data = $model->where('a.path_id', $request->path_id)
                    ->orderBy('a.sort', 'asc')
                    ->orderBy('a.id', 'desc')
                    ->select(
                        'a.image',
                        'a.image_pc',
                        'a.url',
                        'a.game_id',
                        'a.game_type_id',
                        'a.vendor_id',
                        'a.event_id'
                    )
                    ->get();
                $redis->set($key, $data, 600);
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
            'path_id.required' => __('error_message.1103'),
            'path_id.digits_between' => __('error_message.1104')
        ];
    }
}