<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Member;
use App\Agent;
use App\MemberValidation;
use App\Models\FlowLimit;
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
     * @bodyParam agent_id string 代理系統編號(如果為空就查所有下線) Example: xxx
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
            // $key = array(
            //     'member',
            //     'list',
            //     $request->ID,
            //     $request->agent_id,
            //     $request->username,
            //     $request->email,
            //     $request->status,
            //     $request->page,
            //     $request->per_page
            // );
            // $total_key = array(
            //     'member',
            //     'list',
            //     $request->ID,
            //     $request->agent_id,
            //     $request->username,
            //     $request->email,
            //     $request->status,
            //     'total'
            // );
            // $redis = new REDIS();
            // if (!$redis->exists($key)) {
                if ($request->agent_id != '') {
                    $model = $model->where('members.parent',  $request->agent_id);
                }
                if ($request->username != '') {
                    $model = $model->where('members.username', 'like', '%' . $request->username . '%');
                }
                if ($request->email != '') {
                    $model = $model->where('members.email', 'like', '%' . $request->email . '%');
                }

                if (isset($request->status)) {
                    $model = $model->where('members.status', $request->status);
                }

                if (isset($request->certification)) {
                    $model = $model->where('members.is_validate', $request->certification);
                }
                // 可以查詢自己下線的所有會員
                $model = $model->leftJoin('agents as a', 'a.id', 'members.parent');
                $model = $model->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');

                $total = $model->count();
                $data = $model->select('a.username as agent_username', 'members.id', 'members.parent', 'members.username', 'members.balance', 'members.email', 'members.nickname', 'members.first_name', 'members.last_name', 'members.birthday', 'members.mobile', 'members.country_id', 'members.zip_code', 'members.district', 'members.city', 'members.address', 'members.status', 'members.is_validate', 'members.mobile_validate', 'members.email_validate', 'members.last_login', DB::raw('DATE_ADD(members.created_at ,INTERVAL 8 HOUR) as created_at'))
                    ->orderBy('members.id', 'desc')
                    ->skip(($request->page - 1) * $request->per_page)
                    ->take($request->per_page)
                    ->get();
                // $redis->set($key, $data);
                // $redis->set($total_key, $total);
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
                $model = $model->leftJoin('agents as a', 'a.id', 'members.parent');
                $model = $model->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');

                $data = $model->select('a.username as agent_username', 'members.id', 'members.parent', 'members.username', 'members.balance', 'members.email', 'members.nickname', 'members.first_name', 'members.last_name', 'members.birthday', 'members.mobile', 'members.timezone_id', 'members.country_id', 'members.zip_code', 'members.district', 'members.city', 'members.address', 'members.status', 'members.is_validate', 'members.mobile_validate', 'members.email_validate', 'members.last_login')
                    ->where('members.id', $request->id)
                    ->first();
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
     * @bodyParam email email 會員電子信箱，最多60個字 Example: user@example.invalid
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
            $data = $model->where('id', $id)->whereIn('parent', $this->listChild($request->ID))->First();
            if (is_object($data)) {
                $row = array();
                if (isset($request->email) && $request->email != '' && $data->email != $request->email) {
                    $row['email'] = $request->email;
                    $row['email_validate'] = 0;
                }
                if ($request->password != '') {
                    $data['password'] = Hash::make($request->password);
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
                $row['admin'] = $request->ID;
                if ($data->update($row)) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'member'
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
    		$model = new FlowLimit();
        	$request->page = isset($request->page)? $request->page : 1;
        	$request->per_page = isset($request->per_page)? $request->per_page : 20;
    		$model = $model
    			->join('members as m', function($join) use ($request) {
    				$join->on('m.id', 'flow_limits.member_id')
    					->where('m.id', $request->member_id);
    			})
    			->join('transaction_logs as t', function($join) {
    				$join->on('t.id', 'flow_limits.reference_id')
    					->where('t.type', 17);
    			})
    			->orderBy('flow_limits.id', 'DESC');
            $total = $model->count();
    		$data = $model
    			->skip(($request->page - 1) * $request->per_page)
    			->take($request->per_page)
    			->select(
    				DB::raw('(CASE WHEN flow_limits.type = 1 THEN "'.__('param.unlimit_bonus') .'" ELSE "'. __('param.other_bonus') .'" END) as type'),
        			DB::raw('convert(t.point, decimal(16, 2)) as bonus'),
        			DB::raw('convert(flow_limits.flow_limit, decimal(16, 2)) as flow_limit'),
        			DB::raw('convert(flow_limits.reach, decimal(16, 2)) as valid_bet'),
            	    DB::raw('(CASE WHEN flow_limits.status = 1 THEN "' . __('param.pass') . '" ELSE "' . __('param.no_pass') . '" END) as status'),
            	    DB::raw('DATE_ADD(flow_limits.created_at,INTERVAL 8 HOUR) as created_at'),
            	    DB::raw('DATE_ADD(flow_limits.updated_at,INTERVAL 8 HOUR) as updated_at')
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
            'username.r_alpha_num' => __('error_message.1025'),
            'username.between' => __('error_message.1024'),
            'username.unique' => __('error_message.1002'),

            'password.required' => __('error_message.1016'),
            'password.between' => __('error_message.1017'),
            'password.confirmed' => __('error_message.2025'),

            'new_password.required' => __('error_message.1019'),
            'new_password.between' => __('error_message.1020'),
            'new_password.confirmed' => __('error_message.1021'),

            'email.email' => __('error_message.1014'),
            'email.between' => __('error_message.1015'),

            'nickname.between' => __('error_message.2024'),

            'first_name.r_alpha_dash' => __('error_message.1031'),
            'first_name.between' => __('error_message.1032'),

            'last_name.r_alpha_dash' => __('error_message.1033'),
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
}