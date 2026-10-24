<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Game;
use App\Models\GameType;
use App\Models\FavouriteGame;
use App\Models\Member;
use App\Models\VendorRequestFail;
use DB;
use App\REDIS;
use Event;
use Validator;

class GameController extends Controller
{

    /**
     * lottery
     * - 遊戲彩金列表
     *
     * @bodyParam number integer 筆數(預設為15筆) Example: 10
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "遊戲系統編號,ex:1",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:Diamond Queen",
     *            "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
     *            "image": "遊戲圖片,ex:http://xxx.xxx.xxx/xxx.jpg"
     *          },
     *          {
     *            "id": 2,
     *            "name": "Alibaba",
     *            "lottery_pool": "123456",
     *            "image": "http://xxx.xxx.xxx/xxx.jpg"
     *          }
     *    ]
     * }
     */
    function lottery(Request $request)
    {
        try {
            $model = new Game();
            $validator = Validator::make($request->all(), $model->rules_lottery, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $data = DB::table('games as a')
                ->leftJoin('game_names as n', function ($join) use ($request) {
                    $join->on('n.game_id', 'a.id')
                        ->where('n.language_id', $request->LANGUAGE);
                })
                ->where('a.status', 1)
                ->where('a.lottery_pool', '>', 0)
                ->groupBy('a.id')
                ->orderBy('a.lottery_pool', 'desc')
                ->orderBy('a.id', 'desc')
                ->select(
                    'a.id',
                    'a.vendor_id',
                    'a.type_id',
                    DB::raw("(CASE WHEN n.name is NULL THEN a.name ELSE n.name END) as name"),
                    'a.lottery_pool',
                    'a.image'
                )
                ->skip(0)
                ->take($request->number ? $request->number : 15)
                ->get();
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
     * game
     * -遊戲資訊
     *
     * @bodyParam game_id interger 遊戲商系統編號
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *            "id": "系統編號,ex:1",
     *            "vendor_id": "遊戲商編號,ex:1",
     *            "vendor_name": "遊戲商名稱,ex:AG",
     *            "vendor_status": "遊戲商狀態1:開啟，2:coming soon，3:維護中，4:禁止遊玩,ex:1",
     *            "type_id": "遊戲類型系統編號,ex:123456",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:Diamond Queen",
     *            "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
     *            "image": "遊戲圖片,ex:http://xxx.xxx.xxx/xxx.jpg",
     *            "is_new": "是否為新遊戲(0:否,1:是),1",
     *            "is_hot": "是否為熱門遊戲(0:否,1:是),0",
     *            "join_bet_event": "是否可以參加投注活動,0:不可以1:可以",
     *            "join_win_event": "是否可以參加贏分活動,0:不可以1:可以",
     *            "disable": "是否可進入遊戲0:可，1不可"
     *        }
     * }
     */
    public function show(Request $request)
    {
        try {
            $rule = [
                'game_id' => 'required|digits_between:0,10'
            ];
            $validator = Validator::make($request->all(), $rule, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            //取得vendor_info_id
            $vendor_info_ids = $this->vendorList($request->ID, 'a.vendor_info_id');

            //查詢玩家目前狀態是否在鎖分中
            $event_mode = Member::where('id', $request->ID)->pluck('event_mode')->first();
            if ($event_mode == 1) {
                //如果玩家進入平台鎖分模式，APP相關及不可參加鎖分活動的遊戲商狀態為4
                $vendor_status = ($request->type == 2)
                    //轉點頁面用(如果轉點有錯誤就進入維護，等排程確定沒問題後開啟)
                    ? DB::raw("(CASE WHEN ((m.id and (m.type=1 or m.type=3)) || f.id is not null) THEN 3 WHEN v.event_lock=0 THEN 4 ELSE v.status END) as status")
                    //遊戲頁面用
                    : DB::raw("(CASE WHEN ((m.id and (m.type=1 or m.type=2)) || f.id is not null) THEN 3 WHEN v.event_lock=0 THEN 4 ELSE v.status END) as status");
            } else {
                $vendor_status = ($request->type == 2)
                    //轉點頁面用(如果轉點有錯誤就進入維護，等排程確定沒問題後開啟)
                    ? DB::raw('IF(((m.id and (m.type=1 or m.type=3)) || f.id is not null), 3, v.status) as status')
                    //遊戲頁面用
                    : DB::raw('IF((m.id and (m.type=1 or m.type=2)), 3, v.status) as status');
            }

            $sub = VendorRequestFail::whereRaw('status = 2')->groupby('vendor_id')->select('id', 'vendor_id');

            $data = DB::table('games as g')
                ->leftJoin('game_names as n', function ($join) use ($request) {
                    $join->on('n.game_id', 'g.id')
                        ->where('n.language_id', $request->LANGUAGE);
                })
                ->join('vendors as v', function ($join) {
                    $join->on('v.id', 'g.vendor_id')
                        ->where('v.status', '>', 0);
                })
                ->join('vendor_info_games as vig', function ($join) use ($vendor_info_ids) {
                    $join->on('vig.game_id', 'g.id')
                        ->where('vig.status', 1)
                        ->whereIn('vig.vendor_info_id', $vendor_info_ids);
                })
                ->leftJoin('vendor_maintenances as m', function ($join) {
                    $join->on('m.vendor_id', 'v.id')
                        ->where('m.status', 1)
                        ->where('m.start_time', '<=', date('Y-m-d H:i:s'))
                        ->where('m.end_time', '>=', date('Y-m-d H:i:s'));
                })
                ->leftJoin(DB::raw('(' . $sub->toSql() . ') as f'), 'f.vendor_id', 'v.id')
                ->where('g.id', $request->game_id)
                ->where('g.status', 1)
                ->select(
                    'g.id',
                    'v.id as vendor_id',
                    'v.name as vendor_name',
                    $vendor_status,
                    'g.type_id',
                    DB::raw("(CASE WHEN n.name is NULL THEN g.name ELSE n.name END) as name"),
                    'g.lottery_pool',
                    'g.image',
                    'vig.is_new',
                    'vig.is_hot',
                    'vig.join_bet_event',
                    'vig.join_win_event'
                )
                ->first();
            //查到有設定的帳號為止
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
     * myGames
     * - 遊戲列表
     *
     * @bodyParam vendor_id integer 遊戲商編號 Example: 1
     * @bodyParam vendor_ids arrary 遊戲商編號 Example: [1,2,3]
     * @bodyParam type_id integer 類型編號 Example: 1
     * @bodyParam key string 遊戲名稱或類型 Example: diamond
     * @bodyParam personal integer 個人化(1:我的最愛2:最近遊玩3:贏最多) Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "vendor_id": "遊戲商編號,ex:1",
     *            "vendor_name": "遊戲商名稱,ex:AG",
     *            "type_id": "遊戲類型系統編號,ex:123456",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:Diamond Queen",
     *            "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
     *            "image": "遊戲圖片,ex:http://xxx.xxx.xxx/xxx.jpg",
     *            "is_new": "是否為新遊戲(0:否,1:是),1",
     *            "is_hot": "是否為熱門遊戲(0:否,1:是),0",
     *            "is_favourite": "是否為我的最愛(0:否,1:是),0",
     *            "event_bet": "參加了哪些關於投注的活動,編號1:標題1,編號2:標題2",
     *            "event_win": "參加了哪些關於贏分的活動,編號1:標題1,編號2:標題2",
     *            "disable": "是否可進入遊戲0:可，1不可"
     *          }
     *    ],
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    },
     *  "new_data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "vendor_id": "遊戲商編號,ex:1",
     *            "vendor_name": "遊戲商名稱,ex:AG",
     *            "type_id": "遊戲類型系統編號,ex:123456",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:Diamond Queen",
     *            "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
     *            "image": "遊戲圖片,ex:http://xxx.xxx.xxx/xxx.jpg",
     *            "is_new": "是否為新遊戲(0:否,1:是),1",
     *            "is_hot": "是否為熱門遊戲(0:否,1:是),0",
     *            "is_favourite": "是否為我的最愛(0:否,1:是),0",
     *            "event_bet": "參加了哪些關於投注的活動,編號1:標題1,編號2:標題2",
     *            "event_win": "參加了哪些關於贏分的活動,編號1:標題1,編號2:標題2",
     *            "disable": "是否可進入遊戲0:可，1不可"
     *          }
     *    ],
     *  "hot_data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "vendor_id": "遊戲商編號,ex:1",
     *            "vendor_name": "遊戲商名稱,ex:AG",
     *            "type_id": "遊戲類型系統編號,ex:123456",
     *            "name": "名稱(依Header傳送的語系顯示,沒資料為預設值),ex:Diamond Queen",
     *            "lottery_pool": "彩池金額(無彩池的遊戲為0),ex:0",
     *            "image": "遊戲圖片,ex:http://xxx.xxx.xxx/xxx.jpg",
     *            "is_new": "是否為新遊戲(0:否,1:是),1",
     *            "is_hot": "是否為熱門遊戲(0:否,1:是),0",
     *            "is_favourite": "是否為我的最愛(0:否,1:是),0",
     *            "event_bet": "參加了哪些關於投注的活動,編號1:標題1,編號2:標題2",
     *            "event_win": "參加了哪些關於贏分的活動,編號1:標題1,編號2:標題2",
     *            "disable": "是否可進入遊戲0:可，1不可"
     *          }
     *    ]
     * }
     */
    function myList(Request $request)
    {
        try {
            $model = new Game();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            $agent = DB::table('members as m')
                ->join('agents as a', 'a.id', 'm.parent')
                ->where('m.id', $request->ID)
                ->select('a.id', 'a.hierarchy', 'm.event_mode')
                ->first();

            $now = gmdate("Y-m-d H:i:s");
            $maintainVendors = DB::table('vendor_maintenances')
                ->where('status', 1)
                ->where('start_time', "<=" , $now)
                ->where('end_time', ">" , $now)
                ->pluck('vendor_id')
                ->toArray();

            $model = $this->myGamesModel($request , $agent , $maintainVendors);
            $total = $model->count();
            $data = $model->orderBy('f.status', 'desc')
                ->orderBy('vig.sort', 'desc')
                ->orderBy('a.id', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            $new = $this->myGamesModel($request , $agent, $maintainVendors);
            $new = $new->where('vig.is_new', 1)->get();

            $hot = $this->myGamesModel($request , $agent, $maintainVendors);
            $hot = $hot->where('vig.is_hot', 1)->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page),
                'new_data' => $new,
                'hot_data' => $hot,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function myGamesModel($request , $agent , $maintainVendors)
    {
     
        //查vendor_info_ids
        $vendor_infos = $this->vendorList($request->ID, 'a.vendor_info_id');
        $hierarchy = explode(',', $agent->hierarchy);
        array_shift($hierarchy); //刪除第一個元素
        array_pop($hierarchy); //刪除最後一個元素
        array_push($hierarchy, 0); //加上0為平台的編號
        $title = DB::raw("(CASE WHEN n.title is NULL THEN e.title ELSE n.title END)");
        // 取活動的Detail,每個遊戲商參加了什麼活動
        $events = DB::table('events as e')
            ->join('event_details as d', function ($join) {
                $join->on('d.event_id', 'e.id')
                    ->where('d.status', 1)
                    ->where('d.vendor_id', '>', 0)
                    ->where('d.start_time', '<', date('Y-m-d H:i:s'))
                    ->where('d.end_time', '>', date('Y-m-d H:i:s'));
            })
            ->leftJoin('event_names as n', function ($join) use ($request) {
                $join->on('n.event_id', 'e.id')
                    ->where('n.language_id', $request->LANGUAGE);
            })
            ->where('e.status', 1)
            ->whereIn('e.agent_id', $hierarchy) //可參加自己上線及平台辦的活動
            ->select(
                'd.vendor_id',
                DB::raw("GROUP_CONCAT(distinct (CASE d.action_id WHEN 2 THEN d.event_id END),':',(CASE d.action_id WHEN 2 THEN $title END)) as bet"),
                DB::raw("GROUP_CONCAT(distinct (CASE d.action_id WHEN 3 THEN d.event_id END),':',(CASE d.action_id WHEN 3 THEN $title END)) as win")
            )
            ->groupBy('vendor_id');

        $model = DB::table('games as a')
            ->join('vendor_info_games as vig', function ($join) use ($vendor_infos) {
                $join->on('vig.game_id', 'a.id')
                    ->where('vig.status', 1)
                    ->whereIn('vig.vendor_info_id', $vendor_infos); //篩選這個會員可以玩的遊戲商
            })
            ->join('vendors as v', function ($join) {
                $join->on('v.id', 'a.vendor_id')
                    ->where('v.status', '>', 0);
            })
            ->leftJoin('game_names as n', function ($join) use ($request) {
                $join->on('n.game_id', 'a.id')
                    ->where('n.language_id', $request->LANGUAGE);
            })
            ->leftJoin('favourite_games as f', function ($join) use ($request) {
                $join->on('f.game_id', 'a.id')
                    ->where('f.member_id', $request->ID)
                    ->where('f.status', 1);
            })
            ->leftJoin('game_types as t', 't.id', 'a.type_id')
            ->leftJoin('game_type_names as tn', function ($join) use ($request) {
                $join->on('t.id', 'tn.game_type_id')
                    ->where('tn.language_id', $request->LANGUAGE);
            })
            ->leftJoin(DB::raw("({$events->toSql()}) e"), 'e.vendor_id', 'a.vendor_id')
            ->mergeBindings($events)
            ->select(
                'a.id',
                'a.vendor_id',
                'v.name as vendor_name',
                'a.type_id',
                DB::raw("(CASE WHEN n.name is NULL THEN a.name ELSE n.name END) as name"),
                'a.lottery_pool',
                DB::raw("(CASE WHEN (a.image is NULL OR a.image = '')THEN v.image ELSE a.image END) as image"),
                'vig.is_new',
                'vig.is_hot',
                DB::raw("(CASE WHEN f.status is NULL THEN 0 ELSE 1 END) as is_favourite"),
                DB::raw("(CASE WHEN (e.bet is NULL OR vig.join_bet_event = 0) THEN '' ELSE e.bet END) as event_bet"),
                DB::raw("(CASE WHEN (e.win is NULL OR vig.join_win_event = 0) THEN '' ELSE e.win END) as event_win"),
                // 判斷玩家是否在鎖分模式中(鎖分模式只可玩slot)
                ($agent->event_mode == 1)
                    ? DB::raw("(CASE WHEN (a.join_bet_event = 1 and a.join_win_event = 1) THEN 0 ELSE 1 END) as disable")
                    : DB::raw("0 as disable")
            );


        // 模糊查詢遊戲名稱及類別
        if (isset($request->key) && $request->key != '') {
            $model->where(function ($query) use ($request) {
                $query->orwhere('n.name', 'like', '%' . $request->key . '%')
                    ->orwhere('a.name', 'like', '%' . $request->key . '%')
                    ->orwhere('t.name', 'like', '%' . $request->key . '%')
                    ->orwhere('tn.name', 'like', '%' . $request->key . '%');
            });
        }
        
        if (isset($request->vendor_id) && $request->vendor_id != '') {
            $model->where('a.vendor_id', $request->vendor_id);
        }
        else
        {
            //如果列出所有遊戲商遊戲，要排除維護的遊戲
            if(count($maintainVendors) > 0)
            {
                $model->whereNotIn('a.vendor_id', $maintainVendors);
            }
        }

        if (isset($request->vendor_ids) && count($request->vendor_ids) != 0) {
            $model->whereIn('a.vendor_id', $request->vendor_ids);
        }
        if (isset($request->type_id) && $request->type_id != '') {
            // 看是否有子選單
            $types = GameType::where('parent', $request->type_id)->pluck('id')
                ->toArray();
            if (count($types) > 1) {
                $model->whereIn('a.type_id', $types);
            } else {
                $model->where('a.type_id', $request->type_id);
            }
        }
        //可以遊玩的排前面
        $model->where('a.status', 1);
        // 依玩家設備判斷是否顯示
        if ($request->IS_MOBILE) {
            //手機版顯示的遊戲商
            $model->where('v.show_on_mobile', 1);
        } else {
            //PC版顯示的遊戲商
            $model->where('v.show_on_pc', 1);
        }
        //寮國(總代編號38401)不提供AG體育(vendor:1，game_type:TASSPTA)
        if (is_object($agent) && strpos($agent->hierarchy, ',38401,') !== false) {
            $model->where('a.game_type', '!=', 'TASSPTA');
        }

        // 個人化查詢
        switch ($request->personal) {
            case 1: // 我的最愛
                $model->where('f.status', 1);
                break;
            case 2: // 最常遊玩
            case 3: // 贏最多
                // 取summary一個月內的記錄做統計
                $model = $model->leftJoin('summary_bet_records as r', function ($join) use ($request) {
                        $join->on('r.game_id', 'a.id')
                            ->where('r.member_id', $request->ID)
                            ->whereBetween(
                                'r.summary_time',
                                [
                                    date('Y-m-d H:i:s', strtotime('-2month')),
                                    date('Y-m-d H:i:s')
                                ]
                            );
                    })
                    ->whereNotNull('r.id');
                switch ($request->personal) {
                    case 2: // 最常遊玩
                        $model = $model->orderBy('r.bet', 'desc')
                            ->groupBy('r.game_id');
                        $model = $model->where('r.bet', '>', 0);
                        break;
                    case 3: // 贏最多
                        $model = $model->orderBy('r.win_lose', 'desc')
                            ->groupBy('r.game_id');
                        $model = $model->where('r.win_lose', '>', 0);
                        break;
                }
                break;
            default:
                $model = $model->orderBy('disable');
                break;
        }
        return $model;
    }

    /**
     * favouriteGame
     * - 加入/取消我的最愛遊戲
     *
     * @bodyParam game_id integer required 遊戲系統編號 Example: 1
     * @bodyParam status boolean required 狀態(0:取消,1:加入) Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    function setFavourite(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new FavouriteGame();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_set, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // 取消我的最愛
            $result = $model->where('member_id', $request->ID)
                ->where('game_id', $request->game_id)
                ->update([
                    'status' => 0
                ]);
            if ($request->status == 1) {
                // 新增我的最愛
                $data = array();
                $data['member_id'] = $request->ID;
                $data['game_id'] = $request->game_id;
                $data['status'] = $request->status;
                $data['admin'] = $request->ID;
                $result = $model->create($data);
            }
            DB::commit();
            if ($result) {
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'game'
                ));
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000')
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

    private function messages()
    {
        return [
            'game_id.required' => __('error_message.1407'),
            'game_id.digits_between' => __('error_message.1414'),

            'status.required' => __('error_message.2009'),
            'status.boolean' => __('error_message.2009'),

            'number.numeric' => __('error_message.2032'),

            'vendor_id.numeric' => __('error_message.1401'),
            'type_id.numeric' => __('error_message.1402'),
            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),
            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}