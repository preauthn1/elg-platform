<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Agent;
use App\AgentPermission;
use App\AgentMenu;
use App\AgentVendor;
use App\Helpers\Common;
use App\Vendor;
use App\REDIS;
use Hash;
use Validator;
use DB;

class AgentController extends Controller
{

    /**
     * agent/list
     * -營運商列表(只能查看自己的下線，如果在我們下一層的代理，可以查詢他下面所有的代理)
     *
     * @bodyParam id integer 營運商系統編號 Example: 1
     * @bodyParam username string 帳號 Example: xxx
     * @bodyParam email string 電子信箱 Example: xxx
     * @bodyParam status boolean  營運商狀態(0:凍結,1:正常) Example: 1 or 0
     * @bodyParam include_sub_account integer 是否包含子帳號，預設為1:包含(0:不包含,1:包含) Example: 1 or 0
     * @bodyParam include_self integer 是否包含自己，預設為0:不包含(0:不包含,1:包含) Example: 1 or 0
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "營運商系統編號,ex:1",
     *            "host_id": "主帳號系統編號,ex:1",
     *            "parent": "上層代理的系統編號(0的話是第一層),ex:0",
     *            "parent_username": "上層代理的代理帳號(0的話是第一層),ex:0",
     *            "code": "推薦碼,ex:123456",
     *            "username": "營運商帳號,ex:dmin",
     *            "balance": "餘額,ex:100",
     *            "email": "營運商email,ex:user@example.invalid",
     *            "nickname": "暱稱,ex:xxx",
     *            "mobile": "手機號碼,ex:0912345678",
     *            "percentage": "分成百分比,ex:5",
     *            "timezone_id": "時區系統編號,ex:1",
     *            "country_id": "國家系統編號,ex:1",
     *            "zip_code": "區碼,ex:230",
     *            "district": "地區,ex:中正區",
     *            "city": "城市,ex:台北市",
     *            "address": "地址,ex:忠孝西路",
     *            "email_validate": "是否需要電子信箱認證0:不必需1:必需,ex:1",
     *            "mobile_validate": "是否需要手機認證0:不必需1:必需,ex:1",
     *            "frontend_register":"前端是否開放註冊0:不開放1:開放,ex:1",
     *            "has_chat":"是否開放聊天室, 0:不開放1:開放,ex:1",
     *            "has_pc":"是否有PC版，0:沒有1:有,ex:1",
     *            "has_mobile":"是否有手機版，0:沒有1:有,ex:1",
     *            "has_online_payment":"是否有線上儲值，0:沒有1:有,ex:1",
     *            "has_app":"是否有APP下載，0:沒有1:有,ex:1",
     *            "transaction_all":"進入遊戲時是否將所有錢轉入，0:否1:是,ex:1",
     *            "agent_backend_transaction":"是否可以在代理後台開洗分，0:否1:是,ex:1",
     *            "status": "狀態(0:凍結,1:正常),ex:1",
     *            "last_login": "最後登入時間,ex:2019-04-09 01:28:23",
     *            "created_at": "建立時間,ex:2019-04-09 01:28:23"
     *        }
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
    public function list(Request $request)
    {
        try {
            $model = new Agent();
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
            // $key = array(
            //     'agent',
            //     'list',
            //     $request->ID,
            //     $request->id,
            //     $request->username,
            //     $request->email,
            //     $request->status,
            //     $request->include_sub_account,
            //     $request->include_self,
            //     $request->page,
            //     $request->per_page
            // );
            // $total_key = array(
            //     'agent',
            //     'list',
            //     $request->ID,
            //     $request->id,
            //     $request->username,
            //     $request->email,
            //     $request->status,
            //     $request->include_sub_account,
            //     $request->include_self,
            //     'total'
            // );
            // $redis = new REDIS();
            // if (!$redis->exists($key)) {
                if ($this->isChild($request->HOST, $request->id) || $request->HOST == $request->id || $request->id == '') {
                    $model = DB::table('agents as a')->leftJoin('agents as p', 'a.parent', 'p.id');
                    if (isset($request->username)) {
                        $model = $model->where('a.username', 'like', '%' . $request->username . '%');
                    }
                    if (isset($request->email)) {
                        $model = $model->where('a.email', 'like', '%' . $request->email . '%');
                    }
                    if (isset($request->status)) {
                        $model = $model->where('a.status', $request->status);
                    }
                    // 不包含子帳號
                    if (isset($request->include_sub_account) && $request->include_sub_account == 0) {
                        $model = $model->where('a.host_id', 0);
                    }
                    $model = $model->where(function ($query) use ($request) {
                        if (isset($request->id)) {
                            $query->orWhere('a.parent', $request->id);
                        } else {
                            //如果是子帳號，查詢他的主帳號的資訊並使用他的parent
                            $host = DB::table('agents')->where('id',$request->HOST)->first();
                            if ($host->parent <= 1) {
                                //總公司及第一層會員可以查看他所有的下線帳號
                                $query->orWhere('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                            } else {
                                $query->orWhere('a.parent', $request->HOST);
                            }
                        }
                        // 是否包含自己
                        if (isset($request->include_self) && $request->include_self == 1) {
                            //包含
                            $query->orWhere('a.id', $request->HOST);
                        } else {
                            //不要查詢到自己
                            $query->where('a.id', '!=', $request->HOST);
                        }
                    });
                    //用最後操作者id查詢username
                    $model->leftJoin('agents as an', 'a.admin', 'an.id');
                    $model->leftJoin('agents as ac', 'a.created_by', 'ac.id');
                    $total = $model->count();
                    $data = $model->select('a.id', 'a.host_id', 'a.parent', 'p.username as parent_username', 'a.code', 'a.username', 'a.balance', 'a.email', 'a.nickname', 'a.mobile', 'a.timezone_id', 'a.country_id', 'a.zip_code', 'a.district', 'a.city', 'a.address', 'a.email_validate', 'a.mobile_validate', 'a.frontend_register', 'a.has_chat', 'a.has_pc', 'a.has_mobile', 'a.has_online_payment', 'a.has_app', 'a.transaction_all', 'a.agent_backend_transaction', 'a.status', 'a.last_login', DB::raw('DATE_ADD(a.created_at ,INTERVAL 8 HOUR) as created_at'), 'an.username as operator', 'ac.username as created_by')
                        ->orderBy('a.id', 'desc')
                        ->skip(($request->page - 1) * $request->per_page)
                        ->take($request->per_page)
                        ->get();
                    // $redis->set($key, $data);
                    // $redis->set($total_key, $total);
                } else {
                    return response()->json([
                        'code' => 9003,
                        'message' => __('error_message.9003')
                    ]);
                }
            // } else {
            //     $data = $redis->get($key);
            //     $total = $redis->get($total_key);
            // }
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
     * agent/view
     * -營運商詳細資料(只能查看自己的下線)
     *
     * @bodyParam id string required 營運商系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *        "id": "營運商系統編號,ex:1",
     *        "host_id": "主帳號系統編號,ex:1",
     *        "parent": "上層系統編號(0的話是第一層),ex:0",
     *        "parent_username": "上層代理帳號,ex:ADMIN",
     *        "code": "推薦碼,ex:123456",
     *        "username": "營運商帳號,ex:Admin",
     *        "balance": "餘額,ex:100",
     *        "email": "營運商email,ex:user@example.invalid",
     *        "nickname": "暱稱,ex:xxx",
     *        "mobile": "手機號碼,ex:0912345678",
     *        "percentage": "分成百分比,ex:5",
     *        "timezone_id": "時區系統編號,ex:1",
     *        "country_id": "國家系統編號,ex:1",
     *        "zip_code": "郵遞區號,ex:853",
     *        "district": "地區,ex:中正區",
     *        "city": "城市,ex:台北市",
     *        "address": "地址,ex:忠孝西路1號1樓",
     *        "email_validate": "是否需要電子信箱認證0:不必需1:必需,ex:1",
     *        "mobile_validate": "是否需要手機認證0:不必需1:必需,ex:1",
     *        "frontend_register":"前端是否開放註冊0:不開放1:開放,ex:1",
     *        "has_chat":"是否開放聊天室, 0:不開放1:開放,ex:1",
     *        "has_pc":"是否有PC版，0:沒有1:有,ex:1",
     *        "has_mobile":"是否有手機版，0:沒有1:有,ex:1",
     *        "has_online_payment":"是否有線上儲值，0:沒有1:有,ex:1",
     *        "has_app":"是否有APP下載，0:沒有1:有,ex:1",
     *        "transaction_all":"進入遊戲時是否將所有錢轉入，0:否1:是,ex:1",
     *        "agent_backend_transaction":"是否可以在代理後台開洗分，0:否1:是,ex:1",
     *        "status": "狀態-0凍結1正常,ex:1",
     *        "last_login": "最後登入時間,ex:2019-04-01 09:36:46"
     *    }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Agent();
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
                'agent',
                'show',
                $request->id
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                if ($this->isChild($request->ID, $request->id) || $request->ID == $request->id || $request->id == '') {
                    $data = $model->select(
                    	'agents.id', 
                    	'agents.host_id', 
                    	'agents.parent', 
                    	'p.username as parent_username', 
                    	'agents.code', 
                    	'agents.username', 
                    	'agents.balance', 
                    	'agents.email', 
                    	'agents.nickname', 
                    	'agents.mobile', 
                    	'agents.timezone_id', 
                    	'agents.country_id', 
                    	'agents.zip_code', 
                    	'agents.district', 
                    	'agents.city', 
                    	'agents.address', 
                    	'agents.email_validate', 
                    	'agents.mobile_validate', 
                    	'agents.frontend_register',
                    	DB::raw('p.agent_mode as parent_agent_mode'), 
                    	'agents.agent_mode', 
                    	'agents.has_chat', 
                    	'agents.has_pc', 
                    	'agents.has_mobile', 
                    	'agents.has_online_payment', 
                    	'agents.has_app', 
                    	'agents.transaction_all', 
                    	'agents.agent_backend_transaction', 
                    	'agents.status', 
                    	'agents.last_login'
                    	)
                        ->leftJoin('agents as p', 'p.id', 'agents.parent')
                        ->where('agents.id', $request->id)->first();
                    if (!$data) {
                        return response()->json([
                            'code' => 1005,
                            'message' => __('error_message.1005')
                        ]);
                    }
                    $redis->set($key, $data);
                } else {
                    return response()->json([
                        'code' => 9003,
                        'message' => __('error_message.9003')
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
     * agent/add
     * -新增營運商(只能新增自己的下線)
     *
     * @bodyParam parent integer 上層代理系統編號 Example: 1
     * @bodyParam username unique required 帳號 Example: agent_username
     * @bodyParam email email  電子信箱 Example: user@example.invalid
     * @bodyParam nickname string 暱稱 Example: xxx
     * @bodyParam password string required 營運商密碼
     * @bodyParam password_confirmation string required 營運商確認密碼
     * @bodyParam mobile string 聯絡電話 Example: 0912345678
     * @bodyParam percentage integer 分成百分比 Example: 5
     * @bodyParam timezone_id integer 時區系統編號 Example: 1
     * @bodyParam country_id string 國家 Example: 台灣
     * @bodyParam zip_code string 郵遞區號 Example: 100
     * @bodyParam district string 區域 Example: 中正區
     * @bodyParam city string 城市 Example: 台北市
     * @bodyParam address string 地址 Example: 忠孝西路1號1樓
     * @bodyParam email_validate boolean required 是否需要電子信箱認證0:不必需1:必需 Example: 1
     * @bodyParam mobile_validate boolean required 是否需要手機認證0:不必需1:必需 Example: 1
     * @bodyParam frontend_register boolean required 是否開放會員註冊0:不開放,1:開放 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "系統編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new Agent();
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
            DB::beginTransaction();
            $parent_id = (isset($request->parent) && $request->parent != '') ? $request->parent : $request->HOST;
            $parent = Agent::find($parent_id);
            if (!is_object($parent)) {
                //營運商不存在
                return response()->json([
                    'code' => 1050,
                    'message' => __('error_message.1050')
                ]);
            }
            $data = array();
            $data['parent'] = $parent_id;
            $data['host_id'] = $request->is_sub_account ? $parent_id : 0;
            $data['code'] = $request->is_sub_account ? '-' : $this->getShareCode();
            $data['username'] = $request->username;
            $data['email'] = $request->email;
            $data['nickname'] = $request->nickname;
            $data['password'] = Hash::make($request->password);
            $data['mobile'] = $request->mobile;
            $data['percentage'] = $request->percentage;
            $data['timezone_id'] = $request->timezone_id;
            $data['country_id'] = $request->country_id;
            $data['zip_code'] = $request->zip_code;
            $data['district'] = $request->district;
            $data['city'] = $request->city;
            $data['address'] = $request->address;
            $data['email_validate'] = $request->email_validate;
            $data['mobile_validate'] = $request->mobile_validate;
            $data['frontend_register'] = $request->frontend_register;
            $data['agent_mode'] = (isset($request->agent_mode))? $request->agent_mode : 0;
            /* 開關設定與上線一致 */
            $data['has_chat'] = $parent->has_chat;
            $data['has_pc'] = $parent->has_pc;
            $data['has_mobile'] = $parent->has_mobile;
            $data['has_online_payment'] = $parent->has_online_payment;
            $data['has_app'] = $parent->has_app;
            $data['transaction_all'] = $parent->transaction_all;
            $data['agent_backend_transaction'] = $parent->agent_backend_transaction;
            $data['status'] = 1;
            $data['cs_sync_flag'] =  $parent->has_chat;
            $data['admin'] = $request->ID;
            $data['created_by'] = $request->ID;
            if ($result = Agent::create($data)) {
                //更新hierarchy
                $hierarchy = $this->listParent($result->id, true);
                array_unshift($hierarchy, -1);
                $hierarchy[] = -2;
                Agent::where('id', $result->id)->update(['hierarchy' => json_encode($hierarchy)]);
                //123
                //寫入權限
                // $data = array();
                // $data['agent_id'] = $result->id;
                // // 給予所有選單的權限
                // $menus = AgentMenu::get();
                // foreach ($menus as $row) {
                //     //預設不給活動選單，代理管理, 新增會員, 遊戲商管理
                //     $cut_menu = [1, 2, 3, 4, 5, 6, 9, 10, 12, 13, 14, 15, 16, 17, 18, 25];
                //     if (!in_array($row['id'], $cut_menu)) {
                //         $data['agent_menu_id'] = $row['id'];
                //         AgentPermission::create($data);
                //     }
                // }

                // 預設開啟上層開啟的遊戲商(會自動繼承，所以不需要寫入了)
                // $data = array();
                // $data['agent_id'] = $result->id;
                // $data['status'] = 1;

                // $vendors = DB::table('vendors as v')->leftJoin('agent_vendors as a', function ($join) use ($request) {
                //     $join->on('v.id', '=', 'a.vendor_id')
                //         ->where('a.agent_id', $request->ID);
                // })
                //     ->where('v.status', '>', 0)
                //     ->where('a.status', 1)
                //     ->select('v.id')
                //     ->get();
                // foreach ($vendors as $row) {
                //     $data['vendor_id'] = $row->id;
                //     AgentVendor::create($data);
                // }
                DB::commit();
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'agent'
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
     * agent/edit
     * -編輯營運商(只能管理自己的下線)
     *
     * @bodyParam id string required 營運商系統編號 Example: 1
     * @bodyParam email email 電子信箱 Example: user@example.invalid
     * @bodyParam nickname string 暱稱 Example: xxx
     * @bodyParam password string  營運商密碼
     * @bodyParam password_confirmation string  如需修改密碼，需傳營運商確認密碼
     * @bodyParam mobile string 聯絡電話 Example: 0912345678
     * @bodyParam percentage integer 分成百分比 Example: 5
     * @bodyParam timezone_id integer 時區系統編號 Example: 1
     * @bodyParam country_id string 國家 Example: 台灣
     * @bodyParam zip_code string 郵遞區號 Example: 100
     * @bodyParam district string 區域 Example: 中正區
     * @bodyParam city string 城市 Example: 台北市
     * @bodyParam address string 地址 Example: 忠孝西路1號1樓
     * @bodyParam email_validate boolean required 是否需要電子信箱認證0:不必需1:必需 Example: 1
     * @bodyParam mobile_validate boolean required 是否需要手機認證0:不必需1:必需 Example: 1
     * @bodyParam frontend_register boolean required 是否開放會員註冊0:不開放,1:開放 Example: 1
     * @bodyParam status boolean required 狀態0:停用,1:啟用 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new Agent();
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
            
            // 是否為下線
            if ($this->isChild($request->HOST, $request->id) || $request->HOST == $request->id || $request->id == '') {
                if ($row = $model->find($request->id)) {
                    $data = array();
                    $data['email'] = $request->email ? $request->email : $row->email;
                    if ($request->password != '') {
                        $data['password'] = Hash::make($request->password);
                    }
                    $data['nickname'] = $request->nickname ? $request->nickname : $row->nickname;
                    $data['mobile'] = $request->mobile ? $request->mobile : $row->mobile;
                    if ($request->ID == $row['parent']) {
                        $data['percentage'] = $request->percentage ? $request->percentage : $row->percentage;
                    }
                    $data['timezone_id'] = $request->timezone_id ? $request->timezone_id : $row->timezone_id;
                    $data['country_id'] = $request->country_id ? $request->country_id : $row->country_id;
                    $data['zip_code'] = $request->zip_code ? $request->zip_code : $row->zip_code;
                    $data['district'] = $request->district ? $request->district : $row->district;
                    $data['city'] = $request->city ? $request->city : $row->city;
                    $data['address'] = $request->address ? $request->address : $row->address;
                    $data['email_validate'] = isset($request->email_validate) ? $request->email_validate : $row->email_validate;
                    $data['mobile_validate'] = isset($request->mobile_validate) ? $request->mobile_validate : $row->mobile_validate;
                    $data['frontend_register'] = isset($request->frontend_register) ? $request->frontend_register : $row->frontend_register;
                    $data['agent_mode'] = isset($request->agent_mode) ? $request->agent_mode : $row->agent_mode;
                    $data['status'] = isset($request->status) ? $request->status : $row->status;
                    $data['cs_sync_flag'] = $row->has_chat;
                    $data['admin'] = $request->ID;
                    if ($model->find($request->id)->update($data)) {
                        // //更新hierarchy(更新自己及下線的上線) -- 沒有修改代理的功能，所以暫時不需要更新
                        // $agents = Agent::where('hierarchy', 'like', '%,' . $request->id . ',%')->get();
                        // foreach ($agents as $row) {
                        //     $hierarchy = $this->listParent($row->id, true);
                        //     array_unshift($hierarchy, -1);
                        //     $hierarchy[] = -2;
                        //     Agent::where('id', $row->id)->update(['hierarchy' => json_encode($hierarchy)]);
                        // }
                        //有調整開關設定時，下線無條件繼承
                        $setting = [
                            'frontend_register' => $data['frontend_register'],
                            'email_validate' => $data['email_validate'],
                            'mobile_validate' => $data['mobile_validate'],
                        ];
                        Agent::where('hierarchy', 'like', '%,' . $request->id . ',%')->update($setting);

                        $key = array(
                            'agent'
                        );
                        $redis = new REDIS();
                        $redis->delete($key);
                        
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
                        'code' => 1005,
                        'message' => __('error_message.1005')
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003')
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
     * agent/changePasswordBySelf
     * -變更自己的密碼
     *
     * @bodyParam password string required 舊密碼 Example: aaa123456
     * @bodyParam new_password string required 新密碼 Example: abc123456
     * @bodyParam new_password_confirmation string required 確認新密碼 Example: abc123456
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function changePasswordBySelf(Request $request)
    {
        try {
            $model = new Agent();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_password_self, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            if ($row = $model->find($request->ID)) {
                // 驗證舊密碼是否正確
                if (Hash::check($request->password, $row->password)) {
                    $data = array();
                    $data['password'] = Hash::make($request->new_password);
                    $data['cs_sync_flag'] = $row->has_chat;
                    $data['admin'] = $request->ID;
                    if ($model->find($request->ID)->update($data)) {
                        $code = 1000;
                        $message = __('error_message.1000');
                    } else {
                        $code = 2001;
                        $message = __('error_message.2001');
                    }
                } else {
                    $code = 1018;
                    $message = __('error_message.1018');
                }
            } else {
                $code = 1005;
                $message = __('error_message.1005');
            }
            return response()->json([
                'code' => $code,
                'message' => $message
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
     * agent/changePassword
     * -修改營運商密碼(只能編輯自己的下線)
     *
     * @bodyParam id string required 營運商系統編號 Example: 1
     * @bodyParam new_password string required 新密碼 Example: abc123456
     * @bodyParam new_password_confirmation string required 確認新密碼 Example: abc123456
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function changePassword(Request $request)
    {
        try {
            $model = new Agent();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_password, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 是否為下線
            if ($this->isChild($request->HOST, $request->id) || $request->HOST == $request->id || $request->id == '') {
                // 先確認帳號是否存在
                if ($row = $model->find($request->id)) {
                    $data = array();
                    $data['password'] = Hash::make($request->new_password);
                    $data['cs_sync_flag'] = $row->has_chat;
                    $data['admin'] = $request->ID;
                    if ($model->find($request->id)->update($data)) {
                        $code = 1000;
                        $message = __('error_message.1000');
                    } else {
                        $code = 2001;
                        $message = __('error_message.2001');
                    }
                } else {
                    $code = 1005;
                    $message = __('error_message.1005');
                }
            } else {
                $code = 9003;
                $message = __('error_message.9003');
            }
            return response()->json([
                'code' => $code,
                'message' => $message
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
     * agent/confirmPassword
     * -確認自己的密碼
     *
     * @bodyParam password string required 原始密碼 Example: abc123456
     * 
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function confirmPassword(Request $request)
    {
        try {
            $model = new Agent();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_confirm_password, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 先確認帳號是否存在
            $data = $model->find($request->ID);
            if (is_object($data)) {
                //確認密碼
                if (Hash::check($request->password, $data->password)) {
                    //密碼正確
                    $code = 1000;
                    $message = __('error_message.1000');
                } else {
                    //密碼錯誤
                    $code = 1018;
                    $message = __('error_message.1018');
                }
            } else {
                $code = 1005;
                $message = __('error_message.1005');
            }
            return response()->json([
                'code' => $code,
                'message' => $message
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
     * agent/delete
     * -凍結營運商
     *
     * @bodyParam id integer required 營運商系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function delete(Request $request)
    {
        try {
            $model = new Agent();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_delete, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 是否為下線
            if ($this->isChild($request->HOST, $request->id) || $request->HOST == $request->id || $request->id == '') {
                // 先確認帳號是否存在
                if ($model->find($request->id)) {
                    // 更新帳號狀態
                    if ($model->find($request->id)->update(array(
                        'status' => 0,
                        'admin' => $request->ID
                    ))) {
                        $key = array(
                            'agent'
                        );
                        $redis = new REDIS();
                        $redis->delete($key);
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
                        'code' => 1005,
                        'message' => __('error_message.1005')
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003')
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

    private function getShareCode()
    {
        $pool = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        $pool2 = '23456789';

        $code = substr(str_shuffle(str_repeat($pool, 3)), 0, 3) . substr(str_shuffle(str_repeat($pool2, 4)), 0, 4);
        $is_exist = Agent::where('code', $code)->count();
        // 驗證不重覆
        if ($is_exist) {
            return $this->getShareCode();
        } else {
            return $code;
        }
    }

    private function listParent($parentId, $isFirst = false)
    {
        static $hierarchy_array = array();
        //如果是第一次呼叫，清空$result
        if ($isFirst) {
            unset($hierarchy_array);
            static $hierarchy_array = array();
        }
        $agent = Agent::where('id', $parentId)->first();
        $hierarchy_array[] = $agent->id;
        if (isset($agent->parent) && $agent->parent != 0) {
            $this->listParent($agent->parent);
        }
        return $hierarchy_array;
    }

    private function messages()
    {
        return [
            'id.required' => __('error_message.2017'),
            'id.digits_between' => __('error_message.2018'),

            'username.required' => __('error_message.1026'),
            'username.alpha_num' => __('error_message.1025'),
            'username.between' => __('error_message.1024'),
            'username.unique' => __('error_message.1002'),

            'nickname.between' => __('error_message.2024'),

            'email.required' => __('error_message.1013'),
            'email.email' => __('error_message.1014'),
            'email.between' => __('error_message.1015'),

            'password.required' => __('error_message.1016'),
            'password.between' => __('error_message.1017'),
            'password.confirmed' => __('error_message.2025'),

            'new_password.required' => __('error_message.1019'),
            'new_password.between' => __('error_message.1020'),
            'new_password.confirmed' => __('error_message.1021'),

            'confirm_password.different' => __('error_message.1021'),

            'mobile.digits_between' => __('error_message.1036'),

            'timezone_id.digits_between' => __('error_message.1054'),

            'country_id.digits_between' => __('error_message.2022'),

            'zip_code.digits_between' => __('error_message.1038'),

            'district.between' => __('error_message.1039'),

            'city.between' => __('error_message.1040'),

            'address.between' => __('error_message.1041'),

            'email_validate.boolean' => __('error_message.1091'),
            'mobile_validate.boolean' => __('error_message.1092'),
            'frontend_register.boolean' => __('error_message.1065'),

            'status.boolean' => __('error_message.2009'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}