<?php

namespace App\Http\Controllers;

use App\Helpers\SQLHelper;
use Illuminate\Http\Request;
use App\Models\Advertise;
use App\REDIS;
use Validator;
use DB;

/**
 * @group Advertises
 */
class AdvertiseController extends Controller
{

    /**
     * advertises
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
            $model = new Advertise();
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
                $ad_model = DB::table('advertise_languages')
                    ->whereIn('language_id', [1, $request->LANGUAGE])
                    ->select(
                        'advertise_id',
                        DB::raw('case when language_id = 1 then advertise_image_id else 0 end default_advertise_image_id'),
                        DB::raw('case when language_id = 1 then advertise_image_pc_id else 0 end default_advertise_image_pc_id'),
                        DB::raw('case when language_id != 1 then advertise_image_id else 0 end advertise_image_id'),
                        DB::raw('case when language_id != 1 then advertise_image_pc_id else 0 end advertise_image_pc_id')
                    );

            $sub = DB::table(DB::raw('(' . $ad_model->toSql() .  ') as s'))
                ->mergeBindings($ad_model)
                ->groupBy('s.advertise_id')
                ->select(
                    's.advertise_id',
                    DB::raw('max(s.default_advertise_image_id) as default_advertise_image_id'),
                    DB::raw('max(s.advertise_image_id) as advertise_image_id'),
                    DB::raw('max(s.default_advertise_image_pc_id) as default_advertise_image_pc_id'),
                    DB::raw('max(s.advertise_image_pc_id) as advertise_image_pc_id')
                );

                $ad = DB::table(DB::raw('(' . $sub->toSql(). ') as s'))
                    ->mergeBindings($sub)
                    ->select(
                        's.advertise_id',
                        DB::raw('case when s.advertise_image_id = 0 THEN s.default_advertise_image_id ELSE s.advertise_image_id END advertise_image_id'),
                        DB::raw('case when s.advertise_image_pc_id = 0 THEN s.default_advertise_image_pc_id ELSE s.advertise_image_pc_id END advertise_image_pc_id')
                    );

                $data = DB::table('members as m')
                    ->join('agents as a', 'a.id', 'm.parent')
                    ->join('advertises as ad', function ($join) use ($request) {
                        $join->whereRaw("a.hierarchy LIKE CONCAT('%,', ad.agent_id, ',%')")
                            ->where('ad.start_time', '<=', date('Y-m-d H:i:s'))
                            ->where('ad.end_time', '>=', date('Y-m-d H:i:s'))
                            ->where('ad.status', 1)
                            ->where('ad.advertisement_path_id', $request->path_id);
                    })
                    ->leftJoin('games as g', function ($join) {
                        $join->where('ad.type', 2)
                            ->whereRaw('g.id = (ad.content * 1)');
                    })
                    ->join(DB::raw('(' . $ad->toSql(). ') as al'), 'al.advertise_id', 'ad.id')
                    ->mergeBindings($ad)
                    ->leftJoin('advertise_images as ai', 'ai.id', 'al.advertise_image_id')
                    ->leftJoin('advertise_images as aic', 'aic.id', 'al.advertise_image_pc_id')
                    ->where('m.id', $request->ID)
                    ->orderBy('ad.sort', 'DESC')
                    ->orderBy('ad.id', 'DESC')
                    ->select(
                        'ad.advertisement_path_id as path_id',
                        'ad.type',
                        DB::raw('CASE WHEN ad.type = 1 THEN ad.content END AS game_type_id'),
                        DB::raw('CASE WHEN ad.type = 2 THEN ad.content END AS game_id'),
                        DB::raw('CASE ad.type WHEN 3 THEN ad.content WHEN 2 THEN g.vendor_id END AS vendor_id'),
                        DB::raw('CASE ad.type WHEN 4 THEN ad.content WHEN 5 THEN ad.content END AS url'),
                        'ai.image_url as image',
                        'aic.image_url as image_pc',
                        'ad.open_window'
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