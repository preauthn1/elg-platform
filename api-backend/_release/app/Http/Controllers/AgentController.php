<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Agent;
use App\AgentPermission;
use App\AgentMenu;
use App\Member;
use Hash;
use Validator;
use DB;
use App\REDIS;

class AgentController extends Controller
{

    /**
     * agent/list
     * -營運商列表
     *
     * @bodyParam parent integer 上層編號 Example: 0
     * @bodyParam status boolean 營運商狀態 Example: 1 or 0
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "營運商系統編號,ex:1",
     *            "parent": "上層系統編號(0的話是第一層),ex:0",
     *            "username": "營運商帳號,ex:dmin",
     *            "nickname": "營運商暱稱,ex:XXX",
     *            "email": "營運商email,ex:user@example.invalid",
     *            "mobile": "手機號碼,ex:0912345678",
     *            "percentage": "分成百分比,ex:5",
     *            "timezone_id": "時區系統編號,ex:1",
     *            "country_id": "國家系統編號,ex:1",
     *            "zip_code": "郵遞區號,ex:853",
     *            "district": "地區,ex:中正區",
     *            "city": "城市,ex:台北市",
     *            "address": "地址,ex:忠孝西路1號1樓",
     *            "frontend_register":"前端是否開放註冊0:不開放1:開放,ex:1",
     *            "has_chat":"是否開放聊天室, 0:不開放1:開放,ex:1",
     *            "has_pc":"是否有PC版，0:沒有1:有,ex:1",
     *            "has_mobile":"是否有手機版，0:沒有1:有,ex:1",
     *            "has_online_payment":"是否有線上儲值，0:沒有1:有,ex:1",
     *            "has_app":"是否有APP下載，0:沒有1:有,ex:1",
     *            "transaction_all":"進入遊戲時是否將所有錢轉入，0:否1:是,ex:1",
     *            "agent_backend_transaction":"是否可以在代理後台開洗分，0:否1:是,ex:1",
     *            "status": "狀態-0凍結1正常,ex:1",
     *            "last_login": "最後登入時間,ex:2019-04-01 09:36:46"
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

            $key = array(
                'agent',
                'list',
                $request->parent,
                $request->status,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'agent',
                'list',
                $request->parent,
                $request->status,
                'total'
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                if (isset($request->parent)) {
                    $model = $model->where('parent', $request->parent);
                }
                if (isset($request->status)) {
                    $model = $model->where('status', $request->status);
                }
                $total = $model->count();
                $data = $model->select('id', 'parent', 'username', 'nickname', 'email', 'mobile', 'percentage', 'timezone_id', 'country_id', 'zip_code', 'district', 'city', 'address', 'frontend_register', 'has_chat', 'has_pc', 'has_mobile', 'has_online_payment', 'has_app', 'transaction_all', 'agent_backend_transaction', 'status', 'last_login')
                    ->orderBy('id', 'desc')
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
     * agent/view
     * -營運商詳細資料
     *
     * @bodyParam id integer required 營運商系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data":{
     *        "parent": "上層系統編號(0的話是第一層),ex:0",
     *        "username": "營運商帳號,ex:Admin",
     *        "nickname": "營運商暱稱,ex:XXX",
     *        "email": "營運商email,ex:user@example.invalid",
     *        "mobile": "手機號碼,ex:0912345678",
     *        "percentage": "分成百分比,ex:5",
     *        "timezone_id": "時區系統編號,ex:1",
     *        "country_id": "國家系統編號,ex:1",
     *        "zip_code": "郵遞區號,ex:853",
     *        "district": "地區,ex:中正區",
     *        "city": "城市,ex:台北市",
     *        "address": "地址,ex:忠孝西路1號1樓",
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
                $data = $model->select('id', 'parent', 'code', 'username', 'nickname', 'email', 'mobile', 'percentage', 'timezone_id', 'country_id', 'zip_code', 'district', 'city', 'address', 'frontend_register', 'has_chat', 'has_pc', 'has_mobile', 'has_online_payment', 'has_app', 'transaction_all', 'agent_backend_transaction', 'status', 'last_login')->find($request->id);
                if (!$data) {
                    return response()->json([
                        'code' => 1005,
                        'message' => __('error_message.1005')
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
     * -新增營運商
     *
     * @bodyParam username unique required 帳號 Example: agent_username
     * @bodyParam nickname string 暱稱 Example: XXX
     * @bodyParam email email required 電子信箱 Example: user@example.invalid
     * @bodyParam password string required 營運商密碼
     * @bodyParam password_confirmation string required 營運商確認密碼
     * @bodyParam mobile string 聯絡電話 Example: 0912345678
     * @bodyParam percentage integer 分成百分比 Example: 5
     * @bodyParam timezone_id integer 時區系統編號 Example: 1
     * @bodyParam country_id integer 國家 Example: 台灣
     * @bodyParam zip_code integer 郵遞區號 Example: 100
     * @bodyParam district string 區域 Example: 中正區
     * @bodyParam city string 城市 Example: 台北市
     * @bodyParam address string 地址 Example: 忠孝西路1號1樓
     * @bodyParam frontend_register boolean 前端是否顯示會員註冊0:不開放1:開放 Example: 1
     * @bodyParam has_chat boolean 是否開放聊天室,0:不開放1:開放 Example: 1
     * @bodyParam has_pc boolean 是否有PC版，0:沒有1:有 Example: 1
     * @bodyParam has_mobile boolean 是否有手機版，0:沒有1:有 Example: 1
     * @bodyParam has_online_payment boolean 是否有線上儲值，0:沒有1:有 Example: 1
     * @bodyParam has_app boolean 是否有APP下載，0:沒有1:有 Example: 1
     * @bodyParam transaction_all boolean 進入遊戲時是否將所有錢轉入，0:否1:是 Example: 1
     * @bodyParam agent_backend_transaction boolean 是否可以在代理後台開洗分，0:否1:是 Example: 1
     * @bodyParam status boolean 狀態-0凍結1正常 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "營運商編號,ex:1"
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
            // 寫入數據庫
            $data = array();
            $data['parent'] = 1; // 在ADMIN建立的為最上層帳號
            $data['code'] = Str::random(6);
            $data['username'] = $request->username;
            $data['nickname'] = $request->nickname;
            $data['email'] = $request->email;
            $data['password'] = Hash::make($request->password);
            $data['mobile'] = $request->mobile;
            if ($request->ID == 1) {
                $data['percentage'] = $request->percentage;
            }
            $data['timezone_id'] = $request->timezone_id;
            $data['country_id'] = $request->country_id;
            $data['zip_code'] = $request->zip_code;
            $data['district'] = $request->district;
            $data['city'] = $request->city;
            $data['address'] = $request->address;
            $data['frontend_register'] = $request->frontend_register;
            $data['has_chat'] = $request->has_chat;
            $data['has_pc'] = $request->has_pc;
            $data['has_mobile'] = $request->has_mobile;
            $data['has_online_payment'] = $request->has_online_payment;
            $data['has_app'] = $request->has_app;
            $data['transaction_all'] = $request->transaction_all;
            $data['agent_backend_transaction'] = $request->agent_backend_transaction;
            $data['status'] = isset($request->status) ? $request->status : 0;
            $data['cs_sync_flag'] = $request->has_chat; //有聊天室才需要同步到客服系統
            $data['admin'] = $request->ID;

            if ($result = $model->create($data)) {
                //更新hierarchy
                $hierarchy = $this->listParent($result->id, true);
                array_unshift($hierarchy, -1);
                $hierarchy[] = -2;
                Agent::where('id', $result->id)->update(['hierarchy' => json_encode($hierarchy)]);

                //寫入權限
                $data = array();
                $data['agent_id'] = $result->id;
                $menus = AgentMenu::get();
                foreach ($menus as $row) {
                    $data['agent_menu_id'] = $row['id'];
                    AgentPermission::create($data);
                }
                DB::commit();
                // 刪除Redis列表
                $key = array(
                    'agent',
                    'list'
                );
                $redis = new REDIS();
                $redis->delete($key);
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
     * agent/edit
     * -編輯營運商
     *
     * @bodyParam id string required 營運商系統編號 Example: 1
     * @bodyParam nickname string 暱稱 Example: XXX
     * @bodyParam email email 電子信箱 Example: user@example.invalid
     * @bodyParam password string required 營運商密碼
     * @bodyParam password_confirmation string required 如需修改密碼，需傳營運商確認密碼
     * @bodyParam mobile string 聯絡電話 Example: 0912345678
     * @bodyParam percentage integer 分成百分比 Example: 5
     * @bodyParam timezone_id string 時區系統編號 Example: 1
     * @bodyParam country_id string 國家 Example: 台灣
     * @bodyParam zip_code string 郵遞區號 Example: 100
     * @bodyParam district string 區域 Example: 中正區
     * @bodyParam city string 城市 Example: 台北市
     * @bodyParam address string 地址 Example: 忠孝西路1號1樓
     * @bodyParam frontend_register boolean 前端是否顯示會員註冊0:不開放1:開放 Example: 1
     * @bodyParam has_chat boolean 是否開放聊天室,0:不開放1:開放 Example: 1
     * @bodyParam has_pc boolean 是否有PC版，0:沒有1:有 Example: 1
     * @bodyParam has_mobile boolean 是否有手機版，0:沒有1:有 Example: 1
     * @bodyParam has_online_payment boolean 是否有線上儲值，0:沒有1:有 Example: 1
     * @bodyParam has_app boolean 是否有APP下載，0:沒有1:有 Example: 1
     * @bodyParam transaction_all boolean 進入遊戲時是否將所有錢轉入，0:否1:是 Example: 1
     * @bodyParam agent_backend_transaction boolean 是否可以在代理後台開洗分，0:否1:是 Example: 1
     * @bodyParam status boolean 狀態-0凍結1正常 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
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
            if ($row = $model->find($request->id)) {
                $data = array();
                $data['nickname'] = $request->nickname ? $request->nickname : $row->nickname;
                $data['email'] = $request->email ? $request->email : $row->email;
                if ($request->password != '') {
                    $data['password'] = Hash::make($request->password);
                }
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
                $data['frontend_register'] = isset($request->frontend_register) ? $request->frontend_register : $row->frontend_register;
                $data['has_chat'] = isset($request->has_chat) ? $request->has_chat : $row->has_chat;
                $data['has_pc'] = isset($request->has_pc) ? $request->has_pc : $row->has_pc;
                $data['has_mobile'] = isset($request->has_mobile) ? $request->has_mobile : $row->has_mobile;
                $data['has_online_payment'] = isset($request->has_online_payment) ? $request->has_online_payment : $row->has_online_payment;
                $data['has_app'] = isset($request->has_app) ? $request->has_app : $row->has_app;
                $data['transaction_all'] = isset($request->transaction_all) ? $request->transaction_all : $row->transaction_all;
                $data['agent_backend_transaction'] = isset($request->agent_backend_transaction) ? $request->agent_backend_transaction : $row->agent_backend_transaction;
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['cs_sync_flag'] = isset($request->has_chat) ? $request->has_chat : $row->has_chat; //有聊天室才需要同步到客服系統
                $data['admin'] = $request->ID;
                if ($model->find($request->id)->update($data)) {
                    //有調整開關設定時，下線無條件繼承
                    $setting = [
                        'frontend_register' => $data['frontend_register'],
                        'has_chat' => $data['has_chat'],
                        'has_pc' => $data['has_pc'],
                        'has_mobile' => $data['has_mobile'],
                        'has_online_payment' => $data['has_online_payment'],
                        'has_app' => $data['has_app'],
                        'transaction_all' => $data['transaction_all'],
                        'agent_backend_transaction' => $data['agent_backend_transaction'],
                        'status' => $data['status'],
                        'cs_sync_flag' => $data['cs_sync_flag'],
                    ];
                    Agent::where('hierarchy', 'like', '%,' . $request->id . ',%')->update($setting);

                    //如果是多錢包轉單錢包，玩家需要加個FLAG轉回各遊戲商的餘額
                    if ($row->transaction_all == 0 && isset($request->transaction_all) && $request->transaction_all == 1) {
                        $agents = Agent::where('hierarchy', 'like', '%,' . $request->id . ',%')->pluck('id');
                        Member::whereIn('parent', $agents)->update(['change_to_single_wallet' => 1]);
                    }
                    //刪除agent、member、tempalte(開關的關係)的redis
                    $redis = new REDIS();
                    $redis->delete(['agent']);
                    $redis->delete(['domain']);
                    $redis->delete(['member']);
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
            // 先確認帳號是否存在
            if ($model->find($request->id)) {
                // 更新帳號狀態
                if ($model->find($request->id)->update(array(
                    'status' => 0,
                    'admin' => $request->ID
                ))) {
                    $key = array(
                        'agent',
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

            'parent.numeric' => __('error_message.1055'),

            'username.required' => __('error_message.1026'),
            'username.r_alpha_num' => __('error_message.1025'),
            'username.between' => __('error_message.1024'),
            'username.unique' => __('error_message.1002'),

            'nickname.between' => __('error_message.2024'),

            'email.required' => __('error_message.1013'),
            'email.email' => __('error_message.1014'),
            'email.between' => __('error_message.1015'),

            'password.required' => __('error_message.1016'),
            'password.between' => __('error_message.1017'),
            'password.confirmed' => __('error_message.2025'),

            'mobile.between' => __('error_message.1036'),

            'percentage.digits_between' => __('error_message.1089'),

            'timezone_id.digits_between' => __('error_message.1054'),

            'country_id.digits_between' => __('error_message.2022'),

            'zip_code.digits_between' => __('error_message.1038'),

            'district.between' => __('error_message.1039'),

            'city.between' => __('error_message.1040'),

            'address.between' => __('error_message.1041'),

            'frontend_register.boolean' => __('error_message.1065'),

            'status.boolean' => __('error_message.2009'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}