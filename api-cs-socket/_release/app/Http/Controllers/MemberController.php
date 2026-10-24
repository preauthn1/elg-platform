<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\User;
use App\Member;
use App\Friend;
use App\ThirdParty;
use App\Models\FlowLimit;
use DB;
use Hash;
use Validator;
use App\REDIS;

class MemberController extends Controller
{

    /**
     * member/search
     * -帳號查詢
     *
     * @bodyParam username string required 好友ID Example: hello
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *        "id": "會員系統編號,ex:1",
     *        "third_party_id": "第三方使用者帳號,ex:Admin",
     *        "username": "好友ID,ex:Admin",
     *        "nickname": "使用者暱稱(如果有設定別稱會顯示別稱),ex:XXX",
     *        "thumbnail": "縮圖,ex:http://xxx.xx.xxx",
     *        "friend_id": "好友編號",
     *        "status": "好友狀態0:不是好友，1:好友，2:黑名單,ex:1"
     *      }
     *    ]
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Member();
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // 限同個營運商的帳號才可互加(10/03解除限制)
            $status = DB::raw("(CASE WHEN f.status is NULL THEN 0 ELSE f.status END) as status");
            $data = $model->leftJoin('friends as f', function ($join) use ($request) {
                $join->on('f.other_id', '=', 'members.id')
                    ->where('f.self_id', $request->ID)
                    ->where('f.status', '>', 0);
            })
                ->where('members.username', $request->username)
                // ->where('members.user_id', $request->AGENT_ID)
                ->where('members.status', 1)
                ->select('members.id', 'members.third_party_id', 'members.username', 'members.nickname', 'members.thumbnail', 'f.id as friend_id', $status)
                ->first();
            if (is_object($data)) {
                if ($data->id == $request->ID) {
                    // 不可加入自己為好友
                    return response()->json([
                        'code' => 1101,
                        'message' => __('error_message.1101')
                    ]);
                } else {
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $data
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 1101,
                    'message' => __('error_message.1101')
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
     * member/view
     * -會員帳號資訊
     *
     * @bodyParam member_id integer required 會員系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *        "id": "會員系統編號,ex:1",
     *        "username": "第三方使用者帳號,ex:Admin",
     *        "nickname": "使用者暱稱(如果有設定別稱會顯示別稱),ex:XXX",
     *        "thumbnail": "縮圖,ex:http://xxx.xx.xxx",
     *        "friend_id": "好友編號",
     *        "balance": "0",
     *        "event_mode": "是否進入活動鎖定模式0:未鎖定，1:鎖定",
     *        "transaction_all": "是否為單一錢包模式0:否、1:是，EX:1",
     *        "status": "好友狀態0:不是好友，1:好友，2:黑名單,ex:1"
     *      }
     *    ]
     * }
     */
    public function view(Request $request)
    {
        try {
            $model = new Member();
            $validator = Validator::make($request->all(), $model->rules_view, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // 只能查詢同一個營運商(10/03解除限制)
            $status = DB::raw("(CASE WHEN f.status is NULL THEN 0 ELSE f.status END) as status");
            $data = $model->leftJoin('friends as f', function ($join) use ($request) {
                $join->on('f.other_id', '=', 'members.id')
                    ->where('f.self_id', $request->ID)
                    ->where('f.status', '>', 0);
            })
                ->where('members.id', $request->member_id)
                // ->whereIn('members.user_id', $this->listChild($request->AGENT_ID))
                ->where('members.status', 1)
                ->select('members.id', 'members.third_party_id as username', 'members.nickname', 'members.thumbnail', 'f.id as friend_id', $status)
                ->first();
            if (is_object($data)) {
                // 取玩家資訊跟餘額
                $third_party_info = $this->getMemberInfo($request->AGENT_ID, $request->member_id);
                if (is_array($third_party_info)) {
                    $data->balance = $third_party_info['balance'];
                    $data->event_mode = $third_party_info['event_mode'];
                    $data->transaction_all = $third_party_info['transaction_all'];
                }
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $data
                ]);
            } else {
                return response()->json([
                    'code' => 1101,
                    'message' => __('error_message.1101')
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
     * member/self
     * -會員帳號資訊(前台使用)
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *        "id": "會員系統編號,ex:1",
     *        "third_party_id": "第三方使用者帳號,ex:Admin",
     *        "username": "好友ID,ex:Admin",
     *        "nickname": "使用者暱稱(如果有設定別稱會顯示別稱),ex:XXX",
     *        "thumbnail": "縮圖,ex:http://xxx.xx.xxx",
     *        "balance": "0"
     *      }
     *    ]
     * }
     */
    public function self(Request $request)
    {
        try {
            // 只能查詢同一個營運商
            $data = Member::where('id', $request->ID)->select('id', 'third_party_id', 'username', 'nickname', 'thumbnail')->first();
            if (is_object($data)) {
                // 取餘額
                $data->balance = $this->getBalance($request->AGENT_ID, $request->ID);
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $data
                ]);
            } else {
                return response()->json([
                    'code' => 1101,
                    'message' => __('error_message.1101')
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
     * member/add
     * -建立會員帳號
     *
     * @bodyParam third_party_id string 串接平台的會員帳號 Example: xxx
     * @bodyParam username string required 好友ID(只可輸入英數，不可重覆，字數長度為4-10) Example: xxx
     * @bodyParam nickname string 暱稱 Example: xxx
     * @bodyParam thumbnail string base64 file 個人頭像
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "會員編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new Member();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $user_id = $request->AGENT_ID;
            // 寫入數據庫
            $data = array();
            $data['user_id'] = $user_id;
            $data['third_party_id'] = $request->third_party_id;
            $data['username'] = $request->username;
            $data['nickname'] = $request->nickname;
            // 移動圖檔至本地及上傳到GCP
            if (isset($request->thumbnail) && $request->thumbnail != '') {
                $path = $this->uploadBase64File($request->thumbnail);
                if ($path['code'] != 1000) {
                    return response()->json([
                        'code' => $path['code'],
                        'message' => $path['message']
                    ]);
                }
                $data['thumbnail'] = $path['message'];
            }
            $data['status'] = 1;
            $data['admin'] = $request->ID;

            if ($result = $model->create($data)) {
                // 如果新增了會員要幫他加入客服帳號,third_party_id 0 是客服帳號
                $cs = Member::where('user_id', $user_id)->where('third_party_id', 0)->first;
                // 會員加入客服當朋友
                $data = array();
                $data['self_id'] = $result->id;
                $data['other_id'] = $cs->id;
                $data['status'] = 1;
                Friend::create($data);
                // 客服加入會員當朋友
                $data = array();
                $data['self_id'] = $cs->id;
                $data['other_id'] = $result->id;
                $data['status'] = 1;
                Friend::create($data);
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
     * member/edit
     * -編輯自己的帳號資訊
     *
     * @bodyParam username string 好友ID(只有第一次能設定) Example: xxx
     * @bodyParam nickname string 暱稱 Example: xxx
     * @bodyParam thumbnail string base64 file 個人頭像
     * @bodyParam status boolean 狀態-0凍結1正常 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new Member();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            if ($row = $model->find($request->ID)) {
                $data = array();
                //username只能設定一次
                $data['username'] = (isset($request->username) && $request->username != '' && $row->username == '') ? $request->username : $row->username;
                $data['nickname'] = (isset($request->nickname) && $request->nickname != '') ? $request->nickname : $row->nickname;
                // 移動圖檔至本地及上傳到GCP
                if (isset($request->thumbnail) && $request->thumbnail != '') {
                    $path = $this->uploadBase64File($request->thumbnail);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $data['thumbnail'] = $path['message'];
                }
                $data['status'] = (isset($request->status) && $request->status != '') ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($row->update($data)) {
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
     * member/flowLimit
     * - 稽核流水
     *
     * @bodyParam member_id integer 玩家ID Example: 123
     * @bodyParam user_id integer 客服ID Example: 456
     * @bodyParam page integer 頁數 Example: 1
     * @bodyParam per_page integer 每頁幾筆 Example: 20
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *        "type": "種類,ex:全民代理",
     *        "bonus": "獲得金幣,ex:0.50",
     *        "flow_limit": "稽核流水,ex:0.50",
     *        "valid_bet": "有效輸贏流水,ex:0.00",
     *        "status": "狀態,ex:通過",
     *        "created_at": "時間,ex:2020-05-23 23:41:39",
     *        "updated_at": "操作時間,ex:2020-05-23 23:41:39"
     *      }
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
	public function getFlowLimit(Request $request)
	{
		try {
			$model = new Member();
        	$request->page = isset($request->page)? $request->page : 1;
        	$request->per_page = isset($request->per_page)? $request->per_page : 20;
			$member = $model
				->where('id', $request->member_id)
				->select('id', 'user_id', 'third_party_id', 'username', 'status')
				->first();
			$total = 0;
			$data = [];
			if (is_object($member)) {
				$model = new FlowLimit();
				$model = $model
						->join('members as m', function($join) use ($member){
							$join->on('m.id', '=', 'flow_limits.member_id')
								->where('m.username', $member->username);
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
            	        	DB::raw('(CASE WHEN flow_limits.type = 1 THEN "'.__('push_message.unlimit_bonus') .'" ELSE "'. __('push_message.other_bonus') .'" END) as type'),
        					DB::raw('convert(t.point, decimal(16, 2)) as bonus'),
        					DB::raw('convert(flow_limits.flow_limit, decimal(16, 2)) as flow_limit'),
        					DB::raw('convert(flow_limits.reach, decimal(16, 2)) as valid_bet'),
            	        	DB::raw('(CASE WHEN flow_limits.status = 1 THEN "' . __('push_message.pass') . '" ELSE "' . __('push_message.no_pass') . '" END) as status'),
            	        	DB::raw('DATE_ADD(flow_limits.created_at,INTERVAL 8 HOUR) as created_at'),
            	        	DB::raw('DATE_ADD(flow_limits.updated_at,INTERVAL 8 HOUR) as updated_at')
            	        )
            	        ->get();
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
	
    private function messages()
    {
        return [
            'id.required' => __('error_message.2017'),
            'id.digits_between' => __('error_message.2018'),

            'member_id.required' => __('error_message.2036'),
            'member_id.digits_between' => __('error_message.2035'),

            'username.required' => __('error_message.2019'),
            'username.r_alpha_num' => __('error_message.2020'),
            'username.between' => __('error_message.2021'),
            'username.unique' => __('error_message.1002'),

            'nickname.between' => __('error_message.2024'),

            'thumbnail.base64image' => __('error_message.2023'),

            'status.boolean' => __('error_message.2009')
        ];
    }
}