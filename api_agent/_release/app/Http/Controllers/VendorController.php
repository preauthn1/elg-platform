<?php

namespace App\Http\Controllers;

use App\AES;
use App\Helpers\SQLHelper;
use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\Game;
use App\Models\VendorInfo;
use App\Models\VendorImage;
use Illuminate\Http\Request;
use App\Models\Vendor;
use App\Models\Member;
use App\Repositories\AgentRepository;
use App\REDIS;
use App\Helpers\Common;
use Validator;
use DB;

class VendorController extends Controller
{

    private $playerID_required_vendors;
    private $prefix_required_vendors;


    function __construct()
    {
        $this->playerID_required_vendors = array(3 , 10 , 23);
        $this->prefix_required_vendors = array(2 , 4 , 5, 25 , 28, 32, 41, 43, 44);
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
    public function list(Request $request)
    {
        try {
            $key = array(
                'vendor',
                'list',
                $request->HOST,
                $request->LANGUAGE,
            );
            $redis = new REDIS();
            if (!$redis->exists($key) || 1) {
                //查詢這個代理開啟的遊戲商
                $vendors = $this->vendorList($request->HOST);
                //如果有設定CODE代表有錯誤，就直接拋錯誤訊息
                if (!isset($vendors['code'])) {
                    $vendorImage = DB::table('vendor_customized_items as vci')
                        ->join('agents as a', function ($join) use ($request) {
                            $join->on('vci.agent_group_id', 'a.vendor_customize_group_id')
                                ->where('vci.item_type', 1)
                                ->where('a.id', $request->HOST);
                        })
                        ->select('vci.*');
                    $bindings = $vendorImage->getBindings();
                    $vendorImageSql = str_replace('?', '%s', $vendorImage->toSql());
                    $vendorImageSql = sprintf($vendorImageSql, ...$bindings);

                    $model = Vendor::leftJoin(DB::raw("(".$vendorImageSql.") AS b" ), "b.vendor_id", "vendors.id")
                        ->where('vendors.status', 1)
                        ->whereIn('vendors.id', $vendors)
                        ->select(
                            'vendors.id',
                            DB::raw('(CASE WHEN b.name IS NULL THEN vendors.name ELSE b.name END) as name'),
                            'vendors.rate',
                            'vendors.image',
                            'vendors.app_icon'
                        );
                    $data = $model->get();
                    $redis->set($key, $data);
                } else {
                    return response()->json($vendors);
                }
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
     * vendor/username
     * -查詢遊戲商帳號
     * 
     * @bodyParam member_id integer required 會員帳號 Example: 30
     * @bodyParam vendor_id integer required 遊戲商編號
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *            "username": "系統編號,ex:1",
     *            "balance": "平台幣跟遊戲幣的轉換率,ex:10"
     *        }
     * }
     */
    public function username(Request $request)
    {
        try {
            // 驗證POST資料
            $rules = array(
                'member_id' => 'required|digits_between:0,10',
                'vendor_id' => 'required|digits_between:0,10',
            );
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            //issues_891 要根據現在的代理顯示對應遊戲商戶的前綴
            $member = Member::find($request->member_id);
            $memberVendorInfoId = $this->getVendorInfoId($member->parent, $request->vendor_id);

            //查詢各遊戲商帳號
            $vendor = Vendor::find($request->vendor_id);

            if (is_object($vendor)) {
                $member = DB::table($vendor->code . '_accounts')
                    ->where('vendor_info_id', $memberVendorInfoId)
                    ->where('member_id', $request->member_id)
                    ->first();

                if (is_object($member)) {

                    $queryAgent = DB::table("members as m")
                        ->leftJoin("agents as a" , "m.parent" , "a.id")
                        ->where("m.id" ,  $request->member_id)
                        ->select("a.hierarchy_path" )
                        ->first();

                    $agentIDs = $this->getAgentIDsFromHierarchyPath($queryAgent->hierarchy_path);

                    //根據不同遊戲商 有額外不同的前綴處理
                    $username = $this->concateVendorAccountByHierarchyAgentIDs($member, $request->vendor_id, $agentIDs);

                    $data = [
                        'username' => $username,
                        'balance' => 0
                    ];
                    // $redis->set($key, $data);
                } else {
                    //查無此玩家遊戲商帳號
                    return response()->json([
                        'code' => 1005,
                        'message' => __('error_message.1005'),
                    ]);
                }
            } else {
                //查無此遊戲商
                return response()->json([
                    'code' => 1426,
                    'message' => __('error_message.1426'),
                ]);
            }
            // } else {
            //     $data = $redis->get($key);
            // }
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

    private function getVendorInfoId($parent, $vendorId)
    {
        $vendor_info_id = 0;
        $agent = Agent::find($parent);
        if(is_object($agent)){
            $hierarchy = explode("/", $agent->hierarchy_path);
            //代理要由最下層開始往上找
            rsort($hierarchy);
            foreach ($hierarchy as $row) {
                $agentVendor = DB::table('agent_vendors as av')
                    ->join('vendor_infos as vi', function ($join) {
                        $join->on('vi.id', 'av.vendor_info_id')
                            ->where('vi.status', 1);
                    })
                    ->where('av.agent_id', $row)
                    ->select('av.*')
                    ->first();
                if (is_object($agentVendor)) {
                    $agentVendor = DB::table('agent_vendors as av')
                        ->join('vendor_infos as vi', function ($join) {
                            $join->on('vi.id', 'av.vendor_info_id')
                                ->where('vi.status', 1);
                        })
                        ->where('av.agent_id', $row)
                        ->where('av.vendor_id', $vendorId)
                        ->select('av.*')
                        ->first();
                    $vendor_info_id = isset($agentVendor->vendor_info_id) ? $agentVendor->vendor_info_id : 0;
                    break;
                }
            }
        }
        return $vendor_info_id;
    }


    /*

     * vendor/account/list
     * -查詢玩家遊戲商帳號
     *
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *
     *
     *    }
     * }
     *
    */
    public function vendor_accounts(Request $request)
    {
        try {
            // 驗證POST資料
            $rules = array(
                'vendor_id' => 'required|min:1|digits_between:0,10',
            );

            $validator = Validator::make($request->all(), $rules, $this->messages());

            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $targetVendor = DB::table("vendors")->where('id', $request->vendor_id)->first();
            $tableName = $targetVendor->code . '_accounts';


            $rep = new AgentRepository();
            $queryMyself = $rep->getById($request->HOST);


            $queryMainTable = $tableName." as va";
            $query_vendor_account = DB::table($queryMainTable)
                ->leftJoin("members as m" , "va.member_id" , "m.id")
                ->leftJoin("agents as a" , "m.parent" , "a.id")
                ->where("va.member_id" , '>' , 0)
                ->where("a.hierarchy_path" , 'like' , $queryMyself->hierarchy_path.'%');

            if(Common::IsNotNullOrEmptyString($request->username))
            {
                $query_vendor_account = $query_vendor_account->where("m.username" , $request->username);
            }

            if(Common::IsNotNullOrEmptyString($request->account_name))
            {
                //有特定幾家是用playerID
                if(in_array($targetVendor->id , $this->playerID_required_vendors))
                {
                    $query_vendor_account = $query_vendor_account->where("va.player_id" , $request->account_name);
                }
                else
                {
                    $allVendorPrefix = $this->getAllVendorAccountPrefixList($targetVendor->id);

                    foreach ($allVendorPrefix as $prefix)
                    {
                        //如果前綴相同 才會取代掉prefix (避免中間的)
                        if(substr($request->account_name, 0, strlen($prefix)) === $prefix)
                        {
                            //只取代第一組match的字串
                            $request->account_name =  $this->str_replace_first($prefix , "" ,  $request->account_name);
                        }
                    }

                    $debug1 =  $request->account_name;

                    $query_vendor_account = $query_vendor_account->where("va.username" , $request->account_name);
                }
            }

            $total = $query_vendor_account->count();

            $query_vendor_account = $query_vendor_account
                ->select("va.*" , "m.username as member_username" ,  "a.id as agent_id" , "a.hierarchy as hierarchy" ,  "a.username as agent_username" , "m.nickname as member_nickname" , "m.created_at as member_created_at")
                ->orderBy('va.id', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();


            foreach ($query_vendor_account as $account)
            {
                //要嘗試從 該代理的 Hierarchy 找出vendorInfo設置
                $hierarchy_agentIDs = $rep->parseHierarchyToArrayFormat($account->hierarchy);

                //根據不同遊戲商 有額外不同的前綴處理
                $account->vendor_username = $this->concateVendorAccountByHierarchyAgentIDs($account, $targetVendor->id, $hierarchy_agentIDs);
                $account->vendor_id = $targetVendor->id;
                $account->vendor_name = $targetVendor->name;
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $query_vendor_account,
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




    /*
        vendor/account/summary
        玩家遊戲帳號總覽
     */
    public function member_all_vendor_suumary(Request $request)
    {
        try {
            $target_member_username = $request->username;

            //先查出會員基本資料
            $queryMember = DB::table('members as m')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('m.parent', 'a.id')
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                })
                ->where('m.username',  $target_member_username)
                ->select(
                    'm.id as member_id',
                    'm.parent as parent',
                    'm.username as member_username',
                    'a.id as agent_id',
                    'a.username as agent_name',
                    'a.customized_setting_group_id',
                    'a.chat_group_id',
                    'a.hierarchy_path as hierarchy_path'
                )
                ->first();


            if(!is_object( $queryMember))
            {
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044'),
                ]);
            }

            $target_member_id = $queryMember->member_id;


            $commonInfo = array();
            $commonInfo["gcp_project_id"] = Env('GOOGLE_CLOUD_PROJECT_ID');

            //TODO 遊戲商維護中的資訊
            //TODO 遊戲商維護中的資訊
            //TODO 遊戲商維護中的資訊
            //TODO 遊戲商維護中的資訊


            //查詢各遊戲商帳號
            $allVendors = Vendor::all();

            $vendor_accounts = array();
            $vendor_accounts_mappingDic = array();




            $debugMsg = "";
            $agentIDs = $this->getAgentIDsFromHierarchyPath($queryMember->hierarchy_path);


            foreach ($allVendors as $eachVendor) {
                if (Common::IsNullOrEmptyString($eachVendor->code) ||
                    $eachVendor->code == "testgame") {
                    continue;
                }
                //issues_891 要根據現在的代理顯示對應遊戲商戶的前綴
                $memberVendorInfoId = $this->getVendorInfoId($queryMember->parent, $eachVendor->id);

                $tableName = $eachVendor->code . '_accounts';

                //加入try catch 避免table沒建立導致錯誤
                try {
                    $query_vendor_account = DB::table($tableName." as a")
                        ->where('member_id', $target_member_id)
                        ->where('vendor_info_id', $memberVendorInfoId)
                        ->first();
                    
                    $eachVendorInfo = array();
                    $eachVendorInfo["vendor_id"] = $eachVendor->id;
                    $eachVendorInfo["vendor_info_id"] = $memberVendorInfoId;
                    $eachVendorInfo["vendor_name"] = $eachVendor->name;
                    $eachVendorInfo["vendor_status"] = $eachVendor->status;
                    $eachVendorInfo["vendor_username"] = "-";

                    if (is_object($query_vendor_account)) {
                        //根據不同遊戲商 有額外不同的前綴處理
                        $eachVendorInfo["vendor_username"] = $this->concateVendorAccountByHierarchyAgentIDs($query_vendor_account, $eachVendor->id, $agentIDs);
                    }

                    //這兩個遊戲商比較特別
                    if ($eachVendor->id == 25) {
                        //AES要轉成小寫
                        $eachVendorInfo["vendor_username"] = strtolower($eachVendorInfo["vendor_username"]);
                    } elseif ($eachVendor->id == 43) {
                        //Obet33要轉成大寫
                        $eachVendorInfo["vendor_username"] = strtoupper($eachVendorInfo["vendor_username"]);
                    }

                    $vendor_accounts_mappingDic[$eachVendor->id] = $eachVendorInfo;
                    array_push($vendor_accounts  ,  $eachVendorInfo);

                } catch (\Exception $e) {

                    $debugMsg = $debugMsg.$e->getMessage();                                                                                                                        
                }
               
            }

            $queryChatDB = $this->get_ChatDB_MemberInfo($queryMember->member_username , $queryMember->agent_name);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => 
                [
                    'commonInfo' => $commonInfo,
                    "member" => $queryMember,
                    "chatDB_member" => $queryChatDB,
                    "vendor_accounts" => $vendor_accounts,
                    "vendor_accounts_mappingDic" => $vendor_accounts_mappingDic,
                    'debugMsg' => $debugMsg
                ]
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



    private function get_ChatDB_MemberInfo($username , $agent_username)
    {
        try {
            $queryCSMember = DB::connection("cs")
            ->table("members")
            ->where("third_party_id" , $username)
            ->first();

            $queryParentAgentMember = DB::connection("cs")
                ->table("members")
                ->where("username" , $agent_username)
                ->where("third_party_id" , 0)
                ->first();

            return [
                'member_id' => $queryCSMember->id,
                'parent_agent_member_id' => $queryParentAgentMember->id,
                'parent_agent_user_id' => $queryParentAgentMember->user_id,
            ];

        } catch (\Exception $e) {
            return [
                'member_id' => '',
                'parent_agent_member_id' => '',
                'parent_agent_user_id' => ''
            ];
        }
      
    }



    private function getAgentIDsFromHierarchyPath($hierarchy_path)
    {
        $agentIDs = explode("/", $hierarchy_path);
        $agentIDs = array_diff($agentIDs, [ ""]);
        return  $agentIDs;
    }



    /**
     * vendor/dropdownList
     * - 取得遊戲商下拉選單
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *                *
     *    }
     * }
     *
    */
    public function dropdownList(Request $request)
    {
        try {
            $queryVendors = DB::table("vendors")
                ->where('status', 1)
                ->select("id" , "name")
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $queryVendors,
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



    private function concateVendorAccountByHierarchyAgentIDs($query_vendor_account, $vendor_id, $hierarchy_agentIDs)
    {
        //特定遊戲商要加上prefix
        $accountPrefix = $this->getVendorAccountPrefix($vendor_id, $hierarchy_agentIDs, $query_vendor_account->vendor_info_id);

        $isRequiredPlayerID = in_array($vendor_id , $this->playerID_required_vendors);

        //特定幾家遊戲商是使用playerID
        if($isRequiredPlayerID)
        {
            return $query_vendor_account->player_id;
        }

        //剩下的都是prefix + username
        return $accountPrefix.$query_vendor_account->username;
    }



    private function getVendorAccountPrefix($vendor_id , $hierarchy_agentIDs, $vendor_info_id)
    {
        //若不是定義好需要prefix的遊戲商，直接返回空字串 避免浪費query效能
        if(!in_array($vendor_id , $this->prefix_required_vendors))
            return "";

        //TODO 加個cache吧
        //TODO 加個cache吧
        //TODO 加個cache吧
        //TODO 加個cache吧
        //TODO 加個cache吧
        //TODO 加個cache吧
        //TODO 加個cache吧
        //TODO 加個cache吧
        //TODO 加個cache吧
        //TODO 加個cache吧
        //TODO 加個cache吧
        $queryVendorInfo = VendorInfo::leftJoin("agent_vendors as av" , "av.vendor_info_id" , "vendor_infos.id")
            ->whereIn("av.agent_id" , $hierarchy_agentIDs)
            ->where("vendor_infos.vendor_id" , $vendor_id)
            ->select('vendor_infos.*')
            ->first();
        //issues_891 如果vendor_infos跟查詢者的代理的話,admin會查錯誤的vendor_info
        if(is_object($queryVendorInfo)){
            //先確定權限後,再查詢正確的vendor_info_id,效能太差的話再做更改
           $memberVendorInfo = VendorInfo::find($vendor_info_id);
        }
        $prefixResult = $this->getAccountPrefixByVendorInfo($memberVendorInfo);
        return $prefixResult;
    }


    private function getAllVendorAccountPrefixList($vendor_id)
    {
        //若不是定義好需要prefix的遊戲商，直接返回空字串 避免浪費query效能
        if(!in_array($vendor_id , $this->prefix_required_vendors))
            return array();

        $queryVendorInfo = VendorInfo::where("vendor_infos.vendor_id" , $vendor_id)
                ->select('vendor_infos.*')
                ->get();

        $allPrefix = array();

        //解析出 所有類型該遊戲商 設置的的prefix (可能 MYR站台、澳洲站台 設置prefix不同)
        foreach ($queryVendorInfo as $item) {

            $prefixResult = $this->getAccountPrefixByVendorInfo($item);
            array_push($allPrefix , $prefixResult);

        }

        return  $allPrefix;
    }


    function str_replace_first($from, $to, $content)
    {
        $from = '/'.preg_quote($from, '/').'/';

        return preg_replace($from, $to, $content, 1);
    }



    private function getAccountPrefixByVendorInfo($queryVendorInfo)
    {
        if (is_object($queryVendorInfo))
        {
            if (json_decode($queryVendorInfo->info))
            {
                $info = json_decode($queryVendorInfo->info);
            }
            else
            {
                $time = $queryVendorInfo->created_at;
                $aes = md5($time);
                $iv = date('UYm', strtotime($time));
                $aes = new AES($aes, $iv);
                $info = json_decode($aes->decrypt($queryVendorInfo->info));
            }
        }

        if(!is_object($info))
        {
            return "Error_Parsing_Fail";
        }

        switch ($queryVendorInfo->vendor_id) {
            case 2:
                return $info->APP_ID.".";
            case 4:
                return  $info->HAOLONG_USER;
            case 5:
                return  $info->API_AGENT;
            case 25:
                return  $info->BACKEND_PREFIX;
            case 28:
                return  $info->ID;
            case 32:
                return  $info->BACKEND_PREFIX.'_';
            case 41:
                return  $info->BACKEND_PREFIX;
            case 43:
                return  $info->AGENT_ID;
            case 44:
                return  $info->OPERATOR_ID.'_';
            default:
                return "";
        }
    }

    /**
     * vendor/gameList
     * -遊戲商列表
     *
     * @bodyParam vendor_id integer 遊戲商編碼 Example: 1
     * @bodyParam game_type integer 遊戲類別編碼 Example: 1
     * @bodyParam name string 遊戲名稱 Example: abc
     * @bodyParam status boolean 狀態 Example: 1
     *
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱,ex:AG",
     *            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *            "percentage": "拆帳百分比,ex:10",
     *            "image": "圖片地址,ex:http://xxxxx.xx.xx/xx.jpg",
     *            "app_icon": "遊戲商app圖示地址,ex:http://xxxxx.xx.xx/xx.jpg",
     *            "sort": "排序(數字由小排到大),ex:1",
     *            "status": "狀態(0:停用1:啟用2:開發中),ex:1"
     *        },
     *        {
     *            "id": 2,
     *            "name": "MG",
     *            "rate": "1.5",
     *            "percentage": 5,
     *            "image": "http://xxxxx.xx.xx/xx.jpg",
     *            "app_icon": "http://xxxxx.xx.xx/xx.jpg",
     *            "sort": 2,
     *            "status": 1
     *        }
     *    ]
     * }
     */
    public function gameList(Request $request)
    {
        try {
            $request->page = isset($request->page) ? $request->page : 1;
            $request->per_page = isset($request->per_page) ? $request->per_page : 20;
            $agent_game_lists = $this->agentGameList(
                $request->HOST,
                (
                    (isset($request->vendor_id) && $request->vendor_id != '')
                        ? $request->vendor_id
                        : null
                )
            );
            $model = DB::table('games as g')
                ->join('vendors as v', 'v.id', 'g.vendor_id')
                ->leftJoin('game_types as gt', 'gt.id', 'g.type_id');

            if (isset($request->vendor_id) && $request->vendor_id != '') {
                $model = $model->where('v.id', $request->vendor_id);
            }
            if (isset($request->name) && $request->name != '') {
                $model = $model->where('g.name', 'like', '%' . $request->name . '%');
            }
            if (isset($request->game_type) && $request->game_type != '') {
                $model = $model->where('gt.id', $request->game_type);
            }
            if (isset($request->status) && $request->status != '') {
                $model = $model->where('g.status', $request->status);
            }
            $total = $model->count();
            $data = $model->orderBy('g.updated_at', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    'g.id as id',
                    'v.name as vendor_name',
                    'g.game_type as game_code',
                    'gt.name as game_type',
                    'g.name as game_name',
                    'g.memo as remark',
                    'g.image as image',
                    'g.status as status',
                    'g.admin as admin'
                )
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
     * vendor/gameList
     * -遊戲商列表
     *
     * @bodyParam status boolean 狀態 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱,ex:AG",
     *            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *            "percentage": "拆帳百分比,ex:10",
     *            "image": "圖片地址,ex:http://xxxxx.xx.xx/xx.jpg",
     *            "app_icon": "遊戲商app圖示地址,ex:http://xxxxx.xx.xx/xx.jpg",
     *            "sort": "排序(數字由小排到大),ex:1",
     *            "status": "狀態(0:停用1:啟用2:開發中),ex:1"
     *        },
     *        {
     *            "id": 2,
     *            "name": "MG",
     *            "rate": "1.5",
     *            "percentage": 5,
     *            "image": "http://xxxxx.xx.xx/xx.jpg",
     *            "app_icon": "http://xxxxx.xx.xx/xx.jpg",
     *            "sort": 2,
     *            "status": 1
     *        }
     *    ]
     * }
     */
    public function getVendorList(Request $request)
    {
        try {
            $model = Vendor::select(
                'id',
                'name',
                'rate',
                'percentage',
                'image',
                'app_icon',
                'sort',
                'status',
                'remark',
                'valid_bet_rate'
            );
            if (isset($request->status) && $request->status != '') {
                $model = $model->where('status', $request->status);
            }
            $data = $model->orderBy('updated_at', 'desc')->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
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
     * vendor/view
     * -遊戲商列表
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *        "id": "系統編號,ex:1",
     *        "name": "名稱,ex:AG",
     *        "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *        "percentage": "拆帳百分比,ex:10",
     *        "image": "圖片地址,ex:http://xxxxx.xx.xx/xx.jpg",
     *        "app_icon": "遊戲商app圖示地址,ex:http://xxxxx.xx.xx/xx.jpg",
     *        "sort": "排序(數字由小排到大),ex:1",
     *        "status": "狀態(0:停用1:啟用2:開發中),ex:1",
     *        "remark": "備註,ex:abc",
     *        "valid_bet_rate": "有效轉換比例,ex:1",
     *        "open_window": "狀態(0:停用1:啟用2:開發中),ex:1"
     *    }
     * }
     */
    public function show(Request $request)
    {
        try {
//            $model = new Vendor();
            // 驗證POST資料
//            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
//            if (!$validator->passes()) {
//                return response()->json([
//                    'code' => 2002,
//                    'message' => __('error_message.2002'),
//                    'data' => $validator->errors()
//                        ->all()
//                ]);
//            }
            $key = array(
                'vendor',
                $request->id
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $data = Vendor::select(
                        'id',
                        'name',
                        'rate',
                        'percentage',
                        'image',
                        'app_icon',
                        'sort',
                        'status',
                        'remark',
                        'valid_bet_rate',
                        'open_window'
                    )
                    ->find($request->id);
                if (is_object($data)) {
                    $redis->set($key, $data);
                } else {
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ]);
                }
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
     * vendor/add
     * -新增遊戲商
     *
     * @bodyParam name string required 遊戲商名稱 Example: AG
     * @bodyParam rate flaot required 域名 Example: 1.5
     * @bodyParam percentage flaot required 域名 Example: 30
     * @bodyParam image string required base64 file 廣告圖片
     * @bodyParam app_icon string required base64 file 遊戲商app icon
     * @bodyParam status integer required 狀態(0停用,1正常,2開發中) Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "系統編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $data = array();
            $model = new Vendor();
//            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
//            if (!$validator->passes()) {
//                return response()->json([
//                    'code' => 2002,
//                    'message' => __('error_message.2002'),
//                    'data' => $validator->errors()
//                        ->all()
//                ]);
//            }
            $data['name'] = $request->name;
            $data['rate'] = $request->rate;
            $data['percentage'] = $request->percentage;
            $data['valid_bet_rate'] = $request->valid_bet_rate;
            $data['remark'] = $request->remark;
            // 移動圖檔至本地及上傳到GCP
            if (isset($request->image)) {
                $path = $this->uploadBase64File($request->image);
                if ($path['code'] != 1000) {
                    return response()->json([
                        'code' => $path['code'],
                        'message' => $path['message']
                    ]);
                }
                $data['image'] = $path['message'];
            }
            // 移動圖檔至本地及上傳到GCP
            if (isset($request->app_icon)) {
                $path = $this->uploadBase64File($request->app_icon);
                if ($path['code'] != 1000) {
                    return response()->json([
                        'code' => $path['code'],
                        'message' => $path['message']
                    ]);
                }
                $data['app_icon'] = $path['message'];
            }
            $data['sort'] = isset($request->sort) ? $request->sort : 0;
            $data['status'] = isset($request->status) ? $request->status : 0;
            $data['admin'] = $request->ID;
            if ($result = $model->create($data)) {
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'vendor'
                ));
                $redis->delete(array(
                    'agentVendor'
                ));
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
     * vendor/edit
     * -編輯遊戲商
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam name string  遊戲商名稱 Example: AG
     * @bodyParam rate flaot  匯率 Example: 1.5
     * @bodyParam percentage flaot  分成比 Example: 30
     * @bodyParam image string  base64 file 廣告圖片
     * @bodyParam app_icon string  base64 file 遊戲商app icon
     * @bodyParam sort integer  排序 Example: 2
     * @bodyParam open_window integer  另開視窗 Example: 0
     * @bodyParam status integer  狀態(0停用,1正常,2開發中) Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new Vendor();
//            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
//            if (!$validator->passes()) {
//                return response()->json([
//                    'code' => 2002,
//                    'message' => __('error_message.2002'),
//                    'data' => $validator->errors()
//                        ->all()
//                ]);
//            }
            if ($row = $model->find($request->id)) {
                $data = array();
                $data['name'] = isset($request->name) ? $request->name : $row->name;
                $data['rate'] = isset($request->rate) ? $request->rate : $row->rate;
                $data['percentage'] = isset($request->percentage) ? $request->percentage : $row->percentage;
                $data['valid_bet_rate'] = isset($request->valid_bet_rate) ? $request->valid_bet_rate : $row->valid_bet_rate;
                $data['remark'] = isset($request->remark) ? $request->remark : $row->remark;
                // 移動圖檔至本地及上傳到GCP
                if (isset($request->image)) {
                    $path = $this->uploadBase64File($request->image);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $data['image'] = $path['message'];
                }
                // 移動圖檔至本地及上傳到GCP
                if (isset($request->app_icon)) {
                    $path = $this->uploadBase64File($request->app_icon);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $data['app_icon'] = $path['message'];
                }
                $data['sort'] = isset($request->sort) ? $request->sort : $row->sort;
                $data['open_window'] = isset($request->open_window) ? $request->open_window : $row->open_window;
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'vendor'
                    ));
                    $redis->delete(array(
                        'agentVendor'
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
            } else {
                return response()->json([
                    'code' => 1213,
                    'message' => __('error_message.1213')
                ]);
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

    public function getVendorImageSetting(Request $request)
    {
        try {
        $model = new Vendor();
//        $vendorImage = DB::table('vendor_images as vi')
//            ->join('agent_groups as ag', function ($join) use ($request) {
//                $join->on('ag.id', 'vi.agent_group_id')
//                ->where('')
//            })
//                ->select('vi.*');
        $group = DB::table('agent_groups')->find($request->group_id);
        $data = $model->leftJoin('vendor_images as vi', function ($join) use ($request) {
            $join->on('vi.vendor_id', 'vendors.id')
                ->where('agent_group_id', $request->group_id);
        })
            ->select(
                'vendors.id as id',
                'vendors.name as name',
                'vi.name  as show_name',
                'vi.image  as image',
                'vi.app_icon as app_icon'
            )
            ->orderBy('id', 'desc')
            ->get();


            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => array(
                    'groups' => $group,
                    'data' => $data
                )
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

    public function updateVendorImageSetting(Request $request)
    {
        try {
            $vendor_image_datas = $request->vendor_image_datas;
            //先查詢已有設定的遊戲商
            $hasData = VendorImage::where('agent_group_id', $request->group_id)->pluck('vendor_id')->toArray();
            foreach($vendor_image_datas as $row)
            {
                if($row['show_name'] != '' || $row['image'] != '' || $row['app_icon'] != '') {
                    //如果是空值,要設定為NULL
                    $name = $row['show_name'] == '' ? NULL : $row['show_name'];
                    $image = $row['image'] == '' ? NULL : $row['image'];
                    $app_icon = $row['app_icon'] == '' ? NULL : $row['app_icon'];
                    //如果已有設定就用update,沒有就用create
                    if(in_array($row['vendor_id'], $hasData)) {
                        $data = VendorImage::where('agent_group_id', $request->group_id)
                            ->where('vendor_id', $row['vendor_id'])
                            ->first();
                        if ($data){
                            $data->update(
                                [
                                    'name' => $name,
                                    'image' => $image,
                                    'app_icon' => $app_icon
                                ]
                            );
                        }
                    } else {
                        $data = array(
                            'agent_group_id' => $request->group_id,
                            'vendor_id' => $row['vendor_id'],
                            'name' => $name,
                            'image' => $image,
                            'app_icon' => $app_icon
                        );
                        VendorImage::create($data);
                    }
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