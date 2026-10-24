<?php

namespace App\Http\Controllers;

use App\Helpers\Common;
use Illuminate\Http\Request;
use App\User;
use App\TransactionAgent;
use App\Member;
use App\Friend;
use Hash;
use DB;
use Validator;
use App\REDIS;
use App\Setting;
use App\ThirdParty;
use Composer\Package\Package;

class UserController extends Controller
{

    /**
     * user/list
     * -營運商/客服列表(列出所有在下層樹狀中的帳號)
     *
     * @bodyParam username string option 營運商/客服帳號
     * @bodyParam nickname string option 營運商/客服名稱
     * @bodyParam role_id string option 角色編號(預設2),2:代理，3:客服
     * @bodyParam status boolean option 營運商/客服狀態 Example: 1 or 0
     * @bodyParam page numeric required 第幾頁 Example: 1
     * @bodyParam per_page numeric required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *        "id": "系統編號,ex:1",
     *        "username": "營運商/客服帳號,ex:Admin",
     *        "nickname": "營運商/客服暱稱,ex:XXX",
     *        "thumbnail": "縮圖,ex:http://xxx.xx.xxx",
     *        "limit_of_credit": "開洗分額度(-1為無上限),ex:0",
     *        "used_credit": "已使用額度,ex:1",
     *        "transaction_type": "開洗分模式1:一般2:紅包,ex:1",
     *        "withdraw_confirm": "洗分是否需要同意，0:不需要、1:需要,ex:1",
     *        "status": "狀態-0凍結1正常,ex:1",
     *        "last_login": "最後登入時間,ex:2019-04-01 09:36:46"
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
    public function list(Request $request)
    {
        try {
            $model = new User();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'user',
                'list',
                $request->ID,
                $request->username,
                $request->nickname,
                $request->role_id,
                $request->status,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'user',
                'list',
                $request->ID,
                $request->username,
                $request->nickname,
                $request->role_id,
                $request->status,
                'total'
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                if (isset($request->username) && $request->username != '') {
                    $model = $model->where('username', 'like', '%' . $request->username . '%');
                }
                if (isset($request->nickname) && $request->nickname != '') {
                    $model = $model->where('nickname', 'like', '%' . $request->nickname . '%');
                }
                if (isset($request->status) && $request->status != '') {
                    $model = $model->where('status', $request->status);
                }
                $user_id = ($request->HOST != 0) ? $request->HOST : $request->ID; //如果host不是0就是子帳號
                //可以看所有在下層樹狀中的帳號
                $model = $model->whereIn('parent_id', $this->listChild($user_id));

                if ($request->role_id == 3) { //客服管理
                    $model->where('role_id', 3);
                } else { //營運商管理
                    $model->where('role_id', 2);
                }
                $total = $model->count();
                $data = $model->select('id', 'username', 'nickname', 'thumbnail', 'limit_of_credit', 'used_credit', 'transaction_type', 'withdraw_confirm', 'status', 'last_login')
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
     * user/view
     * -管理者/營運商/客服詳細資料(可以看所有在下層樹狀中的帳號)
     *
     * @bodyParam id numeric required 管理員系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data":{
     *        "role_id": "角色系統編號,ex:2",
     *        "username": "管理者/營運商/客服帳號,ex:Admin",
     *        "API_USER": "使用API的帳號,ex:Admin",
     *        "nickname": "管理者/營運商/客服暱稱,ex:XXX",
     *        "share_code": "分享碼,ex:123456",
     *        "domain": "域名,ex:https://",
     *        "thumbnail": "縮圖,ex:http://xxx.xx.xxx",
     *        "limit_of_credit": "開洗分額度(0為無上限),ex:0",
     *        "used_credit": "已使用額度,ex:1",
     *        "transaction_type": "開洗分模式1:一般2:紅包,ex:1",
     *        "withdraw_confirm": "洗分是否需要同意，0:不需要、1:需要,ex:1",
     *        "status": "狀態-0凍結1正常,ex:1",
     *        "last_login": "最後登入時間,ex:2019-04-01 09:36:46",
     *        "member_id": "聊天會員系統編號,ex:2",
     *        "member_username": "聊天會員系統編號,ex:2"
     *    }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new User();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_view, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $id = $request->id;
            // 驗證POST資料
            $model = User::select('id', 'role_id', 'host_id', 'parent_id',  'username', 'API_USER', 'nickname', 'share_code', 'domain', 'thumbnail', 'limit_of_credit', 'used_credit', 'transaction_type', 'withdraw_confirm', 'status', 'last_login');
            if ($request->ID != $id) {
                $user_id = ($request->HOST != 0) ? $request->HOST : $request->ID; //如果host不是0就是子帳號
                //可以看所有在下層樹狀中的帳號
                $model = $model->whereIn('id', $this->listChild($user_id));
            }
            if ($row = $model->where('id', $id)->first()) {
                if ($row->role_id == 1) {
                    if ($row->host_id != 0) {
                        // 營運商子帳號
                        $agent_id = $row->host_id;
                    } else {
                        // 營運商帳號
                        $agent_id = $row->id;
                    }
                } elseif ($row->role_id == 2) {
                    // 代理商帳號
                    $agent_id = $row->id;
                } else if ($row->role_id == 3) {
                    // 營運商帳號
                    $agent_id = $row->parent_id;
                }
                $member = Member::where('user_id', $agent_id)->where('third_party_id', 0)->first();
                if (is_object($member)) {
                    $row['member_id'] = $member->id;
                    $row['member_username'] = $member->username;
                } else {
                    $row['member_id'] = '';
                    $row['member_username'] = '';
                }
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $row
                ]);
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
     * user/add
     * -建立營運商/客服
     *
     * @bodyParam username string required 帳號(只可輸入英數，不可重覆，字數長度為4-10) Example: xxx
     * @bodyParam nickname string 暱稱 Example: xxx
     * @bodyParam password string required 管理員密碼(字數長度為4-15)
     * @bodyParam password_confirmation string required 確認密碼
     * @bodyParam thumbnail string base64 file 個人頭像
     * @bodyParam transaction_type boolean 開洗分模式1:一般2:紅包 Example: 1
     * @bodyParam withdraw_confirm boolean 洗分是否需要同意，0:不需要、1:需要 Example: 1
     * @bodyParam status boolean 狀態-0凍結1正常 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "營運商/客服編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new User();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            DB::beginTransaction();
            // $my = User::find($request->ID);
            //如果有給額度要驗證, 如果本身不是無限的，不可以開給下線無限制(10/03不可建立無限制額度)
            // if (is_object($my)  && ($request->limit_of_credit >= 0) && (($my->limit_of_credit - $my->used_credit) >= $request->limit_of_credit || $my->limit_of_credit == -1)) {
            $role_id = ($request->ROLE + 1);
            // 寫入數據庫
            $data = array();
            $data['role_id'] = $role_id;
            $data['parent_id'] = ($request->HOST != 0) ? $request->HOST : $request->ID;
            $data['username'] = $request->username;
            $data['password'] = Hash::make($request->password);
            if ($role_id == 2) {
                $api = $this->getApiInfo();
                $data['API_USER'] = $api['user'];
                $data['API_KEY'] = $api['key'];
            }
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
            // $data['limit_of_credit'] = (isset($request->limit_of_credit) && $request->limit_of_credit != '') ? $request->limit_of_credit : 0;
            $data['transaction_type'] = (isset($request->transaction_type) && $request->transaction_type != '') ? $request->transaction_type : 1;
            $data['withdraw_confirm'] = (isset($request->withdraw_confirm) && $request->withdraw_confirm != '') ? $request->withdraw_confirm : 1;
            $data['status'] = (isset($request->status) && $request->status != '') ? $request->status : 1;
            $data['admin'] = $request->ID;

            if ($result = $model->create($data)) {
                //如果有給額度，寫記錄
                // if ($request->limit_of_credit > 0) {
                //     //新增已使用額度
                //     $my->increment('used_credit', $request->limit_of_credit);
                //     //寫入開洗分log
                //     $transaction = [
                //         'from' => $request->ID,
                //         'to' => $result->id,
                //         'type' => 1,
                //         'amount' => $request->limit_of_credit,
                //         'remark' => '建立帳號開分',
                //         'status' => 1,
                //     ];
                //     TransactionAgent::create($transaction);
                // }
                // 如果新增了營運商，同時要新增一個聊天用帳號
                if ($role_id == 2) {
                    $data = array();
                    $data['user_id'] = $result->id;
                    $data['third_party_id'] = 0; // 0代表是客服帳號
                    $data['username'] = $request->username;
                    $data['nickname'] = 'Agent Service';
                    $data['thumbnial'] = isset($path['message']) ? $path['message'] : '';
                    $data['status'] = 1;
                    $member = Member::create($data);
                    //與最上層營運商加好友
                    Friend::create(['self_id' => $member->id, 'other_id' => 1]);
                    Friend::create(['other_id' => $member->id, 'self_id' => 1]);
                }
                DB::commit();
                // 刪除Redis列表
                $key = array(
                    'user'
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
            // } else {
            //     return response()->json([
            //         'code' => 1408,
            //         'message' => __('error_message.1408')
            //     ]);
            // }
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
     * user/edit
     * -編輯營運商/客服(可以改所有在下層樹狀中的帳號)
     *
     * @bodyParam id string required 營運商/客服系統編號 Example: 1
     * @bodyParam nickname string 暱稱 Example: xxx
     * @bodyParam password string 管理員密碼(字數長度為4-15)
     * @bodyParam password_confirmation string 如需修改密碼，需傳確認密碼
     * @bodyParam thumbnail string base64 file 個人頭像
     * @bodyParam transaction_type boolean 開洗分模式1:一般2:紅包 Example: 1
     * @bodyParam withdraw_confirm boolean 洗分是否需要同意，0:不需要、1:需要 Example: 1
     * @bodyParam status boolean 狀態-0凍結1正常 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new User();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            DB::beginTransaction();
            //可以改所有在下層樹狀中的帳號
            if ($request->ID != $request->id) {
                $user_id = ($request->HOST != 0) ? $request->HOST : $request->ID;
                $model = $model->whereIn('parent_id', $this->listChild($user_id));
            }
            if ($row = $model->find($request->id)) {
                //如果有給額度要驗證, 如果本身不是無限的，不可以開給下線無限制(10/03不可建立無限制額度)
                // $my = User::find($request->ID);
                // $change_limit = $request->limit_of_credit - ($row->limit_of_credit - $row->used_credit); //要變更的金額-目前餘額
                // if (is_object($my) && ($request->limit_of_credit >= 0) && (($my->limit_of_credit - $my->used_credit) >= $change_limit || $my->limit_of_credit == -1)) {
                $data = array();
                $data['nickname'] = (isset($request->nickname) && $request->nickname != '') ? $request->nickname : $row->nickname;
                if (isset($request->password) && $request->password != '') {
                    $data['password'] = Hash::make($request->password);
                }
                // 移動圖檔至本地及上傳到GCP
                if (isset($request->thumbnail) && trim($request->thumbnail) != '') {
                    $path = $this->uploadBase64File($request->thumbnail);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $data['thumbnail'] = $path['message'];
                }
                // $data['limit_of_credit'] = ($request->limit_of_credit + $row->used_credit); //額度為將要變更的+已使用的
                $data['transaction_type'] = (isset($request->transaction_type) && $request->transaction_type != '') ? $request->transaction_type : $row->transaction_type;
                $data['withdraw_confirm'] = (isset($request->withdraw_confirm) && $request->withdraw_confirm != '') ? $request->withdraw_confirm : $row->withdraw_confirm;
                $data['status'] = (isset($request->status) && $request->status != '') ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($row->update($data)) {
                    //如果額度有變更，寫記錄
                    // if ($change_limit != 0) {
                    //     //變更額度
                    //     if ($change_limit > 0) {
                    //         //開分
                    //         $my->increment('used_credit', $change_limit);
                    //         $type = 1;
                    //     } else {
                    //         //洗分
                    //         $my->decrement('used_credit', abs($change_limit));
                    //         $type = 2;
                    //     }
                    //     //寫入開洗分log
                    //     $transaction = [
                    //         'from' => $request->ID,
                    //         'to' => $request->id,
                    //         'type' => $type,
                    //         'amount' => abs($change_limit),
                    //         'remark' => '編輯帳號開/洗分',
                    //         'status' => 1,
                    //     ];
                    //     TransactionAgent::create($transaction);
                    // }
                    DB::commit();
                    $key = array(
                        'user'
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
                    'code' => 1408,
                    'message' => __('error_message.1408')
                ]);
            }
            // } else {
            //     return response()->json([
            //         'code' => 1005,
            //         'message' => __('error_message.1005')
            //     ]);
            // }
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
     * user/changePasswordBySelf
     * -修改自己的密碼
     *
     * @bodyParam password string required 舊密碼 Example: aaa123456
     * @bodyParam new_password string required 新密碼(字數長度為4-15) Example: abc123456
     * @bodyParam new_password_confirmation string required 確認新密碼 Example: abc123456
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function changePasswordBySelf(Request $request)
    {
        try {
            $model = new User();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_password_self, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            if ($row = User::find($request->ID)) {
                // 驗證舊密碼是否正確
                if (Hash::check($request->password, $row->password)) {
                    $data = array();
                    $data['password'] = Hash::make($request->new_password);
                    $data['admin'] = $request->ID;
                    if ($row->update($data)) {
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
     * user/changePassword
     * -修改其他管理者密碼(只能修改自己下層的帳號)
     *
     * @bodyParam id integer required 管理員系統編號 Example: 1
     * @bodyParam new_password string required 新密碼(字數長度為4-15) Example: abc123456
     * @bodyParam new_password_confirmation string required 確認新密碼 Example: abc123456
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function changePassword(Request $request)
    {
        try {
            $model = new User();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_password, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // 先確認帳號是否存在
            if ($request->ID != $request->id) {
                $model = $model->where('parent_id', $request->ID);
            }
            if ($model->find($request->id)) {
                $data = array();
                $data['password'] = Hash::make($request->new_password);
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
     * user/delete
     * -凍結管理者
     *
     * @bodyParam id numeric required 管理員系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function delete(Request $request)
    {
        try {
            $model = new User();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_delete, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
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
                        'user'
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



    /**
     * user/transaction
     * -管理員開洗分
     *
     * @bodyParam id integer required 管理員系統編號 Example: 1
     * @bodyParam amount integer required 金額 Example: 100
     * @bodyParam remark string required 備註 Example: test
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function userTransaction(Request $request)
    {
        try {
            $model = new User();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_transaction, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            if (abs($request->amount) == 0) {
                return response()->json([
                    'code' => 2041,
                    'message' => __('error_message.2041')
                ]);
            }
            DB::beginTransaction();
            // 先確認帳號是否存在, 是否為下線
            if ($request->ID != $request->id) {
                $user_id = ($request->HOST != 0) ? $request->HOST : $request->ID;
                $model = $model->whereIn('parent_id', $this->listChild($user_id));
            }

            if ($user = $model->find($request->id)) {
                $my = User::find($request->ID);
                //如果開分要驗證開分者餘額,洗分要驗證被洗分者餘額
                if (is_object($my)) {
                    //變更額度
                    if ($request->amount > 0 && (($my->limit_of_credit - $my->used_credit) >= $request->amount || $my->limit_of_credit == -1)) {
                        //開分
                        $my->increment('used_credit', $request->amount);
                        $user->increment('limit_of_credit', $request->amount);
                        $type = 1;
                    } else if ($request->amount < 0 && ($user->limit_of_credit - $user->used_credit) >= abs($request->amount)) {
                        //洗分
                        $my->decrement('used_credit', abs($request->amount));
                        $user->decrement('limit_of_credit', abs($request->amount));
                        $type = 2;
                    }
                    if (isset($type)) {
                        $user = $model->find($request->id);
                        $my = User::find($request->ID);
                        //寫入開洗分log
                        $transaction = [
                            'from' => $request->ID,
                            'to' => $request->id,
                            'type' => $type,
                            'amount' => abs($request->amount),
                            'from_limit_of_credit' =>  $my->limit_of_credit,
                            'from_used_credit' =>  $my->used_credit,
                            'to_limit_of_credit' => $user->limit_of_credit,
                            'to_used_credit' => $user->used_credit,
                            'remark' => $request->remark,
                            'status' => 1,
                        ];
                        TransactionAgent::create($transaction);
                        //寫log到遊戲平台
                        $third_party = new ThirdParty($user_id);
                        $third_party->agentTransaction($transaction);
                        // 刪除Redis列表
                        $key = array(
                            'user'
                        );
                        $redis = new REDIS();
                        $redis->delete($key);
                        DB::commit();
                        $code = 1000;
                        $message = __('error_message.1000');
                    } else {
                        $code = 1408;
                        $message = __('error_message.1408');
                    }
                } else {
                    $code = 1408;
                    $message = __('error_message.1408');
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

    public function sync(Request $request)
    {
        try {
            //加IP訪問限制 
            if (getenv('HTTP_CLIENT_IP') && strcasecmp(getenv('HTTP_CLIENT_IP'), 'unknown')) {
                $userip = getenv('HTTP_CLIENT_IP');
                //  echo "1:".$userip."<br>";
            } elseif (getenv('HTTP_X_FORWARDED_FOR') && strcasecmp(getenv('HTTP_X_FORWARDED_FOR'), 'unknown')) {
                $userip = explode(",", getenv('HTTP_X_FORWARDED_FOR'))[0];
                //  echo "2:".$userip."<br>";
            } elseif (getenv('REMOTE_ADDR') && strcasecmp(getenv('REMOTE_ADDR'), 'unknown')) {
                $userip = getenv('REMOTE_ADDR');
                //  echo "3:".$userip."<br>";
            } elseif (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], 'unknown')) {
                $userip = $_SERVER['REMOTE_ADDR'];
                //  echo "4:".$userip."<br>";
            }
            $ips = ['0.0.0.0', '0.0.0.0', '0.0.0.0', '0.0.0.0'];
            if (!in_array($userip, $ips)) {
                Common::log(0, $userip, 'sync_agent');
                echo '您的IP不在允許存取範圍:' . $userip;
                exit;
            }
            Common::log(0, $request->all(), 'sync_agent');


            DB::beginTransaction();
            $model = new User();
            //查上層帳號是否存在
            $user = User::where('username', $request->parent)->first();
            if (is_object($user)) {
                $has_user = User::where('username', $request->username)->first();
                $data = array();
                $data['parent_id'] = $user->id;
                $data['password'] = $request->password;
                $data['share_code'] = $request->code;
                $api = $this->getApiInfo();
                $data['nickname'] = $request->nickname;
                $data['admin'] = 0;
                if (is_object($has_user)) {
                    //修改營運商資料
                    if (!$has_user->update($data)) {
                        return response()->json([
                            'code' => 2001,
                            'message' => __('error_message.2001')
                        ]);
                    }
                    $data['API_USER'] = $has_user->API_USER;
                    $data['API_KEY'] = $has_user->API_KEY;
                } else {
                    $data['role_id'] = ($request->parent == 'Admin') ? 1 : 2; //如果上線是平台管理員的話，就是總代理帳號
                    $data['username'] = $request->username;
                    $data['API_USER'] = $api['user'];
                    $data['API_KEY'] = $api['key'];
                    $data['status'] = 1;
                    // 新增營運商
                    if ($result = $model->create($data)) {
                        // 如果新增了營運商，同時要新增一個聊天用帳號
                        $data = array();
                        $data['user_id'] = $result->id;
                        $data['third_party_id'] = 0; // 0代表是客服帳號
                        $data['username'] = $request->username;
                        $data['nickname'] = 'Agent Service';
                        $data['status'] = 1;
                        $member = Member::create($data);
                        //與最上層營運商加好友
                        Friend::create(['self_id' => $member->id, 'other_id' => 1, 'status' => 1]);
                        Friend::create(['other_id' => $member->id, 'self_id' => 1, 'status' => 1]);
                        //新增設定
                        Setting::create(['agent_id' => $result->id, 'api_url' => env('DEFAULT_API_URL')]);
                    } else {
                        return response()->json([
                            'code' => 2001,
                            'message' => __('error_message.2001')
                        ]);
                    }
                }
                DB::commit();
                // 刪除Redis列表
                $key = array(
                    'user'
                );
                $redis = new REDIS();
                $redis->delete($key);
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' =>  ['API_USER' => $data['API_USER'], 'API_KEY' => $data['API_KEY']]
                ]);
            } else {
                return response()->json([
                    'code' => 0000,
                    'message' => '上層帳號不存在'
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

    //代理無限代獎勵發放
    public function userUnlimitBonus(Request $request)
    {
        try {
            $model = new User();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_unlimitBonus, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            if (abs($request->amount) == 0) {
                return response()->json([
                    'code' => 2041,
                    'message' => __('error_message.2041')
                ]);
            }
            DB::beginTransaction();
            if ($user = $model->where('username', $request->to_username)->first()) {  //發給誰
                $my = User::where('username', $request->from_username)->first();  //發放的人 admin
                //如果開分要驗證開分者餘額,洗分要驗證被洗分者餘額
                if (is_object($my)) {
                    //增加無限代獎勵
                    if ($request->amount > 0 && (($my->limit_of_credit - $my->used_credit) >= $request->amount || $my->limit_of_credit == -1)) {
                        //開分
                        $rows = 2;
                        $my->increment('used_credit', $request->amount);
                        $user->increment('limit_of_credit', $request->amount);
                        $type = 3;
                        $remark = '';
                    }
                    if (isset($type)) {
                        $user = $model->where('username', $request->to_username)->first();
                        $my = User::where('username', $request->from_username)->first();
                        //寫入TransactionAgent
                        $transaction = [
                            'from' => $my->id,
                            'to' => $user->id,
                            'type' => $type,
                            'amount' => abs($request->amount),
                            'from_limit_of_credit' => $my->limit_of_credit,
                            'from_used_credit' => $my->used_credit,
                            'to_limit_of_credit' => $user->limit_of_credit,
                            'to_used_credit' => $user->used_credit,
                            'remark' => $remark,
                            'status' => 1
                        ];
                        TransactionAgent::create($transaction);
                        //寫log到遊戲平台
                        $third_party = new ThirdParty($my->id);
                        $third_party->agentTransaction($transaction);
                        // 刪除Redis列表
                        $key = array(
                            'user'
                        );
                        $redis = new REDIS();
                        $redis->delete($key);
                        DB::commit();
                        $code = 1000;
                        $message = __('error_message.1000');
                    } else {
                        $code = 1408;
                        $message = __('error_message.1408');
                    }
                } else {
                    $code = 1408;
                    $message = __('error_message.1408');
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
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function getApiInfo()
    {
        $user = str_random(10);
        $key = str_random(24);
        $is_exist = User::where('API_USER', $user)->where('API_KEY', $key)->count();
        // 驗證不重覆
        if ($is_exist) {
            return $this->getApiInfo();
        } else {
            return [
                'user' => $user,
                'key' => $key
            ];
        }
    }

    private function messages()
    {
        return [
            'id.required' => __('error_message.2017'),
            'id.digits_between' => __('error_message.2018'),

            'username.required' => __('error_message.2019'),
            'username.r_alpha_num' => __('error_message.2020'),
            'username.between' => __('error_message.2021'),
            'username.unique' => __('error_message.1002'),

            'API_USER.required' => __('error_message.1006'),
            'API_USER.r_alpha_num' => __('error_message.1007'),
            'API_USER.between' => __('error_message.1008'),
            'API_USER.unique' => __('error_message.1009'),

            'API_KEY.required' => __('error_message.1010'),
            'API_KEY.r_alpha_num' => __('error_message.1011'),
            'API_KEY.between' => __('error_message.1012'),

            'nickname.between' => __('error_message.2024'),

            'password.required' => __('error_message.1016'),
            'password.between' => __('error_message.1017'),
            'password.confirmed' => __('error_message.2025'),

            'new_password.required' => __('error_message.1019'),
            'new_password.between' => __('error_message.1020'),
            'new_password.confirmed' => __('error_message.1021'),

            'thumbnail.base64image' => __('error_message.2023'),
            'limit_of_credit.required' => __('error_message.2026'),
            'limit_of_credit.digits_between' => __('error_message.2026'),
            'status.boolean' => __('error_message.2009'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}