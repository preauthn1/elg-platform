<?php

namespace App\Http\Controllers;

use App\Models\VendorInfoGame;
use Illuminate\Http\Request;
use App\Models\Game;
use App\Models\GameName;
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
            $vendor_list = $this->vendorList($request->HOST);

            $data = DB::table('games as a')->leftJoin('game_names as n', function ($join) use ($request) {
                $join->on('n.game_id', 'a.id')
                    ->where('n.language_id', $request->LANGUAGE);
            })
                ->whereIn('a.vendor_id', $vendor_list);
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
            if (isset($request->status)) {
                $data->where('a.status', $request->status);
            }
            $total = $data->count();
            $data = $data->orderBy('a.vendor_id', 'asc')
                ->orderBy('a.id', 'asc')
                ->select(
                    'a.id',
                    'a.vendor_id',
                    'a.type_id',
                    'a.game_type',
                    'a.name',
                    'a.image',
                    'a.status',
                    'a.sort',
                    'a.created_at',
                    'a.updated_at',
                    'a.admin',
                    'a.memo'
                )
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();
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

    /**
     * game/add
     * -新增遊戲
     *
     * @bodyParam vendor_id integer required 遊戲商系統編號 Example: 1
     * @bodyParam type_id integer required 遊戲類型系統編號 Example: 1
     * @bodyParam join_bet_event integer 是否可參加投注活動(0:否,1:是) Example: 1
     * @bodyParam join_win_event integer 是否可參加贏分活動(0:否,1:是) Example: 1
     * @bodyParam game_type string required 遊戲代碼 Example: 1
     * @bodyParam name string required 預設遊戲類型名稱 Example: Slot
     * @bodyParam names array 多語遊戲類型名稱 Example: [{"language_id":1,"name":"Solt"},{"language_id":2,"name":"拉霸遊戲"}]
     * @bodyParam image string base64 file 遊戲圖片
     * @bodyParam is_new boolean 是否為新遊戲(0:否,1:是) Example: 1
     * @bodyParam is_hot boolean 是否為熱門遊戲(0:否,1:是) Example: 0
     * @bodyParam status boolean required 狀態(0:停用,1:正常) Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "系統編號"
     * }
     */
    public function create(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new Game();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $data = array();
            $data['vendor_id'] = $request->vendor_id;
            $data['type_id'] = $request->type_id;
            $data['game_type'] = $request->game_type;
            $data['name'] = $request->name;

            // 移動圖檔至本地及上傳到GCP
            if (isset($request->image) && $request->image != '') {
                $path = $this->uploadBase64File($request->image);
                if ($path['code'] != 1000) {
                    return response()->json([
                        'code' => $path['code'],
                        'message' => $path['message']
                    ]);
                }
                $data['image'] = $path['message'];
            }

            $data['sort'] = $request->sort;
            $data['memo'] = $request->memo;
            $data['status'] = $request->status;
            $data['admin'] = $request->ID;
            
            if ($result = $model->create($data)) {

                $names = array();
                $names['game_id'] = $result->id;

                if (is_array($request->names)) {
                    foreach ($request->names as $row) {
                        // 驗證資料
                        $validator = Validator::make($row, $model->rules_name, $this->messages());
                        if (!$validator->passes()) {
                            return response()->json([
                                'code' => 2002,
                                'message' => __('error_message.2002'),
                                'data' => $validator->errors()
                                    ->all()
                            ]);
                        }
                        $names['language_id'] = $row['language_id'];
                        $names['name'] = $row['name'];
                        GameName::create($names);
                    }
                }


                //預設產生好 [關閉狀態] 的vendor_info_games
                $this->insertDefaultVendorInfoGames($request->vendor_id , $result->id ,$request->sort);


                DB::commit();
                // 刪除遊戲列表
                $redis = new REDIS();
                $redis->delete([
                    'game'
                ]);
                // 因為類型有遊戲數量，所以需刪除重取
                $redis->delete([
                    'gameType'
                ]);
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result->id
                ]);
            } else {
                return response()->json([
                    'code' => 2001,
                    'message' => __('error_message.2001')
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollback();
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
     * game/edit
     * -編輯遊戲
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam vendor_id integer option 遊戲商系統編號 Example: 1
     * @bodyParam type_id integer option 遊戲類型系統編號 Example: 1
     * @bodyParam join_bet_event integer 是否可參加投注活動(0:否,1:是) Example: 1
     * @bodyParam join_win_event integer 是否可參加贏分活動(0:否,1:是) Example: 1
     * @bodyParam game_type string option 遊戲代碼 Example: 1
     * @bodyParam name string option 預設遊戲類型名稱 Example: Slot
     * @bodyParam names array option 多語遊戲類型名稱 Example: [{"language_id":1,"name":"Solt"},{"language_id":2,"name":"拉霸遊戲"}]
     * @bodyParam image string base64 file 遊戲圖片
     * @bodyParam is_new boolean 是否為新遊戲(0:否,1:是) Example: 1
     * @bodyParam is_hot boolean 是否為熱門遊戲(0:否,1:是) Example: 0
     * @bodyParam status boolean option 狀態(0:停用,1:正常) Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new Game();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            if ($row = $model->find($request->id)) {
                $data = array();
                $data['vendor_id'] = isset($request->vendor_id) ? $request->vendor_id : $row->vendor_id;
                $data['type_id'] = isset($request->type_id) ? $request->type_id : $row->type_id;
                $data['game_type'] = isset($request->game_type) ? $request->game_type : $row->game_type;
                $data['name'] = isset($request->name) ? $request->name : $row->name;
                $data['sort'] = isset($request->sort) ? $request->sort : $row->sort;
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['memo'] = isset($request->memo) ? $request->memo : $row->memo;
                // 移動圖檔至本地及上傳到GCP
                if (isset($request->image) && $request->image != '') {
                    $path = $this->uploadBase64File($request->image);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $data['image'] = $path['message'];
                }
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    // 寫入不同語系名稱
                    $names = array();
                    $names['game_id'] = $request->id;
                    if (is_array($request->names)) {
                        foreach ($request->names as $row) {
                            // 驗證資料
                            $validator = Validator::make($row, $model->rules_name, $this->messages());
                            if (!$validator->passes()) {
                                return response()->json([
                                    'code' => 2002,
                                    'message' => __('error_message.2002'),
                                    'data' => $validator->errors()
                                        ->all()
                                ]);
                            }
                            $names['name'] = $row['name'];
                            $names['language_id'] = $row['language_id'];
                            $name = GameName::where('game_id', $request->id)->where('language_id', $row['language_id'])->get();
                            if (count($name) > 0) {
                                GameName::where('game_id', $request->id)->where('language_id', $row['language_id'])->update($names);
                            } else {
                                GameName::create($names);
                            }
                        }
                    }
                    DB::commit();
                    // 刪除Redis中相關的資料
                    // 刪除遊戲列表
                    $redis = new REDIS();
                    $redis->delete([
                        'game'
                    ]);
                    // 因為類型有遊戲數量，所以需刪除重取
                    $redis->delete([
                        'gameType'
                    ]);
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                } else {
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001')
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 1406,
                    'message' => __('error_message.1406')
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollback();
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


    /**     *  
     *  game/editVendorInfoGame
     * 
     * 
     */
    public function editVendorInfoGame(Request $request)
    {
        try {
            foreach ($request->data as $vendor_info_id => $params) {
                $data = array();
                $rest = VendorInfoGame::where('id', $vendor_info_id)
                    ->update([
                        'join_bet_event' => 0,
                        'join_win_event' => 0,
                        'is_new' => 0,
                        'is_hot' => 0
                    ]);
                foreach ($params as $column => $val) {
                    $data[$column] = $val;
                }
                if ($rest) {
                    VendorInfoGame::where('id', $vendor_info_id)
                        ->update($data);
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
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


    /**     *  
     *  game/add/unassign-vendorInfo
     * 
     */
    public function insertUnassignVendorInfo(Request $request)
    {
        try {
           
            $game_id = $request->game_id;

            $queryGame = DB::table("games")->where("id" , $game_id)->first();
            
//            $queryVendor = DB::table("vendors")->where("id" , $queryGame->vendor_id)->first();

            $queryExistVendorInfoGames = DB::table("vendor_info_games")->where("game_id" , $game_id)->pluck("vendor_info_id")->toArray();

            $queryUnassignVendorInfos = DB::table("vendor_infos")
                ->where("vendor_id" , $queryGame->vendor_id)
                ->whereNotIn("id" ,  $queryExistVendorInfoGames)
                ->get();

            if(count($queryUnassignVendorInfos) <= 0)
            {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                ]);
            }

            foreach($queryUnassignVendorInfos as $item)
            {
                $insertItem = array();
                $insertItem["vendor_info_id"] = $item->id;
                $insertItem["game_id"] = $game_id;
                $insertItem["join_bet_event"] = $queryGame->join_bet_event;
                $insertItem["join_win_event"] = $queryGame->join_win_event;
                $insertItem["is_new"] = $queryGame->is_new;
                $insertItem["is_hot"] = $queryGame->is_hot;
                $insertItem["sort"] = is_null($queryGame->sort) ? 0 : $queryGame->sort;
                $insertItem["valid_bet_rate"] = $queryGame->valid_bet_rate;
                $insertItem["status"] = 0;
                $insertItem["admin"] = 0;
    
                DB::table("vendor_info_games")->insert($insertItem);
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
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

    
    private function insertDefaultVendorInfoGames($vendor_id , $game_id , $sort)
    {
        $queryVendor = DB::table("vendors")->where("id" , $vendor_id)->first();

        $queryExistVendorInfos = DB::table("vendor_infos")->where("vendor_id" , $vendor_id)->get();

        if(count($queryExistVendorInfos) <= 0)
        {
            return;
        }

        foreach($queryExistVendorInfos as $item)
        {
            $insertItem = array();
            $insertItem["vendor_info_id"] = $item->id;
            $insertItem["game_id"] = $game_id;
            $insertItem["join_bet_event"] = 0;
            $insertItem["join_win_event"] = 0;
            $insertItem["is_new"] = 0;
            $insertItem["is_hot"] = 0;
            $insertItem["sort"] = $sort;
            $insertItem["valid_bet_rate"] = $queryVendor->valid_bet_rate;
            $insertItem["status"] = 0;
            $insertItem["admin"] = 0;

            DB::table("vendor_info_games")->insert($insertItem);
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