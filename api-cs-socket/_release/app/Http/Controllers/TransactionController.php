<?php

namespace App\Http\Controllers;

use App\Helpers\Common;
use Illuminate\Http\Request;
use App\User;
use App\Member;
use App\Transaction;
use App\Message;
use DB;
use Validator;
use App\REDIS;

class TransactionController extends Controller
{

    /**
     * transaction/list
     * - 開分/洗分/紅包記錄列表
     *
     * @bodyParam username string 會員帳號 Example:1
     * @bodyParam status boolean 開洗分狀態0:失敗,1:成功,2:拒絕,3:已過期,4:處理中 Example: 1 or 0
     * @bodyParam start_time dateTime required 開始時間 Example:2019-01-01 00:00:00
     * @bodyParam end_time dateTime required 結束時間 Example:2019-01-01 00:00:00
     * @bodyParam page numeric required 第幾頁 Example: 1
     * @bodyParam per_page numeric required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *        "id": "系統編號,ex:1",
     *        "user_id": "管理員系統編號,ex:1",
     *        "user_username": "管理員帳號,ex:cs2",
     *        "member_id": "會員系統編號,ex:1",
     *        "member_username": "會員帳號,ex:abc",
     *        "member_nickname": "會員暱稱,ex:abc",
     *        "type": "類型1:轉入，2:轉出,ex:1",
     *        "amount": "金額,ex:100",
     *        "remark": "備註",
     *        "status": "開洗分狀態0:失敗,1:成功,2:拒絕,3:已過期,4:處理中",
     *        "created_at": "建立時間,ex:2019-01-01 00:00:00",
     *        "updated_at": "更新時間,ex:2019-01-01 00:00:00"
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
            $model = new Transaction();
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
                'transaction',
                'list',
                $request->ID,
                $request->username,
                $request->status,
                $request->start_time,
                $request->end_time,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'transaction',
                'list',
                $request->ID,
                $request->username,
                $request->status,
                $request->start_time,
                $request->end_time,
                'total'
            );
            $redis = new REDIS();
            // if (!$redis->exists($key)) {
            //轉帳記錄
            if (isset($request->username) && $request->username != '') {
                $model = $model->where('m.third_party_id', 'like', '%' . $request->username . '%');
            }
            if (isset($request->status) && $request->status != '') {
                $model = $model->where('transactions.status', $request->status);
            }
            if (isset($request->start_time) && $request->start_time != '') {
                $model = $model->where('transactions.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')));
            }
            if (isset($request->end_time) && $request->end_time != '') {
                $model = $model->where('transactions.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour')));
            }
            $user_id = ($request->HOST != 0) ? $request->HOST : $request->ID; //如果host不是0就是子帳號
            $model = $model->leftJoin('users as cu', 'cu.id', 'transactions.user_id')
                ->leftJoin('members as m', 'm.id', 'transactions.member_id');
            $child = $this->listChild($user_id);
            // 只能查詢自己下線的
            $model = $model->where(function ($query) use ($child, $user_id) {
                $query->whereIn('transactions.user_id', $child)
                    ->orWhere('host_id', $user_id);
            });
            // $model = $model->whereIn('transactions.user_id', $this->listChild($user_id));

            $total = $model->getQuery()->getCountForPagination();
            $data = $model->select('transactions.id', 'transactions.user_id', 'cu.username as user_username', 'transactions.member_id', 'm.third_party_id as member_username', 'm.nickname as member_nickname', 'transactions.type', 'transactions.amount', 'transactions.remark', 'transactions.status', DB::raw('DATE_ADD(transactions.created_at,INTERVAL 8 HOUR) as created_at'), DB::raw('DATE_ADD(transactions.updated_at,INTERVAL 8 HOUR) as updated_at'))
                ->orderBy('transactions.created_at', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();
            //     $redis->set($key, $data);
            //     $redis->set($total_key, $total);
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
     * transaction/add
     * - 新增開分/洗分記錄
     *
     * @bodyParam member_id integer required 好友系統編號 Example: 1
     * @bodyParam type integer required 類型1:開分，2:洗分，3:開分(贈點) Example: 1
     * @bodyParam platform integer 類型(預設1:平台)1:平台，2:遊戲商 Example: 1
     * @bodyParam amount integer required 金額 Example: 100
     * @bodyParam remark string 備註
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "開分/洗分記錄系統編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new Transaction();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            //查詢玩家目前狀態是否在鎖分中(鎖分模式中禁止開洗分)
            $third_party_info = $this->getMemberInfo($request->AGENT_ID, $request->member_id);
            if (is_array($third_party_info)) {
                if ($third_party_info['event_mode'] != 1) {
                    $platform = isset($request->platform) ? $request->platform : 1; //預設開分到平台
                    // 寫入數據庫
                    $data = array();
                    $data['user_id'] = $request->ID;
                    $data['member_id'] = $request->member_id;
                    $data['type'] = $request->type;
                    $data['platform'] = $platform;
                    $data['amount'] = $request->amount;
                    $data['remark'] = $request->remark;

                    if ($result = $model->create($data)) {
                        DB::beginTransaction();
                        $user = User::lockForUpdate()->find($request->ID);
                        if (is_object($user)) {
                            //呼叫第三方api開洗分
                            switch ($request->type) {
                                case 1: //開分
                                    $type =  1;
                                    $transaction_type =  4;
                                    $success_status =  1;
                                    $message_type = 7;
                                    break;
                                case 2: //洗分
                                    $type =  2;
                                    $transaction_type =  5;
                                    if ($request->ROLE == 1 || $user->withdraw_confirm == 0) {
                                        //如果是總代理就不需同意或著代理設定不需要同意就直接完成洗分
                                        $success_status =  1;
                                    } else {
                                        $success_status =  4;
                                    }
                                    $message_type = 6;
                                    break;
                                case 3: //開分(贈點)
                                    $type =  1;
                                    $transaction_type =  10;
                                    $success_status =  1;
                                    $message_type = 8;
                                    break;
                            }
                            //確認是否還有開洗分的額度，-1:無上限，洗 分不驗額度
                            if (($request->type == 2) || ($user->limit_of_credit == -1) || ($user->limit_of_credit >= ($user->used_credit + $request->amount))) {
                                switch ($user->role_id) {
                                    case 1:
                                        if ($request->HOST != 0) {
                                            $agent_id = $request->HOST;
                                        } else {
                                            $agent_id =  $user->id;
                                        }
                                        break;
                                    case 2:
                                        $agent_id = $user->id;
                                        break;
                                    case 3:
                                        $agent_id = $user->parent_id;
                                        break;
                                    default:
                                        $agent_id = $user->id;
                                }
                                $member_agent = Member::find($request->member_id);
                                if (is_object($member_agent)) {
                                    // $member_agent_id = $member_agent->user_id;
                                    //開分先扣額度
                                    switch ($request->type) {
                                        case 1:
                                        case 3:
                                            $user->increment('used_credit', $request->amount);
                                            break;
                                    }
                                    $redis = new REDIS();
                                    $redis->delete([
                                        'user'
                                    ]);
                                    DB::commit();
                                    //呼叫API幫會員開洗分
                                    $result_post = $this->transaction($agent_id, $request->member_id,  $platform, $request->amount, $transaction_type, $request->remark, 0, $request->ID);
                                    if ($result_post) {
                                        //洗分要等確定成功才能加額度給代理
                                        switch ($request->type) {
                                            case 2:
                                                //如果是總代理就不需同意，就直接加上額度
                                                if ($success_status == 1) {
                                                    $user->decrement('used_credit', $request->amount);
                                                }
                                                break;
                                        }
                                        $user = User::find($request->ID);
                                        $member_balance = $this->getBalance($agent_id, $request->member_id);
                                        // 更新狀態
                                        $data = array();
                                        $data['status'] = $success_status;
                                        $data['user_limit_of_credit'] = $user->limit_of_credit;
                                        $data['user_used_credit'] = $user->used_credit;
                                        $data['member_balance'] = $member_balance;
                                        if (Transaction::where('id', $result->id)->update($data)) {
                                            //先取member id
                                            $member = Member::where('third_party_id', 0)->where('user_id', $agent_id)->first();

                                            $room = DB::table('rooms as r')->leftJoin('room_members as my', 'my.room_id', 'r.id')
                                                ->leftJoin('room_members as other', 'my.room_id', 'other.room_id')
                                                ->leftJoin('members as m', 'm.id', 'other.member_id')
                                                ->where('other.member_id', $request->member_id)
                                                ->where('my.member_id', $member->id)
                                                ->select('r.id', 'r.type', 'r.code', 'r.name', 'r.thumbnail')
                                                ->first();
                                            if (is_object($room)) {
                                                // 寫入聊天室訊息
                                                app('App\Http\Controllers\ChatController')->insertMessage($room->id, $request->ID, $member->id, $message_type, $request->amount, null, $result->id, $success_status);
                                                // 刪除Redis列表
                                                $redis = new REDIS();
                                                $redis->delete([
                                                    'transaction'
                                                ]);
                                                // $redis->delete([
                                                //     'user'
                                                // ]);
                                                return response()->json([
                                                    'code' => 1000,
                                                    'message' => __('error_message.1000'),
                                                    'data' => $result->id
                                                ]);
                                            } else {
                                                //聊天室不存在
                                                return response()->json([
                                                    'code' => 1104,
                                                    'message' => __('error_message.1104')
                                                ]);
                                            }
                                        } else {
                                            //db寫入失敗
                                            return response()->json([
                                                'code' => 2001,
                                                'message' => __('error_message.2001')
                                            ]);
                                        }
                                    } else {
                                        if ($type == 1) {
                                            DB::beginTransaction();
                                            $user = User::lockForUpdate()->find($request->ID);
                                            //開分失敗將點數還給代理
                                            $user->decrement('used_credit', $request->amount);
                                            $redis = new REDIS();
                                            $redis->delete([
                                                'user'
                                            ]);
                                            DB::commit();
                                            return response()->json([
                                                'code' => 1410,
                                                'message' => __('error_message.1410')
                                            ]);
                                        } else {
                                            //洗分失敗
                                            return response()->json([
                                                'code' => 1409,
                                                'message' => __('error_message.1409')
                                            ]);
                                        }
                                    }
                                } else {
                                    //帳號不存在
                                    return response()->json([
                                        'code' => 10052,
                                        'message' => __('error_message.1005')
                                    ]);
                                }
                            } else {
                                //額度不足
                                return response()->json([
                                    'code' => 1408,
                                    'message' => __('error_message.1408')
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
                            'code' => 2001,
                            'message' => __('error_message.2001')
                        ]);
                    }
                } else {
                    //鎖分模式中禁止被開洗分
                    return response()->json([
                        'code' => 2042,
                        'message' => __('error_message.2042')
                    ]);
                }
            } else {
                //無法查到會員資料
                return response()->json([
                    'code' => 2004,
                    'message' => __('error_message.2004')
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
     * transaction/edit
     * - 編輯開分/洗分記錄
     *
     * @bodyParam transaction_id integer required 開分/洗分記錄系統編號 Example: 1
     * @bodyParam remark string required 備註
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new Transaction();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            if ($row = $model->find($request->transaction_id)) {
                // 更新備註
                $data = [
                    'remark' => $request->remark
                ];
                if ($row->update($data)) {
                    $redis = new REDIS();
                    $redis->delete([
                        'transaction'
                    ]);
                    $redis->delete([
                        'user'
                    ]);
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
     * transaction/confirm
     * - 洗分確認
     *
     * @bodyParam transaction_id integer required 開分/洗分記錄系統編號 Example: 1
     * @bodyParam status integer required 狀態1:同意洗分，2拒絕洗分
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function confirm(Request $request)
    {
        try {
            $model = new Transaction();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_confirm, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            if ($row = $model->where('id', $request->transaction_id)->where('member_id', $request->ID)->where('status', 4)->first()) {
                // 更新狀態
                $data = [
                    'status' => $request->status
                ];
                //拒絕要退還金額
                if ($request->status == 2) {
                    $result_post = $this->transaction($row->user_id, $row->member_id,  1, $row->amount, 11, $request->remark, 0, $request->ID);
                    $data['third_party_result'] = $result_post;
                    $message = '用戶拒絕，編號為' . $request->transaction_id;
                    //更新用戶餘額
                    $member_balance = $this->getBalance($row->user_id,  $row->member_id);
                    $data['member_balance'] = $member_balance;
                } else {
                    //同意後將額度加給代理(扣除已使用額度)
                    User::where('id', $row->user_id)->decrement('used_credit', $row->amount);
                    $message = '用戶已接收，編號為' . $request->transaction_id;
                    //更新代理餘額
                    $user = User::find($row->user_id);
                    $data['user_used_credit'] = $user->used_credit;
                    $data['user_limit_of_credit'] = $user->limit_of_credit;
                }
                //取出這筆聊天記錄
                $history = Message::where('transaction_id', $request->transaction_id)->first();
                // 寫入聊天室訊息
                app('App\Http\Controllers\ChatController')->insertMessage($history->room_id, 0, $request->ID, 1,  $message, null, $request->transaction_id, $request->status);
                // 更新狀態及餘額
                if ($row->update($data)) {
                    $redis = new REDIS();
                    $redis->delete([
                        'transaction'
                    ]);
                    $redis->delete([
                        'user'
                    ]);
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
                    'code' => 2004,
                    'message' => __('error_message.2004')
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

    private function messages()
    {
        return [
            'transaction_id.required' => __('error_message.1301'),
            'transaction_id.digits_between' => __('error_message.1302'),

            'member_id.required' => __('error_message.2036'),
            'member_id.digits_between' => __('error_message.2035'),

            'type.required' => __('error_message.1303'),
            'type.in' => __('error_message.1304'),

            'amount.required' => __('error_message.2026'),
            'amount.digits_between' => __('error_message.2026'),

            'remark.between' => __('error_message.1305'),

            'status.required' => __('error_message.2009'),
            'status.boolean' => __('error_message.2009'),
            'status.in' => __('error_message.2033'),

            'start_time.required' => __('error_message.2027'),
            'start_time.date_format' => __('error_message.2029'),
            'end_time.required' => __('error_message.2028'),
            'end_time.date_format' => __('error_message.2030'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}