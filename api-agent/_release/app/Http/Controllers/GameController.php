<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Game;
use App\GameName;
use DB;
use App\REDIS;
use Validator;

class GameController extends Controller
{

    /**
     * game/list
     * -遊戲列表
     *
     * @bodyParam vendor_id integer 遊戲商編號 Example: 1
     * @bodyParam type_id integer 類型編號 Example: 1
     * @bodyParam name string 遊戲名稱 Example: diamond
     * @bodyParam is_new boolean 是否為新遊戲(0:否,1:是) Example: 1
     * @bodyParam is_hot boolean 是否為熱門遊戲(0:否,1:是) Example: 0
     * @bodyParam status boolean 狀態 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "vendor_id": "遊戲商編號,ex:1",
     *            "type_id": "遊戲類型系統編號,ex:123456",
     *            "game_type": "遊戲代碼,ex:XX101",
     *            "name": "預設名稱,ex:Diamond Queen",
     *            "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
     *            "image": "遊戲圖片,ex:http://xxx.xxx.xxx/xxx.jpg",
     *            "is_new": "是否為新遊戲(0:否,1:是),ex:1",
     *            "is_hot": "是否為熱門遊戲(0:否,1:是),ex:0",
     *            "status": "狀態0停用1正常,ex:1",
     *            "sort": "排序",
     *            "created_at": "建立時間,ex:2019-03-08 17:55:41",
     *            "updated_at": "最後編輯時間,ex:2019-03-08 17:55:41",
     *            "admin": "最後編輯者,ex:1"
     *          },
     *          {
     *            "id": 2,
     *            "vendor_id": 1,
     *            "type_id": 2,
     *            "game_type": "XX102",
     *            "name": "Alibaba",
     *            "lottery_pool": "0",
     *            "image": "http://xxx.xxx.xxx/xxx.jpg",
     *            "is_new": 1,
     *            "is_hot": 0,
     *            "status": 0,
     *            "sort": 123,
     *            "created_at": "2019-03-08 17:55:41",
     *            "updated_at": "2019-03-08 17:55:41",
     *            "admin": 1
     *          }
     *    ],
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     */
    function list(Request $request)
    {
        try {
            $model = new Game();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'game',
                'list',
                $request->LANGUAGE,
                $request->vendor_id,
                $request->type_id,
                $request->name,
                $request->is_new,
                $request->is_hot,
                $request->status,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'game',
                'list',
                $request->vendor_id,
                $request->type_id,
                $request->name,
                $request->is_new,
                $request->is_hot,
                $request->status,
                'total'
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $data = DB::table('games as a')->leftJoin('game_names as n', function ($join) use ($request) {
                    $join->on('n.game_id', '=', 'a.id')
                        ->where('n.language_id', '=', $request->LANGUAGE);
                })
                    ->select('a.id', 'a.vendor_id', 'a.type_id', 'a.game_type', 'a.name', 'a.image', 'a.is_new', 'a.is_hot', 'a.status', 'a.sort', 'a.created_at', 'a.updated_at', 'a.admin');
                if (isset($request->name)) {
                    $data->where(function ($query) use ($request) {
                        $query->orwhere('n.name', 'like', '%' . $request->name . '%')
                            ->orwhere('a.name', 'like', '%' . $request->name . '%');
                    });
                }
                if (isset($request->vendor_id)) {
                    $data->where('a.vendor_id', $request->vendor_id);
                }
                if (isset($request->type_id)) {
                    $data->where('a.type_id', $request->type_id);
                }
                if (isset($request->is_new)) {
                    $data->where('a.is_new', $request->is_new);
                }
                if (isset($request->is_hot)) {
                    $data->where('a.is_hot', $request->is_hot);
                }
                if (isset($request->status)) {
                    $data->where('a.status', $request->status);
                }
                $total = $data->count();
                $data = $data->orderBy('a.created_at', 'desc')
                    ->orderBy('a.id', 'desc')
                    ->skip(($request->page - 1) * $request->per_page)
                    ->take($request->per_page)
                    ->get();
                $redis->set($key, $data);
                $redis->set($total_key, $total);
            } else {
                $data = $redis->get($key);
                $total = $redis->get($total_key);
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
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
     * game/view
     * -遊戲詳細資料
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "vendor_id": "遊戲商編號,ex:1",
     *            "type_id": "遊戲類型系統編號,ex:123456",
     *            "game_type": "遊戲代碼,ex:XX101",
     *            "name": "預設名稱,ex:Diamond Queen",
     *            "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
     *            "image": "遊戲圖片,ex:http://xxx.xxx.xxx/xxx.jpg",
     *            "is_new": "是否為新遊戲(0:否,1:是),ex:1",
     *            "is_hot": "是否為熱門遊戲(0:否,1:是),ex:0",
     *            "status": "狀態0停用1正常,ex:1",
     *            "sort": 1,
     *            "created_at": "建立時間,ex:2019-03-08 17:55:41",
     *            "updated_at": "最後編輯時間,ex:2019-03-08 17:55:41",
     *            "admin": "最後編輯者,ex:1",
     *            "names":[{"language_id":1,"name":"Diamond Queen"},{"language_id":2,"name":"鑽石女王"}]
     *        }
     *    ]
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Game();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'game',
                'show',
                $request->id
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                // 先確認資料是否存在
                if ($data = $model->find($request->id)) {
                    // 取其他語系
                    $names = GameName::select('language_id', 'name')->where('game_id', $request->id)->get();
                    $data['names'] = $names;
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $data
                    ]);
                } else {
                    return response()->json([
                        'code' => 1406,
                        'message' => __('error_message.1406')
                    ]);
                }
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


    private function messages()
    {
        return [
            'id.required' => __('error_message.1407'),
            'id.digits_between' => __('error_message.1414'),

            'vendor_id.required' => __('error_message.1415'),
            'vendor_id.digits_between' => __('error_message.1416'),

            'type_id.required' => __('error_message.1409'),
            'type_id.digits_between' => __('error_message.1410'),

            'game_type.required' => __('error_message.1417'),
            'game_type.between' => __('error_message.1418'),

            'name.required' => __('error_message.1419'),
            'name.between' => __('error_message.1420'),

            'names.array' => __('error_message.1421'),

            'image.base64image' => __('error_message.2023'),

            'language_id.required' => __('error_message.2019'),
            'language_id.digits_between' => __('error_message.2020'),

            'is_new.boolean' => __('error_message.1422'),

            'is_hot.boolean' => __('error_message.1423'),

            'status.required' => __('error_message.1403'),
            'status.boolean' => __('error_message.1403'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}