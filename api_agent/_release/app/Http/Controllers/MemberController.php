<?php

namespace App\Http\Controllers;

use App\Helpers\SQLHelper;
use Illuminate\Http\Request;
use App\Enums\AgentCustomizedSettingKeyEnum;
use App\Models\Member;
use App\Models\Agent;
use App\Models\MemberValidation;
use App\Models\FlowLimit;
use App\Helpers\Common;
use App\Repositories\AgentOperationLogRepository;
use App\Repositories\AgentPersonalSettingRepository;
use App\REDIS;
use Hash;
use DB;
use Validator;
use log;

class MemberController extends Controller
{

    /**
     * member/list
     * -會員列表(可以查看自己及下線的會員)
     *
     * @bodyParam agent_username string 代理名稱(如果為空就查所有下線) Example: xxx
     * @bodyParam agent_id integer 代理系統編號(如果為空就查所有下線) Example: 1
     * @bodyParam username string 會員帳號 Example: xxx
     * @bodyParam email string 會員電子信箱 Example: xxx
     * @bodyParam status boolean 會員狀態 Example: 1
     * @bodyParam certification boolean 認證狀態 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *        "agent_username": "玩家所屬的代理帳號",
     *        "id": 1,
     *        "parent": 0,
     *        "username": "member",
     *        "balance": 100,
     *        "email": "user@example.invalid",
     *        "nickname": "xxx",
     *        "first_name": "HELLO",
     *        "last_name": "WORLD",
     *        "birthday": "1990-01-01",
     *        "image": "",
     *        "mobile": "0912345678",
     *        "country_id": 1,
     *        "zip_code": "230",
     *        "district": "中正區",
     *        "city": "台北市",
     *        "address": "忠孝西路一段",
     *        "status": 1,
     *        "is_validate": 1,
     *        "mobile_validate": 1,
     *        "email_validate": 1,
     *        "last_login": "2019-03-26 03:44:34"
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
            $model = new Member();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $model = DB::table('members as m')
                ->join('agents as a', function ($join) use ($request) {
                    // 可以查詢自己下線的所有會員
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                    if ($request->agent_id != '') {
                        $join->where('a.id',  $request->agent_id);
                    }
                    if ($request->agent_username != '') {
                        $join->where('a.username',  $request->agent_username);
                    }
                });

            if ($request->username != '') {
                $model = $model->where('m.username', 'like', '%' . $request->username . '%');
            }
            if ($request->nickname != '') {
                $model = $model->where('m.nickname', 'like', '%' . $request->nickname . '%');
            }
            if ($request->email != '') {
                $model = $model->where('m.email', 'like', '%' . $request->email . '%');
            }
            if (isset($request->status)) {
                $model = $model->where('m.status', $request->status);
            }
            if (isset($request->certification)) {
                $model = $model->where('m.is_validate', $request->certification);
            }
            if (isset($request->mobile)) {
                $model = $model->where('m.mobile', 'like', '%' . $request->mobile . '%');
            }
            if (isset($request->mobile_authentication)) {
                $model = $model->where('m.mobile_validate', $request->mobile_authentication);
            }

            $total = $model->count();
            $data = $model
                ->orderBy('m.id', 'desc')
                ->select(
                    'a.username as agent_username',
                    'm.id',
                    'm.parent',
                    'm.username',
                    'm.balance',
                    'm.email',
                    'm.nickname',
                    'm.first_name',
                    'm.last_name',
                    'm.birthday',
                    DB::raw("IFNULL(m.image, '') as image"),
                    'm.mobile',
                    'm.country_id',
                    'm.zip_code',
                    'm.district',
                    'm.city',
                    'm.address',
                    'm.status',
                    'm.is_validate',
                    'm.mobile_validate',
                    'm.email_validate',
                    'a.mobile_validate as agent_mobile_validate',
                    'a.email_validate as agent_email_validate',
                    'm.last_login',
                    DB::raw('DATE_ADD(m.created_at ,INTERVAL 8 HOUR) as created_at')
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
     * member/view
     * -會員詳細資料(可以查看自己及下線的會員)
     *
     * @bodyParam id integer required 會員系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *        "id": 1,
     *        "parent": 0,
     *        "username": "member",
     *        "balance": "100",
     *        "email": "user@example.invalid",
     *        "nickname": "xxx",
     *        "first_name": "HELLO",
     *        "last_name": "WORLD",
     *        "birthday": "1990-01-01",
     *        "mobile": "0912345678",
     *        "country_id": 1,
     *        "zip_code": "230",
     *        "district": "中正區",
     *        "city": "台北市",
     *        "address": "忠孝西路一段",

     *        "status": 1,
     *        "is_validate": 1,
     *        "mobile_validate": 1,
     *        "email_validate": 1,
     *        "last_login": "2019-03-26 03:44:34"
     *    }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Member();
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
                'member',
                'show',
                $request->id
            );
            $redis = new REDIS();

            if (!$redis->exists($key)) {
                // 可以查詢自己下線的所有會員
                $data = DB::table('members as m')
                    ->join('agents as a', function ($join) use ($request) {
                        $join->on('a.id', 'm.parent')
                            ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                    })
                    ->leftJoin('agent_customized_settings as acs', function ($join) use ($request) {
                        $join->on('acs.agent_group_id', 'a.customized_setting_group_id')
                            ->where('acs.setting_key', AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_BUTTON_VISIBLE);
                    })
                    ->where('m.id', $request->id)
                    ->select(
                        'a.username as agent_username',
                        'm.id',
                        'm.parent',
                        'm.username',
                        'm.balance',
                        'm.email',
                        'm.nickname',
                        'm.first_name',
                        'm.last_name',
                        'm.birthday',
                        'm.mobile',
                        'm.timezone_id',
                        'm.country_id',
                        'm.zip_code',
                        'm.district',
                        'm.city',
                        'm.address',
                        'm.status',
                        'm.is_validate',
                        'm.mobile_validate',
                        'm.email_validate',
                        'm.last_login',
                        'm.payment_password',
                        DB::raw('IFNULL(acs.value, 0) as payment_password_visible')
                    )->first();

                if (is_object($data)) {
                    $agent = Agent::find($data->parent);
                    if (strpos($agent->hierarchy, ',' . $request->HOST . ',') === false) {
                        return response()->json([
                            'code' => 1005,
                            'message' => __('error_message.1005')
                        ]);
                    } else {
                        $redis->set($key, $data);
                    }
                } else {
                    $redis->set($key, $data);
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
     * member/add
     * -新增會員(只能新增自己的會員)
     *
     * @bodyParam parent integer required 代理系統編號帳號 Example: 1
     * @bodyParam username unique required 會員帳號，限英數字串，4-10個字 Example: username
     * @bodyParam password string required 會員密碼，6-12個字
     * @bodyParam password_confirmation string required 確認會員密碼
     * @bodyParam payment_password string  會員支付密碼
     * @bodyParam payment_password_confirmation string  如需修改支付密碼，需傳會員支付確認密碼
     * @bodyParam email email 會員電子信箱，最多60個字 Example: user@example.invalid
     * @bodyParam nickname string 暱稱，最多60個字 Example: xxx
     * @bodyParam first_name string  英文名，最多35個字 Example: CHIA HUA
     * @bodyParam last_name string  英文姓，最多10個字 Example: CHAN
     * @bodyParam birthday string 出生年月日，需大於18歲 Example: 1990-01-01
     * @bodyParam mobile string 聯絡電話 Example: 0912345678
     * @bodyParam timezone_id integer 時區系統編號 Example: 1
     * @bodyParam country_id integer 國家 Example: 1
     * @bodyParam zip_code string 郵遞區號 Example: 100
     * @bodyParam district string 區域 Example: 中正區
     * @bodyParam city string 城市 Example: 台北市
     * @bodyParam address string 地址 Example: 忠孝西路1號1樓
     * @bodyParam status boolean 狀態0:停用1:啟用 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "會員系統編號,ex:2"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new Member();
            // 添加年齡需買18歲的驗證
            $rule = $model->rules_create;
            $rule['birthday'] = 'date_format:Y-m-d|before:' . date('Y-m-d', strtotime('-18years +1days'));
            // 驗證POST資料
            $validator = Validator::make($request->all(), $rule, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            //檢查 支付密碼不能跟密碼相同
            if ($request->password == $request->payment_password) {
                return response()->json([
                    'code' => 2041,
                    'message' => __('error_message.2041')
                ]);
            }

            //確認username和nickname是否有禁用字
            $unusable_words = $this->getUnsupportNicknames($request->HOST);
            foreach($unusable_words as $word){
                if(strpos(strtolower($request->username), $word) !== false
                    || strpos(strtolower($request->nickname), $word) !== false){
                    return response()->json([
                        'code' => 1093,
                        'message' => str_replace(':value', $word, __('error_message.1093'))
                    ]);
                }
            }

            if (preg_match("/^agent/", strtolower($request->username))) {
                // 前綴不可為agent
                return response()->json([
                    'code' => 2036,
                    'message' => __('error_message.2036')
                ]);
            } else {
                $row = array();
                $row['parent'] = $request->parent;
                $row['username'] = $request->username;
                $row['email'] = $request->email;
                // 如果沒有傳暱稱，給個預設名稱
                if (isset($request->nickname) && $request->nickname != '') {
                    $row['nickname'] = $request->nickname;
                } else {
                    $row['nickname'] = $this->getNickname();
                }
                $row['password'] = Hash::make($request->password);
                $row['first_name'] = $request->first_name;
                $row['last_name'] = $request->last_name;
                $row['birthday'] = $request->birthday;
                $row['mobile'] = $request->mobile;
                $row['timezone_id'] = $request->timezone_id;
                $row['country_id'] = $request->country_id;
                $row['zip_code'] = $request->zip_code;
                $row['district'] = $request->district;
                $row['city'] = $request->city;
                $row['address'] = $request->address;
                $row['status'] = $request->status ? $request->status : 0;
                $row['admin'] = $request->ID;
                if ($result = $model->create($row)) {
                    //觸發客服API 建立sync資料
                    $this -> trigger_CS_API_SyncData($request->parent , $request->username);

                    DB::update("CALL SP_event_gift_point( 1, " . $result->id . ", " . $request->parent . ")");

                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'member'
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
     * member/edit
     * -編輯會員(只能編輯自己及下線的會員)
     *
     * @bodyParam id string required 會員系統編號,需為自己的會員 Example: 1
     * @bodyParam email email 會員電子信箱，最多60個字 Example: user@example.invalid
     * @bodyParam password string  會員密碼
     * @bodyParam password_confirmation string  如需修改密碼，需傳會員確認密碼
     * @bodyParam payment_password string  會員支付密碼
     * @bodyParam payment_password_confirmation string  如需修改支付密碼，需傳會員支付確認密碼
     * @bodyParam nickname string 暱稱，最多60個字 Example: xxx
     * @bodyParam first_name string  英文名，最多15個字 Example: CHIA HUA
     * @bodyParam last_name string  英文姓，最多10個字 Example: CHAN
     * @bodyParam birthday string 出生年月日，需大於18歲 Example: 1990-01-01
     * @bodyParam mobile string 聯絡電話 Example: 0912345678
     * @bodyParam timezone_id integer 時區系統編號 Example: 1
     * @bodyParam country_id integer 國家 Example: 1
     * @bodyParam zip_code string 郵遞區號 Example: 100
     * @bodyParam district string 區域 Example: 中正區
     * @bodyParam city string 城市 Example: 台北市
     * @bodyParam address string 地址 Example: 忠孝西路1號1樓
     * @bodyParam status boolean 狀態(0:停用1:正常) Example:1
     * @bodyParam is_validate boolean 認證狀態(0:未通過1:已通過) Example:1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            Common::log($request->ID, $request->all(), 'member_update');

            $model = new Member();
            // 添加年齡需買18歲的驗證
            $rule = $model->rules_update;
            $rule['birthday'] = 'date_format:Y-m-d|before:' . date('Y-m-d', strtotime('-18years +1days'));
            // 驗證POST資料
            $validator = Validator::make($request->all(), $rule, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 先確認帳號是否存在
            $id = $request->id;

            //確認nickname是否有禁用字
            $unusable_words =  $this->getUnsupportNicknames($request->HOST);
            foreach($unusable_words as $word){
                if(strpos(strtolower($request->nickname), $word) !== false){
                    return response()->json([
                        'code' => 1093,
                        'message' => str_replace(':value', $word, __('error_message.1093'))
                    ]);
                }
            }
            $data = $model->where('id', $id)
                ->whereIn('parent', $this->listChild($request->HOST))
                ->first();
            if (is_object($data)) {
                //檢查 支付密碼不能跟密碼相同
                if ($request->payment_password != '') {
                    if ($request->password != '') {
                        if ($request->password == $request->payment_password) {
                            return response()->json([
                                'code' => 20411,
                                'message' => __('error_message.2041')
                            ]);
                        }
                    } else if (Hash::check($request->payment_password, $data->password)) {
                        return response()->json([
                            'code' => 20412,
                            'message' => __('error_message.2041')
                        ]);
                    }
                }
                $original_mobile_validate = $data->mobile_validate;

                $row = array();
                if (isset($request->email) && $request->email != '' && $data->email != $request->email) {
                    $row['email'] = $request->email;
                    $row['email_validate'] = 0;
                }
                if ($request->password != '') {
                    $data['password'] = Hash::make($request->password);
                }
                if ($request->payment_password != '') {
                    $data['payment_password'] = Hash::make($request->payment_password);
                }
                if (isset($request->mobile) && $request->mobile != '' && $data->mobile != $request->mobile) {
                    $row['mobile'] = $request->mobile;
                    $row['mobile_validate'] = 0;
                }
                $row['nickname'] = $request->nickname ? $request->nickname : $data->nickname;
                $row['first_name'] = $request->first_name ? $request->first_name : $data->first_name;
                $row['last_name'] = $request->last_name ? $request->last_name : $data->last_name;
                $row['birthday'] = $request->birthday ? $request->birthday : $data->birthday;
                $row['timezone_id'] = $request->timezone_id ? $request->timezone_id : $data->timezone_id;
                $row['country_id'] = $request->country_id ? $request->country_id : $data->country_id;
                $row['zip_code'] = $request->zip_code ? $request->zip_code : $data->zip_code;
                $row['district'] = $request->district ? $request->district : $data->district;
                $row['city'] = $request->city ? $request->city : $data->city;
                $row['address'] = $request->address ? $request->address : $data->address;
                $row['status'] = isset($request->status) ? $request->status : $data->status;
                $row['is_validate'] = isset($request->is_validate) ? $request->is_validate : $data->is_validate;
                $row['mobile_validate'] = isset($request->mobile_validate) ? $request->mobile_validate : $data->mobile_validate;
                $row['admin'] = $request->ID;
                if ($data->update($row)) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'member'
                    ));

                    //寫入操作紀錄         
                    $rep = new AgentOperationLogRepository();
                    $rep->insert($request->ID , $id, 'update_member');
                    if($row['mobile_validate'] != $original_mobile_validate) {
                        $rep->insert($request->ID , $id, 'update_mobile_validate');
                    }


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
                    'code' => 1007,
                    'message' => __('error_message.1007')
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
     * member/changePassword
     * -修改會員密碼(只能編輯自己及下線的會員)
     *
     * @bodyParam id string required 會員系統編號 Example: 1
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
            $model = new Member();
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
            // 先確認帳號是否存在
            $id = $request->id;
            $data = $model->where('id', $id)->first();
            if (is_object($data)) {
                $agent = Agent::find($data->parent);
                if (strpos($agent->hierarchy, ',' . $request->HOST . ',') === false) {
                    $code = 1007;
                    $message = __('error_message.1007');
                } else {
                    $data = array();
                    $data['password'] = Hash::make($request->new_password);
                    $data['admin'] = $request->ID;
                    if ($data->update($data)) {
                        $code = 1000;
                        $message = __('error_message.1000');
                    } else {
                        $code = 2001;
                        $message = __('error_message.2001');
                    }
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
     * member/delete
     * -凍結會員(只能編輯自己及下線的會員)
     *
     * @bodyParam id integer required 會員系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function delete(Request $request)
    {
        try {
            $model = new Member();
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
            $id = $request->id;
            $data = $model->where('id', $id)->whereIn('parent', $this->listChild($request->ID))->First();
            if (is_object($data)) {
                // 更新帳號狀態
                if ($data->update(array(
                    'status' => 0,
                    'admin' => $request->ID
                ))) {
                    $key = array(
                        'member'
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
                    'code' => 1007,
                    'message' => __('error_message.1007')
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
     * member/flowLimit
     * - 稽核流水
     *
     * @bodyParam member_id integer required 會員系統編號 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * 
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *        "type": "limit_bonus",
     *        "bonus": "10.00",
     *        "flow_limit": "5.00",
     *        "valid_bet": "0.00",
     *        "status": "Pass",
     *        "created_at": "2019-03-26 03:44:34",
     *        "updated_at": "2019-03-26 03:44:34"
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
    public function flowLimit(Request $request)
    {
    	try {
        	$request->page = isset($request->page)? $request->page : 1;
        	$request->per_page = isset($request->per_page)? $request->per_page : 20;

        	$events = DB::table('events AS e')
                ->leftJoin('event_names AS en', function ($join) use ($request)  {
                    $join->on('en.event_id', 'e.id')
                        ->where('en.language_id', $request->LANGUAGE);
                })
                ->join('agents AS a', 'a.id', 'e.agent_id')
                ->select(
                    'e.id AS event_id',
                    DB::raw('IFNULL(en.title, e.title) AS title')
                )
                ->get();
        	$ar_event = array();
        	foreach ($events as $event) {
                $ar_event[$event->event_id] = $event->title;
            }

        	$login_events = DB::table('login_events AS le')
                ->leftJoin('login_event_names AS len', function ($join) use ($request) {
                    $join->on('len.login_event_id', 'le.id')
                        ->where('len.language_id', $request->LANGUAGE);
                })
                ->join('agents AS a', 'a.id', 'le.agent_id')
                ->select(
                    'le.id AS event_id',
                    DB::raw('IFNULL(len.title, le.title) AS title')
                )
                ->get();
            $ar_login_event = array();
            foreach ($login_events as $login_event) {
                $ar_login_event[$login_event->event_id] = $login_event->title;
            }

    		$model = DB::table('flow_limits as fl')
    			->join('members as m', function($join) use ($request) {
    				$join->on('m.id', 'fl.member_id')
    					->where('m.id', $request->member_id);
    			})
    			->leftJoin('transaction_logs as t', function($join) {
    				$join->on('t.id', 'fl.reference_id')
    					;//->whereIn('t.type', [3, 4, 6, 10, 17, 19, 24, 25, 26]);
    			})
    			->orderBy('fl.id', 'DESC');
            $total = $model->count();
    		$data = $model
    			->skip(($request->page - 1) * $request->per_page)
    			->take($request->per_page)
    			->select(
    				DB::raw('fl.id as id'),
                    DB::raw('fl.type as type'),
                    DB::raw('fl.event_id as event_id'),
        			DB::raw('convert(t.point, decimal(18, 2)) as bonus'),
        			DB::raw('convert(fl.flow_limit, decimal(18, 2)) as flow_limit'),
        			DB::raw('convert(fl.reach, decimal(18, 2)) as valid_bet'),
            	    DB::raw('fl.status as status'),
            	    DB::raw('fl.remark as remark'),
            	    DB::raw('DATE_ADD(fl.created_at,INTERVAL 8 HOUR) as created_at'),
            	    DB::raw('DATE_ADD(fl.updated_at,INTERVAL 8 HOUR) as updated_at'),
                    DB::raw("'' as statusText"),
                    DB::raw("'' as title")
            	)
                ->get();               

            foreach ($data as $row) {
                $lang_key = 'param.flow_limit_status.'.$row->status;
                $row->statusText = __($lang_key);

                //event
                if (in_array($row->type, array(13, 19, 24, 25, 26, 101))) {
                    $row->title = isset($ar_event[$row->event_id])
                        ? $ar_event[$row->event_id]
                        : '';
                }
                //login event
                if (in_array($row->type, array(15))) {
                    $row->title = isset($ar_login_event[$row->event_id])
                        ? $ar_login_event[$row->event_id]
                        : '';
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
     * member/cancel_flow_limit
     * - 取消稽核流水     
     * 
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *      
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
    public function cancel_flow_limit(Request $request)
    {
    	try {

            $model = new FlowLimit();            
            $validator = Validator::make($request->all(), $model->rules_cancel, $this->messages());

            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            //測試用
            //$request->id = 227;

            $express_query = $model->where("id", $request->id);
            $query = $express_query->first();

            if(!is_object($query))
            {
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044'),
                    'data' => $validator->errors()->all()
                ]);
            }

            $express_query->update(
            [ 
                "remark" => $request->remark,
                "admin" => $request->ID,
                "status" => 9  //刪除稽核
            ]);

    		return response()->json([
    		    'code' => 1000,
    		    'message' => __('error_message.1000'),
    		    'data' => "",
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
     * member/getLoginLog
     * -查詢IP登入紀錄
     *
     * @bodyParam id integer required 會員系統編號 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *        "ip": "0.0.0.0",
     *        "status": "1",
     *        "created_at": "2019-03-26 03:44:34",
     *        "updated_at": "2019-03-26 03:44:34"
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
    public function getLoginLog(Request $request)
    {
        try {
            $model = DB::table('member_login_logs')
                ->where('member_id', $request->id)
                ->orderBy('created_at', 'desc');

            $total = $model->count();
            $data = $model
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    'ip',
                    'status',
                    DB::raw('DATE_ADD(created_at,INTERVAL 8 HOUR) as created_at'),
                    DB::raw('DATE_ADD(updated_at,INTERVAL 8 HOUR) as updated_at')
                    )
                ->get();

            foreach ($data as $row) {
                $username_ip = explode(',', $row->ip)[0];
                $row->ip = $username_ip;
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

    private function getNickname()
    {
        $array = [
            '我來比有錢',
            '博彩首富',
            '富豪之星',
            '比你更有錢',
            '我來贏的'
        ];
        $nickname = $array[rand(0, 4)] . str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
        $is_exist = Member::where('nickname', $nickname)->count();
        // 驗證不重覆
        if ($is_exist) {
            return $this->getNickname();
        } else {
            return $nickname;
        }
    }

    private function messages()
    {
        return [
            'id.required' => __('error_message.1066'),
            'id.digits_between' => __('error_message.1067'),

            'username.required' => __('error_message.1026'),
            'username.r_username' => __('error_message.1025'),
            'username.between' => __('error_message.1024'),
            'username.unique' => __('error_message.1002'),

            'password.required' => __('error_message.1016'),
            'password.between' => __('error_message.1017'),
            'password.confirmed' => __('error_message.2025'),

            'new_password.required' => __('error_message.1019'),
            'new_password.between' => __('error_message.1020'),
            'new_password.confirmed' => __('error_message.1021'),

            'payment_password.between' => __('error_message.1097'),
            'payment_password.confirmed' => __('error_message.2040'),

            'email.email' => __('error_message.1014'),
            'email.between' => __('error_message.1015'),

            'nickname.between' => __('error_message.2024'),

            'first_name.r_alpha_dash' => __('error_message.1031'),
            'first_name.between' => __('error_message.1032'),

            //'last_name.r_alpha_dash' => __('error_message.1033'),
            'last_name.between' => __('error_message.1034'),

            'birthday.date_format' => __('error_message.1070'),
            'birthday.before' => __('error_message.1029'),

            'mobile.digits_between' => __('error_message.1036'),

            'timezone_id.digits_between' => __('error_message.1054'),

            'country_id.digits_between' => __('error_message.2022'),

            'zip_code.digits_between' => __('error_message.1038'),

            'district.between' => __('error_message.1039'),

            'city.between' => __('error_message.1040'),

            'address.between' => __('error_message.1041'),

            'status.boolean' => __('error_message.2009'),

            'is_validate.boolean' => __('error_message.2009'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }

    private function getUnsupportNicknames($agentID)
    {
        $rep = new AgentPersonalSettingRepository();

        //先查看db (代理個人配置) 是否有設
        $querySettings = $rep->getFirstSettingByAgentID($agentID , 92001);

        if(!is_object($querySettings) || is_null($querySettings))
        {
            return ['customer', 'service', 'vworld', 'agent', 'a g e n t', 'v w o r l d', 's e r v i c e' , '代理' , '客服' , '代理' , '客服'];
        }

        $settingValues = explode("," ,  $querySettings->value);
        $invalid_nicknames = $settingValues; 
        $invalid_nicknames = array_diff($invalid_nicknames, [""]);

        return $invalid_nicknames;
    }


    private function trigger_CS_API_SyncData($agent_id , $username)
    {
        try {
            $queryAgent = Agent::where("id" , $agent_id)->first();
            $data = [
                'API_USER' => $queryAgent->CS_API_USER,
                'API_KEY' => $queryAgent->CS_API_KEY,
                'THIRD_PARTY_ID' => $username,
            ];
            $result_json = Common::postToSocket("sync/members" , $data);            
            return;
        } catch (Exception $e) 
        {
            $msg = "";
        }  
    }


}