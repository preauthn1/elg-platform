<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Agent;
use App\Models\AgentPermission;
use App\Models\AgentMenu;
use App\Models\AgentVendor;
use App\Models\Vendor;
use App\Models\AgentDomain;
use App\Models\AgentOperateLogs;
use App\Helpers\SQLHelper;
use App\Helpers\Common;
use App\REDIS;
use Hash;
use Validator;
use DB;
use App\Repositories\AgentRepository;
use App\Services\CSDataSyncService;

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
     * @bodyParam fuzzy_query boolean  模糊查詢(0:不啟用,1:啟用) Example: 1 or 0
     * @bodyParam account_type string  帳號類型(all:全部, agent:代理 sub:子帳號) Example: 'all'
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
     *            "has_apk":"是否有APP下載(android)，0:沒有1:有,ex:1",
     *            "has_ipa":"是否有APP下載(ios)，0:沒有1:有,ex:1",
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

            if ($this->isChild($request->HOST, $request->id) || $request->HOST == $request->id || $request->id == '') {

                $model = DB::table('agents as a')
                    ->leftJoin('agents as p', 'a.parent', 'p.id');
                //模糊查詢
                if (Common::IsNotNullOrEmptyString($request->username)) {
                    if ($request->fuzzy_query == 1) {
                        $model = $model->where('a.username', 'like', '%' . $request->username . '%');
                    } else {
                        $model = $model->where('a.username', $request->username);
                    }
                }
                if (Common::IsNotNullOrEmptyString($request->email)) {
                    $model = $model->where('a.email', 'like', '%' . $request->email . '%');
                }
                if (Common::IsNotNullOrEmptyString($request->status)) {
                    $model = $model->where('a.status', $request->status);
                }
                if (Common::IsNotNullOrEmptyString($request->customized_setting_group_id)) {
                    $model = $model->where('a.customized_setting_group_id', $request->customized_setting_group_id);
                }
                if (Common::IsNotNullOrEmptyString($request->cash_flow_group_id)) {
                    $model = $model->where('a.cash_flow_group_id', $request->cash_flow_group_id);
                }
                if (Common::IsNotNullOrEmptyString($request->sms_gateway_group_id)) {
                    $model = $model->where('a.sms_gateway_group_id', $request->sms_gateway_group_id);
                }
                if (Common::IsNotNullOrEmptyString($request->chat_group_id)) {
                    $model = $model->where('a.chat_group_id', $request->chat_group_id);
                }
                // 帳號類型
                if ($request->account_type == 'agent') {
                    $model = $model->where('a.host_id', 0);
                } else if ($request->account_type == 'sub') {
                    $model = $model->where('a.host_id', '>', 0);
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
                        //總公司及第一層會員可以查看他所有的下線帳號
                        $query->orWhere('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                    }

                    //如果是子帳號 不允許查到主帳號 2021-04-07
                    if($request->ID != $request->HOST)
                    {
                        $query->where('a.id', '!=', $request->HOST);
                    }

                    // 是否包含自己
                    // if (isset($request->include_self) && $request->include_self == 1) {
                    //     //包含
                    //     $query->orWhere('a.id', $request->HOST);
                    // } else {
                    //     //不要查詢到自己
                    //     $query->where('a.id', '!=', $request->HOST);
                    // }

                });


                //用最後操作者id查詢username
                $model->leftJoin('agents as an', 'a.admin', 'an.id');
                $model->leftJoin('agents as ac', 'a.created_by', 'ac.id');
                $total = $model->count();


                $data = $model->select(
                    'a.id',
                    'a.host_id',
                    'a.parent',
                    'p.username as parent_username',
                    'a.code', 'a.username',
                    'a.balance',
                    'a.email',
                    'a.nickname',
                    'a.mobile',
                    'a.timezone_id',
                    'a.country_id',
                    'a.zip_code',
                    'a.district',
                    'a.city',
                    'a.address',
                    'a.email_validate',
                    'a.mobile_validate',
                    'a.frontend_register',
                    'a.has_chat', 'a.has_pc',
                    'a.has_mobile',
                    'a.has_online_payment',
                    'a.has_apk',
                    'a.has_ipa',
                    'a.transaction_all',
                    'a.agent_backend_transaction',
                    'a.status',
                    'a.customized_setting_group_id',
                    'a.cash_flow_group_id',
                    'a.sms_gateway_group_id',
                    'a.chat_group_id',
                    'a.last_login',
                    DB::raw('DATE_ADD(a.created_at ,INTERVAL 8 HOUR) as created_at'),
                    'an.username as operator',
                    'ac.username as created_by'
                )
                    ->orderBy('a.id', 'desc')
                    ->skip(($request->page - 1) * $request->per_page)
                    ->take($request->per_page)
                    ->get();
                

            } else {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003')
                ]);
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
     *        "has_apk":"是否有APP下載(android)，0:沒有1:有,ex:1",
     *        "has_ipa":"是否有APP下載(ios)，0:沒有1:有,ex:1",
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
                'agent_v2',
                'show',
                $request->id
            );

            $agentRep = new AgentRepository();

            if(!$agentRep->isViewPermission($request->HOST, $request->id))
            {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003')
                ]);
            }

            $redis = new REDIS();
            if (!$redis->exists($key)) {
                if ($this->isChild($request->HOST, $request->id) || $request->HOST == $request->id || $request->id == '') {
                    $data = DB::table('agents as a')
                        ->leftJoin('agents as p', 'p.id', 'a.parent')
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%')
                        ->where('a.id', $request->id)
                        ->select(
                            'a.id',
                            'a.host_id',
                            'a.parent',
                            'p.username as parent_username',
                            'a.code',
                            'a.username',
                            'a.balance',
                            'a.email',
                            'a.nickname',
                            'a.mobile',
                            'a.timezone_id',
                            'a.country_id',
                            'a.zip_code',
                            'a.district',
                            'a.city',
                            'a.address',
                            'a.email_validate',
                            'a.mobile_validate',
                            'a.frontend_register',
                            DB::raw('p.agent_mode as parent_agent_mode'),
                            'a.agent_mode',
                            'a.has_chat',
                            'a.has_pc',
                            'a.has_mobile',
                            'a.has_online_payment',
                            'a.has_apk',
                            'a.has_ipa',
                            'a.has_2fa',
                            'a.transaction_all',
                            'a.agent_backend_transaction',
                            'a.status',
                            'a.last_login',
                            'a.organization_id',
                            'a.customized_setting_group_id',
                            'a.cash_flow_group_id',
                            'a.sms_gateway_group_id',
                            'a.chat_group_id',
                            'a.vendor_customize_group_id',
                            'a.hierarchy'
                        )
                        ->first();

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
            $data['status'] = 1;
            $data['admin'] = $request->ID;
            $data['created_by'] = $request->ID;

            /* 以下這些設定 預設會與上級一致 */
            $data['has_chat'] = $parent->has_chat;
            $data['has_pc'] = $parent->has_pc;
            $data['has_mobile'] = $parent->has_mobile;
            $data['has_online_payment'] = $parent->has_online_payment;
            $data['has_apk'] = $parent->has_apk;
            $data['has_ipa'] = $parent->has_ipa;
            $data['transaction_all'] = $parent->transaction_all;
            $data['agent_backend_transaction'] = $parent->agent_backend_transaction;
            $data['cs_sync_flag'] =  $parent->has_chat;            
            $data['customized_setting_group_id'] = $parent->customized_setting_group_id;
            $data['cash_flow_group_id'] = $parent->cash_flow_group_id;
            $data['sms_gateway_group_id'] = $parent->sms_gateway_group_id;
            $data['chat_group_id'] = $parent->chat_group_id;
            $data['vendor_customize_group_id'] = $parent->vendor_customize_group_id;
            $data['organization_id'] = $parent->organization_id;
            

            if ($result = Agent::create($data)) {
                //更新hierarchy
                $hierarchy = $this->listParent($result->id, true);
                array_unshift($hierarchy, -1);
                $hierarchy[] = -2;

                $new_hierarchy_path = $parent->hierarchy_path."/".$result->id; 

                //更新舊版/新版的 hierarchy
                Agent::where('id', $result->id)->update(
                    [
                        'hierarchy' => json_encode($hierarchy),
                        'hierarchy_path' => $new_hierarchy_path
                    ]
                );

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

                //同步CS資料庫的資料
                $syncService = new CSDataSyncService();
                $syncService->SyncCustomServiceDbData($result->id);


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
            Common::log_old(1 , $request->all() , 'marshall_debug_3');

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

                    //log會記錄修改前的數值
                    $debugInfoJson = $this->instanceOperateLogDebugInfo($row , $request);

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


                    $data['has_pc'] = Common::IsNotNullOrEmptyString($request->has_pc) ? $request->has_pc : $row->has_pc;
                    $data['has_mobile'] = Common::IsNotNullOrEmptyString($request->has_mobile) ? $request->has_mobile : $row->has_mobile;
                    $data['has_apk'] = Common::IsNotNullOrEmptyString($request->has_apk) ? $request->has_apk : $row->has_apk;
                    $data['has_ipa'] = Common::IsNotNullOrEmptyString($request->has_ipa) ? $request->has_ipa : $row->has_ipa;
                    $data['has_chat'] = Common::IsNotNullOrEmptyString($request->has_chat) ? $request->has_chat : $row->has_chat;
                    $data['has_2fa'] = Common::IsNotNullOrEmptyString($request->has_2fa) ? $request->has_2fa : $row->has_2fa;
                    $data['agent_backend_transaction'] = Common::IsNotNullOrEmptyString($request->agent_backend_transaction) ? $request->agent_backend_transaction : $row->agent_backend_transaction;
                    

                    $data['customized_setting_group_id'] = Common::IsNotNullOrEmptyStringNotZeroValue($request->customized_setting_group_id) ? $request->customized_setting_group_id : $row->customized_setting_group_id;
                    $data['cash_flow_group_id'] = Common::IsNotNullOrEmptyStringNotZeroValue($request->cash_flow_group_id) ? $request->cash_flow_group_id : $row->cash_flow_group_id;
                    $data['sms_gateway_group_id'] = Common::IsNotNullOrEmptyStringNotZeroValue($request->sms_gateway_group_id) ? $request->sms_gateway_group_id : $row->sms_gateway_group_id;
                    $data['chat_group_id'] = Common::IsNotNullOrEmptyStringNotZeroValue($request->chat_group_id) ? $request->chat_group_id : $row->chat_group_id;
                    $data['vendor_customize_group_id'] = Common::IsNotNullOrEmptyStringNotZeroValue($request->vendor_customize_group_id) ? $request->vendor_customize_group_id : $row->vendor_customize_group_id;

                    if ($model->find($request->id)->update($data)) {                       

                        //有調整開關設定時，下線無條件繼承
                        //issues_926 下線都不要繼承 2021-09-03
                        /*$setting = [
                            'frontend_register' => $data['frontend_register'],
                            'email_validate' => $data['email_validate'],
                            'mobile_validate' => $data['mobile_validate'],
                        ];
                        if ($data['agent_mode'] != 1) {
                            $setting = array_merge($setting, ['agent_mode' => 0]);
                        }
                        Agent::where('hierarchy', 'like', '%,' . $request->id . ',%')
                            ->where('id', '!=', $request->id)
                            ->update($setting);*/

                        
                        $redis = new REDIS();

                        //清除各種redisKey
                        $redis->deleteByKeyword("agent");
                        $redis->deleteByKeyword("agent_v2");
                        $redis->deleteByKeyword("domain_v");

                        //寫入操作log
                        $this->WriteAgentOperateLog($request->ID , $request->id , 'update_agent' , $debugInfoJson);

                        //CS db同步資料
                        $syncService = new CSDataSyncService();
                        $syncService->SyncCustomServiceDbData($request->ID);


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
                    $data['last_reset_password'] = gmdate("Y-m-d H:i:s");

                    if ($model->find($request->ID)->update($data)) {

                        //寫log
                        $this->WriteChangePasswordLog($request->ID , $request->ID);

                        //CS db同步資料
                        $syncService = new CSDataSyncService();
                        $syncService->SyncCustomServiceDbData($request->ID);


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
     * agent/filter-by-group
     * - 透過指定的groupId 篩選代理
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function getAgentNameByGroupFilter(Request $request)
    {        
        try {

            $model = new Agent();          

            if(Common::IsNotNullOrEmptyString($request->customized_setting_group_id))
            {
                $model = $model->where("customized_setting_group_id" ,$request->customized_setting_group_id);
            }

            if(Common::IsNotNullOrEmptyString($request->cash_flow_group_id))
            {
                $model = $model->where("cash_flow_group_id" ,$request->cash_flow_group_id);
            }

            if(Common::IsNotNullOrEmptyString($request->sms_gateway_group_id))
            {
                $model = $model->where("sms_gateway_group_id" ,$request->sms_gateway_group_id);
            }

            if(Common::IsNotNullOrEmptyString($request->chat_group_id))
            {
                $model = $model->where("chat_group_id" ,$request->chat_group_id);
            }

            if(Common::IsNotNullOrEmptyString($request->vendor_customize_group_id))
            {
                $model = $model->where("vendor_customize_group_id" ,$request->vendor_customize_group_id);
            }

            $query = $model
                ->select("id" , "username")            
                ->orderBy('username', 'asc')
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' =>  $query 
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

                    $targetAgent = $request->id;
                    if ($model->find($targetAgent)->update($data)) {

                        //寫log
                        $this->WriteChangePasswordLog($request->ID , $targetAgent);
                        
                        //CS db同步資料
                        $syncService = new CSDataSyncService();
                        $syncService->SyncCustomServiceDbData($targetAgent);


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

    /**
     * dropdown/agent
     * -營運商列表(只能查看自己的下線，如果在我們下一層的代理，可以查詢他下面所有的代理)
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "營運商系統編號,ex:1",
     *            "parent": "上層代理的系統編號(0的話是第一層),ex:0",
     *            "username": "營運商帳號,ex:dmin",
     *        }
     *    ]
     * }
     */
    public function dropdownList(Request $request)
    {
        try {
            $model = new Agent();
            $data = $model
                ->where('hierarchy', 'like', '%,' . $request->HOST . ',%')
                ->where('host_id', 0)  //不捞子帐号
                ->select('id', 'parent', 'username')
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
     * dropdown/agent
     * -營運商列表(只能查看自己的下線，如果在我們下一層的代理，可以查詢他下面所有的代理)
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "營運商系統編號,ex:1",
     *            "parent": "上層代理的系統編號(0的話是第一層),ex:0",
     *            "username": "營運商帳號,ex:dmin",
     *        }
     *    ]
     * }
     */
    public function newDropdownList(Request $request)
    {
        try {
            $model = new Agent();
            $data = $model
                ->where('hierarchy', 'like', '%,' . $request->HOST . ',%')
                ->where('username', 'like', '%' . $request->username . '%')
                ->where('host_id', 0)  //不捞子帐号
                ->select('id', 'parent', 'username')
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

    private function WriteChangePasswordLog($operate_agent , $reference_id)
    {         
        $this->WriteAgentOperateLog($operate_agent , $reference_id , 'change_password');
    }


    private function WriteAgentOperateLog($operate_agent , $reference_id , $type , $debug_info = '')
    {   
        $myIP = Common::getIp();

        $insert_log = array();
        $insert_log["agent_id"] = $operate_agent;
        $insert_log["type"] = $type;
        $insert_log["reference_id"] = $reference_id;
        $insert_log["ip"] = $myIP;
        $insert_log["debug_info"] = $debug_info;

        AgentOperateLogs::create( $insert_log);
    }


    private function instanceOperateLogDebugInfo($queryAgent , $request)
    {
        $debugInfo = array();
        $debugInfo["originalDataJson"] = $queryAgent;
        $debugInfo["request_info"] = $request->all();

        //密碼欄位不會記錄
        if(array_key_exists("password" , $debugInfo["request_info"]))
        {
            $debugInfo["request_info"]["password"] = "*mask*";
        }

        if(array_key_exists("password_confirmation" , $debugInfo["request_info"]))
        {
            $debugInfo["request_info"]["password_confirmation"] = "*mask*";
        }

        $debug = $request->password;
        $debugInfoJson = json_encode($debugInfo);
        return $debugInfoJson;
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