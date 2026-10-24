<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use DB;
use Validator;
use App\GameType;
use App\GameTypeName;

class GameTypeController extends Controller
{

    /**
     * gameType/list
     * -遊戲類型列表
     *
     * @bodyParam status boolean required 狀態 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "parent": "上層編號,ex:0",
     *            "name": "預設名稱,ex:Slot",
     *            "status": "狀態0停用1正常,ex:1",
     *            "created_at": "建立時間,ex:2019-03-08 17:55:41",
     *            "updated_at": "最後編輯時間,ex:2019-03-08 17:55:41",
     *            "admin": "最後編輯者,ex:1"
     *        },
     *        {
     *            "id": 2,
     *            "parent": 0,
     *            "name": "Reality",
     *            "status": 1,
     *            "created_at": "2019-03-08 17:55:41",
     *            "updated_at": "2019-03-08 17:55:41",
     *            "admin": 1
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new GameType();
            // 驗證POST資料
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
                'gameType',
                'list',
                $request->status,
                $request->LANGUAGE
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $name = DB::raw("(CASE WHEN n.name is NULL THEN c.name ELSE n.name END) as name");
                $data = DB::table('game_types as c')->leftJoin('game_type_names as n', function ($join) use ($request) {
                    $join->on('n.game_type_id', '=', 'c.id')
                        ->where('n.language_id', '=', $request->LANGUAGE);
                })
                    ->select('c.id', 'c.parent', 'c.name', 'c.status', 'c.created_at', 'c.updated_at', 'c.admin');
                if (isset($request->status)) {
                    $data->where('status', 1);
                }
                $data = $data->get();
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

    /**
     * gameType/view
     * -遊戲類型詳細資料
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "parent": "上層編號,ex:0",
     *            "name": "預設名稱,ex:Slot",
     *            "status": "狀態0停用1正常,ex:1",
     *            "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *            "updated_at": "最後更新時間,ex:2019-03-08 17:43:28",
     *            "admin": "最後更新者,ex:1",
     *            "names":[{"language_id":1,"name":"Slot"},{"language_id":2,"name":"拉霸遊戲"}]
     *        }
     *    ]
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new GameType();
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
                'gameType',
                'show',
                $request->id
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                // 先確認資料是否存在
                if ($data = $model->find($request->id)) {
                    // 取其他語系
                    $names = GameTypeName::select('language_id', 'name')->where('game_type_id', $request->id)->get();
                    $data['names'] = $names;
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $data
                    ]);
                } else {
                    return response()->json([
                        'code' => 1408,
                        'message' => __('error_message.1408')
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
            'id.required' => __('error_message.1409'),
            'id.digits_between' => __('error_message.1410'),

            'parent.required' => __('error_message.1424'),
            'parent.digits_between' => __('error_message.1425'),

            'name.required' => __('error_message.1411'),
            'name.between' => __('error_message.1412'),

            'names.array' => __('error_message.1413'),

            'language_id.required' => __('error_message.2019'),
            'language_id.digits_between' => __('error_message.2020'),

            'status.required' => __('error_message.2009'),
            'status.boolean' => __('error_message.2009')
        ];
    }
}