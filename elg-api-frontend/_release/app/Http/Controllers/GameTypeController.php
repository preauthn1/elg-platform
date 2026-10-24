<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Game;
use App\Models\GameType;
use App\REDIS;
use Validator;
use DB;

/**
 * @group Dropdown
 */
class GameTypeController extends Controller
{

    /**
     * gameTypes
     * - 遊戲類型列表(Header不需要token)
     *
     * @bodyParam vendor_id integer 遊戲商編號 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:Reality",
     *            "number": "此分類的遊戲數量,ex:64",
     *            "submenu":[
     *               {
     *                 "id": 3,
     *                 "name": "Baccarat"
     *               },
     *               {
     *                 "id": 4,
     *                 "name": "Roulette"
     *               }
     *            ]
     *        },
     *        {
     *            "id": 2,
     *            "name": "Slot",
     *            "number": 30
     *        }
     *    ],
     *  "total": "總遊戲數量,ex:94"
     * }
     */
    public function list(Request $request)
    {
        try {
            $rules = array(
                'vendor_id' => 'numeric'
            );
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }
            $key = array(
                'gameType',
                'list',
                $request->ID,
                $request->vendor_id,
                $request->LANGUAGE
            );
            $key_total = array(
                'gameType',
                'list',
                'total',
                $request->ID,
                $request->vendor_id,
                $request->LANGUAGE
            );
            $redis = new REDIS();
            $total = 0;
            if (!$redis->exists($key)) {
                // 取分類
                $name = DB::raw("(CASE WHEN n.name is NULL THEN c.name ELSE n.name END) as name");
                $types = DB::table('game_types as c')->leftJoin('game_type_names as n', function ($join) use ($request) {
                    $join->on('n.game_type_id', '=', 'c.id')
                        ->where('n.language_id', '=', $request->LANGUAGE);
                })
                    ->where('parent', 0)
                    ->select('c.id', $name)
                    ->where('status', 1)
                    ->get();
                $data = array();
                // 遊戲總數
                foreach ($types as $row) {
                    // 取各分類子項目
                    $sub = GameType::where('parent', $row->id)->select('id')
                        ->get()
                        ->toArray();
                    array_push($sub, $row->id);
                    // 取各分類遊戲數量
                    $game = Game::whereIn('type_id', $sub)->where('status', 1);

                    if ($request->vendor_id) {
                        $game = $game->where('vendor_id', $request->vendor_id);
                    }
                    $count = $game->count();
                    $row->number = $count;
                    $total += $count;
                    $parent = DB::table('game_types as c')->leftJoin('game_type_names as n', function ($join) use ($request) {
                        $join->on('n.game_type_id', '=', 'c.id')
                            ->where('n.language_id', '=', $request->LANGUAGE);
                    })
                        ->where('parent', $row->id)
                        ->select('c.id', $name)
                        ->where('status', 1)
                        ->get();
                    if (count($parent) > 0) {
                        $row->submenu = $parent;
                    }
                    $data[] = $row;
                }
                $redis->set($key, $data);
                $redis->set($key_total, $total);
            } else {
                $data = $redis->get($key);
                $total = $redis->get($key_total);
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'total' => $total
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
            'vendor_id.numeric' => __('error_message.1401')
        ];
    }
}
