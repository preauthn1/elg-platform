<?php

namespace App\Http\Controllers;

use App\AES;
use App\Models\Game;
use App\Models\VendorInfo;
use Illuminate\Http\Request;
use App\Models\VendorInfoGame;
use App\Models\Vendor;
use App\REDIS;
use App\Helpers\Common;
use Validator;
use DB;

class VendorInfoController extends Controller
{

    /**
     * vendor_info/list
     * -vendor_info 列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱,ex:AG",
     *            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *            "image": "遊戲商LOGO,ex:http://xxx.xxx.xx",
     *            "app_icon": "遊戲商app圖示地址,ex:http://xxxxx.xx.xx/xx.jpg"
     *        },
     *        {
     *            "id": 2,
     *            "name": "MG",
     *            "rate": "1.5",
     *            "image": "http://example.invalid.xx",
     *            "app_icon": "http://xxxxx.xx.xx/xx.jpg"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = DB::table('vendor_infos as vi')
                ->join('vendors as v', function ($join) use ($request) {
                    $join->on('v.id', 'vi.vendor_id')
                        ->where('v.status', 1);
                    if ($request->vendor_id > 0) {
                        $join->where('v.id', $request->vendor_id);
                    }
                });

            if ($request->status != '') {
                $model = $model->where('vi.status', $request->status);
            }
            if (Common::IsNotNullOrEmptyString($request->vendor_info_id)) {
                $model = $model->where('vi.id', $request->vendor_info_id);
            }

            $total = $model->count();

            $data = $model->orderBy('vi.updated_at', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    'vi.id',
                    'vi.name',
                    'vi.vendor_id',
                    'v.name as vendor_name',
                    'vi.currency',
                    'example.invalid',
                    'vi.prepared_account_number',
                    'vi.account_prefix',
                    'vi.created_at',
                    'vi.status'
                )
                ->orderBy('vi.updated_at', 'desc')
                ->get();
            if (count($data) > 0) {
                foreach ($data as $row) {
                    if (!json_decode($row->info)) {
                        $time = $row->created_at;
                        $aes = md5($time);
                        $iv = date('UYm', strtotime($time));
                        $aes = new AES($aes, $iv);
                        $row->info = $aes->decrypt($row->info);
                    }
                }
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
     * vendor/list
     * -遊戲商列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱,ex:AG",
     *            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *            "image": "遊戲商LOGO,ex:http://xxx.xxx.xx",
     *            "app_icon": "遊戲商app圖示地址,ex:http://xxxxx.xx.xx/xx.jpg"
     *        },
     *        {
     *            "id": 2,
     *            "name": "MG",
     *            "rate": "1.5",
     *            "image": "http://example.invalid.xx",
     *            "app_icon": "http://xxxxx.xx.xx/xx.jpg"
     *        }
     *    ]
     * }
     */
    public function view(Request $request)
    {
        try {
            $data = DB::table('vendor_infos as vi')
                ->join('vendors as v', 'v.id', 'vi.vendor_id')
                ->where('vi.id', $request->id)
                ->select(
                    'vi.id',
                    'vi.name',
                    'vi.vendor_id',
                    'v.name as vendor_name',
                    'vi.currency',
                    'example.invalid',
                    'vi.prepared_account_number',
                    'vi.account_prefix',
                    'vi.created_at',
                    'vi.status',
                    'vi.rate',
                    DB::raw('vi.transfer_min_amount * vi.rate as transfer_min_amount')
                )
                ->first();
            if (json_decode($data->info)) {
                $data->type = 1;
            } else {
                $time = $data->created_at;
                $aes = md5($time);
                $iv = date('UYm', strtotime($time));
                $aes = new AES($aes, $iv);
                $data->info = $aes->decrypt($data->info);
                $data->type = 0;
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
     * vendor/list
     * -遊戲商列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱,ex:AG",
     *            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *            "image": "遊戲商LOGO,ex:http://xxx.xxx.xx",
     *            "app_icon": "遊戲商app圖示地址,ex:http://xxxxx.xx.xx/xx.jpg"
     *        },
     *        {
     *            "id": 2,
     *            "name": "MG",
     *            "rate": "1.5",
     *            "image": "http://example.invalid.xx",
     *            "app_icon": "http://xxxxx.xx.xx/xx.jpg"
     *        }
     *    ]
     * }
     */
    public function getVendorGame(Request $request)
    {
        try {
            $data = DB::table('games as g')
                ->leftJoin('vendor_info_games as vig', function ($join) use ($request) {
                    $join->on('vig.game_id', 'g.id')
                        ->where('vig.vendor_info_id', $request->id);
                })
                ->leftJoin('game_types as gt', 'gt.id', 'g.type_id')
                ->leftJoin('game_type_names as gtn', function ($join) use ($request) {
                    $join->on('gtn.game_type_id', 'gt.id')
                        ->where('gtn.language_id', $request->LANGUAGE);
                })
                ->where('g.vendor_id', $request->vendor_id)
                ->where('g.status', 1)
                ->orderBy('g.type_id')
                ->select(
                    'g.id',
                    'g.name',
                    'vig.status as status',
                    'g.type_id',
                    DB::raw('IFNULL(gtn.name, gt.name) as game_type_name')
                )
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
     * vendor/list
     * -遊戲商列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱,ex:AG",
     *            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *            "image": "遊戲商LOGO,ex:http://xxx.xxx.xx",
     *            "app_icon": "遊戲商app圖示地址,ex:http://xxxxx.xx.xx/xx.jpg"
     *        },
     *        {
     *            "id": 2,
     *            "name": "MG",
     *            "rate": "1.5",
     *            "image": "http://example.invalid.xx",
     *            "app_icon": "http://xxxxx.xx.xx/xx.jpg"
     *        }
     *    ]
     * }
     */
    public function edit(Request $request)
    {
        try {
            $model = new VendorInfo();
            $data = array();
            $data['name'] = $request->name;
            $data['vendor_id'] = $request->vendor_id;
            $data['currency'] = $request->currency;
            $data['info'] = $request->info;
            $data['status'] = $request->status;
            $data['prepared_account_number'] = $request->prepared_account_number;
            $data['account_prefix'] = $request->account_prefix;
            $data['rate'] = $request->rate;
            $data['transfer_min_amount'] = $request->transfer_min_amount;

            if ($request->type == 0) {
                $time = $request->created_at;
                $info = json_decode($request->info);
                $aes = md5($time);
                $iv = date('UYm', strtotime($time));
                $aes = new AES($aes, $iv);
                $data['info'] = $aes->encrypt(json_encode($info));
            } else {
                $data['info'] = $request->info;
            }

            if ($model->where('id', $request->id)->update($data)) {
                if (!empty($request->newgames)) {
                    foreach ($request->newgames as $val) {
                        $sql = 'insert into vendor_info_games'
                            . '(vendor_info_id, game_id, join_bet_event, join_win_event, '
                            . 'is_new, is_hot, sort, valid_bet_rate, status) '
                            . 'select ?, g.id, g.join_bet_event, g.join_win_event, '
                            . 'g.is_new, g.is_hot, g.sort, g.valid_bet_rate, 0 '
                            . 'from games as g where g.id = ? '
                            . 'and not exists ('
                            . 'select * from vendor_info_games vig '
                            . 'where vig.vendor_info_id = ? and vig.game_id = g.id)';
                        DB::insert($sql, [$request->id, $val, $request->id]);
                    }
                }
                if (VendorInfoGame::where('vendor_info_id', $request->id)->update(['status' => 0])) {
                    if (VendorInfoGame::where('vendor_info_id', $request->id)
                        ->whereIn('game_id', $request->games)
                        ->update(['status' => 1])) {
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                        ]);
                    }
                }
            }

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
     * vendor/list
     * -遊戲商列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱,ex:AG",
     *            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *            "image": "遊戲商LOGO,ex:http://xxx.xxx.xx",
     *            "app_icon": "遊戲商app圖示地址,ex:http://xxxxx.xx.xx/xx.jpg"
     *        },
     *        {
     *            "id": 2,
     *            "name": "MG",
     *            "rate": "1.5",
     *            "image": "http://example.invalid.xx",
     *            "app_icon": "http://xxxxx.xx.xx/xx.jpg"
     *        }
     *    ]
     * }
     */
    public function add(Request $request)
    {
        try {
            $model = new VendorInfo();
            $data = array();
            $data['name'] = $request->name;
            $data['vendor_id'] = $request->vendor_id;
            $data['currency'] = $request->currency;
            $data['info'] = $request->info;
            $data['prepared_account_number'] = $request->prepared_account_number;
            $data['account_prefix'] = $request->account_prefix;
            $data['rate'] = $request->rate;
            $data['transfer_min_amount'] = floatval($request->transfer_min_amount / $request->rate);
            if ($vendor_info = $model->create($data)) {
                $sql = 'insert into vendor_info_games'
                    . '(vendor_info_id, game_id, join_bet_event, join_win_event, '
                    . 'is_new, is_hot, sort, valid_bet_rate, status) '
                    . 'select vi.id, g.id, g.join_bet_event, join_win_event, '
                    . 'g.is_new, g.is_hot, g.sort, g.valid_bet_rate, 0 '
                    . 'from games as g '
                    . 'join vendors as v on v.id = g.vendor_id '
                    . 'join vendor_infos as vi on vi.vendor_id = v.id and vi.id =?';
                if (DB::insert($sql, [$vendor_info->id])) {
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                }
            };

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
     * vendor/getVendorInfoGame
     * -遊戲商列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱,ex:AG",
     *            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *            "image": "遊戲商LOGO,ex:http://xxx.xxx.xx",
     *            "app_icon": "遊戲商app圖示地址,ex:http://xxxxx.xx.xx/xx.jpg"
     *        },
     *        {
     *            "id": 2,
     *            "name": "MG",
     *            "rate": "1.5",
     *            "image": "http://example.invalid.xx",
     *            "app_icon": "http://xxxxx.xx.xx/xx.jpg"
     *        }
     *    ]
     * }
     */
    public function getVendorInfoGame(Request $request)
    {
        try {
            $data = DB::table('vendor_info_games as vig')
                ->join('vendor_infos as vi', 'vi.id', 'vig.vendor_info_id')
                ->where('vig.game_id', $request->id)
                ->select(
                    'vig.id',
                    'vi.name as name',
                    'vig.join_bet_event',
                    'vig.join_win_event',
                    'vig.is_new',
                    'vig.is_hot',
                    'vig.valid_bet_rate',
                    'vig.status',
                    'vig.sort'
                )
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
     * vendor-info/find-by-agent
     * - 查出某個代理所有的vendorInfo
     */
    public function findVendorInfoByAgent(Request $request)
    {
        try {

            $queryAgent = DB::table("agents")->where("username" , $request->username)->first();

            if(!is_object($queryAgent)) {               
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044'),
                ]);
            }
           
            $hierarchy_agents = explode("/", $queryAgent->hierarchy_path);
            $hierarchy_agents = array_diff($hierarchy_agents, [ "" ]);     


            $queryVendors = DB::table("vendors")
                ->where("status" , 1)
                ->get();

            $queryAllVendorInfoes = DB::table("agent_vendors as av")
                ->leftJoin("vendor_infos as vi" , "av.vendor_info_id" , "vi.id")
                ->whereIn("agent_id" , $hierarchy_agents)
                ->orderBy("agent_id" , 'desc')
                ->select("av.agent_id" , "av.vendor_id" , "av.vendor_info_id" , 'vi.name')
                ->get();             
        
            $queryAllVendorInfoes = \json_decode(json_encode($queryAllVendorInfoes) , true);

            $vendor_info_source_agents = array();
            $vendorMapping = array();
            foreach ($queryVendors as $item) {

                $match = $this->findItemByVendorID($queryAllVendorInfoes , $item->id);

                if(is_null($match))
                {
                    array_push( $vendorMapping , [
                        'vendor_id' => $item->id,
                        'vendor_name' => $item->name,
                        'vendor_info_id' => '',
                        'source_agent' => '',
                        'vendor_info_name' => '',
                    ]);
                }
                else
                {
                    array_push($vendor_info_source_agents , $match["agent_id"]);
                    array_push($vendorMapping , [
                        'vendor_id' => $item->id,
                        'vendor_name' => $item->name,
                        'vendor_info_id' => $match["vendor_info_id"],                        
                        'source_agent' => $match["agent_id"],                        
                        'vendor_info_name' => $match["name"], 
                    ]);
                }
            }

            $result = array();
            $result["agent_id"] = $queryAgent->id;
            $result["agent_username"] = $queryAgent->username;
            $result["hierarchy_path"] = $queryAgent->hierarchy_path;    
            $result["vendor_info_source_agents"] = array_unique($vendor_info_source_agents);          
            $result["vendor_info_list"] = $vendorMapping;

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $result
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



    private function findItemByVendorID($queryAllVendorInfoes , $targetVendorID)
    {
        foreach($queryAllVendorInfoes as $item)
        {
            if($item["vendor_id"] == $targetVendorID)
            {
                return $item;
            }
        }

        return null;
    }


    private function messages()
    {
        return [
            'member_id.required' => __('error_message.1303'),
            'member_id.digits_between' => __('error_message.1303'),

            'vendor_id.required' => __('error_message.1504'),
            'vendor_id.digits_between' => __('error_message.1505'),
        ];
    }
}