<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\User;
use App\Member;
use App\RedEnvelope;
use App\RedEnvelopeReceiver;
use App\Message;
use DB;
use Validator;
use App\REDIS;
use App\ThirdParty;

class RedEnvelopeController extends Controller
{
    /**
     * redEnvelope/record
     * - 紅包記錄列表(客服用)
     *
     * @bodyParam username string 會員帳號 Example:1
     * @bodyParam start_time dateTime 開始時間 Example:2019-01-01 00:00:00
     * @bodyParam end_time dateTime 結束時間 Example:2019-01-01 00:00:00
     * @bodyParam page numeric required 第幾頁 Example: 1
     * @bodyParam per_page numeric required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *        "created_at": "發送時間或領取時間",
     *        "type": "類型1:發送紅包,2:領取紅包,ex:1",
     *        "self_id": "執行者會員系統編號,ex:1",
     *        "self_username": "執行者會員帳號,ex:cs2",
     *        "self_nickname": "執行者會員暱稱,ex:cs2",
     *        "other_id": "對象會員系統編號,ex:1",
     *        "other_username": "對象會員帳號,ex:cs2",
     *        "other_nickname": "對象會員暱稱,ex:cs2",
     *        "amount": "金額,ex:100",
     *        "status": "紅包狀態1:未領取2:已領取"
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
    public function record(Request $request)
    {
        try {
            $model = new RedEnvelope();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_cs, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'red_envelope',
                'list',
                $request->ID,
                $request->username,
                $request->start_time,
                $request->end_time,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'red_envelope',
                'list',
                $request->ID,
                $request->username,
                $request->start_time,
                $request->end_time,
                'total'
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                //發送者
                $model = DB::table('red_envelopes as s')->leftJoin('red_envelope_receivers as r', 's.id', 'r.red_envelope_id')
                    ->leftJoin('members as sm', 'sm.id', 's.member_id')
                    ->leftJoin('members as rm', 'rm.id', 'r.member_id')
                    ->select(DB::raw('DATE_ADD(s.created_at,INTERVAL 8 HOUR) as created_at'), DB::raw('1 as type'), 'sm.id as self_id', 'sm.username as self_username', 'sm.nickname as self_nickname', 'rm.id as other_id', 'rm.username as other_username', 'rm.nickname as other_nickname', 's.amount', 's.status')
                    ->whereBetween('s.created_at',   [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))])
                    ->whereIn('s.status', [1, 2])
                    ->where(function ($query) use ($request) {
                        $query->where('sm.user_id', $request->ID)->orWhere('rm.user_id', $request->ID);
                    })
                    ->groupBy('s.id');

                if (isset($request->username) && $request->username != '') {
                    $model = $model->where('sm.username', 'like', '%' . $request->username . '%');
                }
                //接收者
                $receiver = DB::table('red_envelope_receivers as r')->leftJoin('red_envelopes as s', 's.id', 'r.red_envelope_id')
                    ->leftJoin('members as sm', 'sm.id', 's.member_id')
                    ->leftJoin('members as rm', 'rm.id', 'r.member_id')
                    ->select(DB::raw('DATE_ADD(r.created_at,INTERVAL 8 HOUR) as created_at'), DB::raw('2 as type'), 'rm.id as self_id', 'rm.username as self_username', 'rm.nickname as self_nickname', 'sm.id as other_id', 'sm.username as other_username', 'sm.nickname as other_nickname', 'r.amount', 's.status')
                    ->whereBetween('r.created_at',  [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))])
                    ->where('r.status', 1)
                    ->where(function ($query) use ($request) {
                        $query->where('sm.user_id', $request->ID)->orWhere('rm.user_id', $request->ID);
                    });

                if (isset($request->username) && $request->username != '') {
                    $receiver = $receiver->where('rm.username', 'like', '%' . $request->username . '%');
                }
                $total = $model->getCountForPagination() + $receiver->getCountForPagination();
                $model = $model->union($receiver);
                $data = $model->orderBy('created_at', 'desc')
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
     * redEnvelope/list
     * - 紅包記錄列表
     *
     * @bodyParam member_id integer required 會員系統編號 Example:1
     * @bodyParam action integer required  執行動作，1:發送2:接收 Example:1
     * @bodyParam page numeric required 第幾頁 Example: 1
     * @bodyParam per_page numeric required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *        "red_envelope_id": "紅包系統編號,ex:1",
     *        "sender_member_id": "發送者會員系統編號,ex:1",
     *        "sender_username": "發送者會員帳號,ex:cs2",
     *        "receiver_member_id": "接收者會員系統編號,ex:1",
     *        "receiver_username": "接收者會員帳號,ex:cs2",
     *        "type": "類型1:個人紅包，2:群組普通紅包，3:隨機紅包,ex:1",
     *        "amount": "金額,ex:100",
     *        "remark": "備註",
     *        "status": "紅包狀態1:未領取2:已領取3:已取消4:已過期",
     *        "send_time": "發送時間,ex:2019-01-01 00:00:00",
     *        "receive_time": "接收時間,ex:2019-01-01 00:00:00"
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
            $model = new RedEnvelope();
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
                'red_envelope',
                'list',
                $request->ID,
                $request->member_id,
                $request->action,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'red_envelope',
                'list',
                $request->ID,
                $request->member_id,
                $request->action,
                'total'
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $model = DB::table('red_envelopes as e')->leftJoin('red_envelope_receivers as r', 'e.id', 'r.red_envelope_id')
                    ->leftJoin('members as em', 'em.id', 'e.member_id')
                    ->leftJoin('members as rm', 'rm.id', 'r.member_id')
                    ->where('e.status', '>', 0);
                if ($request->action == 1) {
                    // 1 發送者
                    $model = $model->where('e.member_id', $request->member_id);
                } else if ($request->action == 2) {
                    // 2 接收者
                    $model = $model->where('r.member_id', $request->member_id);
                }

                $total = $model->count();
                $data = $model->select('e.id as red_envelope_id', 'e.member_id as sender_member_id', 'em.third_party_id as sender_username', 'r.member_id as receiver_member_id', 'rm.third_party_id as receiver_username', 'e.type', 'e.amount', 'e.remark', 'e.status', DB::raw('DATE_ADD(e.created_at,INTERVAL 8 HOUR) as send_time'), DB::raw('DATE_ADD(r.created_at,INTERVAL 8 HOUR) as receive_time'))
                    ->orderBy('e.id', 'desc')
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
     * redEnvelope/view
     * - 紅包明細
     *
     * @bodyParam red_envelope_id integer required 紅包記錄系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *        "red_envelope_id": "紅包系統編號,ex:1",
     *        "sender_member_id": "發送者會員系統編號,ex:1",
     *        "sender_username": "發送者會員帳號,ex:cs2",
     *        "sender_thumbnail": "發送者會員頭像",
     *        "type": "類型1:個人紅包，2:群組普通紅包，3:隨機紅包,ex:1",
     *        "amount": "金額,ex:100",
     *        "remark": "備註",
     *        "status": "紅包狀態1:未領取2:已領取3:已取消4:已過期",
     *        "send_time": "發送時間,ex:2019-01-01 00:00:00",
     *        "receiver":[
     *          {
     *              "member_id": "接收者會員系統編號,ex:1",
     *              "username": "接收者會員帳號,ex:cs2",
     *              "amount": "金額,ex:100",
     *              "receive_time": "接收時間,ex:2019-01-01 00:00:00"
     *          }
     *        ]
     *      }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new RedEnvelope();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'red_envelope',
                $request->red_envelope_id
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $data = DB::table('red_envelopes as e')->leftJoin('red_envelope_receivers as r', 'e.id', 'r.red_envelope_id')
                    ->leftJoin('members as m', 'm.id', 'e.member_id')
                    ->select('e.id as red_envelope_id', 'e.member_id as sender_member_id', 'm.third_party_id as sender_username', 'm.thumbnail as sender_thumbnail', 'e.type', 'e.amount', 'e.remark', 'e.status', 'e.created_at as send_time')
                    ->where('e.id', $request->red_envelope_id)
                    ->first();
                if (is_object($data)) {
                    $recevie = DB::table('red_envelope_receivers as r')->leftJoin('members as m', 'm.id', 'r.member_id')->where('r.red_envelope_id', $request->red_envelope_id)->select('r.member_id', 'm.third_party_id as username', 'r.amount', 'r.created_at as receive_time')->get();
                    $data->recevie = $recevie;
                } else {
                    return response()->json([
                        'code' => 1406,
                        'message' => __('error_message.1406')
                    ]);
                }
                $redis->set($key, $data);
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
     * redEnvelope/add
     * - 新增紅包
     *
     * @bodyParam room_id integer required 聊天室系統編號 Example: 1
     * @bodyParam type integer required 類型1:個人紅包，2:群組普通紅包，3:隨機紅包 Example: 1
     * @bodyParam amount integer required 金額 Example: 100
     * @bodyParam number integer required 數量 Example: 1
     * @bodyParam cancel_hour integer required 自動回收時間(以小時為單位) Example: 1
     * @bodyParam remark string 備註
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "紅包記錄系統編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new RedEnvelope();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            //查詢玩家目前狀態是否在鎖分中(鎖分模式中禁止發紅包)
            $third_party_info = $this->getMemberInfo($request->AGENT_ID, $request->ID);
            if (is_array($third_party_info)) {
                if ($third_party_info['event_mode'] != 1) {
                    // 如果聊天室類型等於1，紅包數量不能大於1
                    if ($request->type == 1 && $request->number > 1) {
                        return response()->json([
                            'code' => 1407,
                            'message' => __('error_message.1407')
                        ]);
                    } else {
                        // TODO群組聊天室紅包數量不可大於群組人數
                    }
                    // TODO 群組紅包
                    // 驗證餘額
                    if ($third_party_info['balance'] >= $request->amount) {
                        // 寫入數據庫
                        $data = array();
                        $data['room_id'] = $request->room_id;
                        $data['member_id'] = $request->ID;
                        $data['type'] = $request->type;
                        $data['amount'] = $request->amount;
                        $data['number'] = $request->number;
                        $data['cancel_time'] = date('Y-m-d H:i:s', strtotime('+' . $request->cancel_hour . ' hours'));
                        $data['remark'] = $request->remark;
                        $data['status'] = 0;

                        if ($result = $model->create($data)) {
                            // 刪除Redis列表
                            $redis = new REDIS();
                            $redis->delete([
                                'red_envelope'
                            ]);
                            // 成功
                            return response()->json([
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => $result->id
                            ]);
                        } else {
                            // 新增記錄失敗，請稍後再試
                            return response()->json([
                                'code' => 2001,
                                'message' => __('error_message.2001')
                            ]);
                        }
                    } else {
                        //餘額不足
                        return response()->json([
                            'code' => 1408,
                            'message' => __('error_message.1408')
                        ]);
                    }
                } else {
                    //鎖分模式中禁止發紅包
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
     * redEnvelope/validatePassword
     * - 驗證密碼
     *
     * @bodyParam red_envelope_id integer required 紅包記錄系統編號 Example: 1
     * @bodyParam password integer required 會員在第三方存放的密碼 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function validatePassword(Request $request)
    {
        try {
            $model = new RedEnvelope();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_password, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            //查詢玩家目前狀態是否在鎖分中(鎖分模式中禁止發紅包)
            $third_party_info = $this->getMemberInfo($request->AGENT_ID, $request->ID);
            if (is_array($third_party_info)) {
                if ($third_party_info['event_mode'] != 1) {
                    DB::beginTransaction();
                    //驗證紅包是否存在，及尚未驗證
                    $row = RedEnvelope::where('id', $request->red_envelope_id)->where('status', 0)->lockForUpdate()->first();
                    if (is_object($row)) {
                        // 驗證密碼
                        $result = $this->checkPassword($request->AGENT_ID, $request->ID, $request->password);
                        if ($result) {
                            // 轉出第三方金額 TODO 群組紅包金額
                            $result = $this->transaction($request->AGENT_ID, $request->ID, 1, $row->amount, 6, $row->remark, $request->ID, 0);
                            if ($result) {
                                // 更新狀態
                                $data = array();
                                $data['id'] = $request->red_envelope_id;
                                $data['status'] = 1;

                                if ($result = $row->update($data)) {
                                    // 刪除Redis列表
                                    $redis = new REDIS();
                                    $redis->delete([
                                        'red_envelope'
                                    ]);

                                    // 寫入聊天室訊息(會順便推播)
                                    app('App\Http\Controllers\ChatController')->insertMessage($row->room_id, 0, $row->member_id, 3, $row->remark, $row->id);
                                    DB::commit();
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
                                // 轉出失敗
                                return response()->json([
                                    'code' => 1409,
                                    'message' => __('error_message.1409')
                                ]);
                            }
                        } else {
                            // 密碼錯誤
                            return response()->json([
                                'code' => 1022,
                                'message' => __('error_message.1022')
                            ]);
                        }
                    } else {
                        // 紅包不存在
                        return response()->json([
                            'code' => 1406,
                            'message' => __('error_message.1406')
                        ]);
                    }
                } else {
                    //鎖分模式中禁止發紅包
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
     * redEnvelope/edit
     * - 打開紅包
     *
     * @bodyParam red_envelope_id integer required 紅包記錄系統編號 Example: 1
     * @bodyParam remark string required 備註
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new RedEnvelope();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            //查詢玩家目前狀態是否在鎖分中(鎖分模式中禁止領紅包), 無法查詢資料時也不可領
            $third_party_info = $this->getMemberInfo($request->AGENT_ID, $request->ID);
            if (is_array($third_party_info)) {
                if ($third_party_info['event_mode'] != 1) {
                    DB::beginTransaction();
                    $row = $model->where('id', $request->red_envelope_id)->lockForUpdate()->first();
                    if (is_object($row)) {
                        if ($row->status == 2) {
                            // 已被領取
                            return response()->json([
                                'code' => 1411,
                                'message' => __('error_message.1411')
                            ]);
                        } else if ($row->status == 0) {
                            // 查無此紅包
                            return response()->json([
                                'code' => 1406,
                                'message' => __('error_message.1406')
                            ]);
                        } else if ($row->status == 3) {
                            // 用戶手動取消紅包
                            return response()->json([
                                'code' => 1406,
                                'message' => __('error_message.1406')
                            ]);
                        } else if ($row->status == 4) {
                            // 過期系統取消紅包
                            return response()->json([
                                'code' => 1406,
                                'message' => __('error_message.1406')
                            ]);
                        } else {
                            // 看紅包數量及類型 TODO 群組紅包，隨機紅包
                            // 將紅包改為已領取
                            if ($row->update(['status' => 2])) {
                                // 新增領取記錄
                                $data = [
                                    'red_envelope_id' => $row->id,
                                    'member_id' => $request->ID,
                                    'amount' => $row->amount, // TODO 隨機紅包
                                    'remark' => $request->remark
                                ];
                                if ($receivers = RedEnvelopeReceiver::create($data)) {
                                    // 呼叫第三方api轉入點數
                                    $result = $this->transaction($request->AGENT_ID, $request->ID, 1, $row->amount, 7, $row->remark, $row->member_id, 0);
                                    if ($result) {
                                        RedEnvelopeReceiver::where('id', $receivers->id)->update(['status' => 1]);
                                        $redis = new REDIS();
                                        $redis->delete([
                                            'red_envelope'
                                        ]);
                                        DB::commit();
                                        return response()->json([
                                            'code' => 1000,
                                            'message' => __('error_message.1000')
                                        ]);
                                    } else {
                                        $row->update(['status' => 1]);
                                        //第三方金額錯誤
                                        return response()->json([
                                            'code' => 1410,
                                            'message' => __('error_message.1410')
                                        ]);
                                    }
                                } else {
                                    $row->update(['status' => 1]);
                                    //db寫入錯誤
                                    return response()->json([
                                        'code' => 2001,
                                        'message' => __('error_message.2001')
                                    ]);
                                }
                            } else {
                                //db寫入錯誤
                                return response()->json([
                                    'code' => 2001,
                                    'message' => __('error_message.2001')
                                ]);
                            }
                        }
                    } else {
                        //紅包不存在
                        return response()->json([
                            'code' => 1406,
                            'message' => __('error_message.1406')
                        ]);
                    }
                } else {
                    //鎖分模式中禁止領紅包
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
     * redEnvelope/cancel
     * - 取消紅包
     *
     * @bodyParam red_envelope_id integer required 紅包記錄系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function cancel(Request $request)
    {
        try {
            $model = new RedEnvelope();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_cancel, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            DB::beginTransaction();
            //先確認紅包是不是自己的
            $row = $model->where('id', $request->red_envelope_id)->where('member_id', $request->ID)->lockForUpdate()->first();
            if (is_object($row)) {
                if ($row->status == 2) {
                    // 已被領取
                    return response()->json([
                        'code' => 1411,
                        'message' => __('error_message.1411')
                    ]);
                } else if ($row->status == 0) {
                    // 查無此紅包
                    return response()->json([
                        'code' => 1406,
                        'message' => __('error_message.1406')
                    ]);
                } else if ($row->status == 3) {
                    // 用戶手動取消紅包
                    return response()->json([
                        'code' => 1406,
                        'message' => __('error_message.1406')
                    ]);
                } else if ($row->status == 4) {
                    // 過期系統取消紅包
                    return response()->json([
                        'code' => 1406,
                        'message' => __('error_message.1406')
                    ]);
                } else {
                    // TODO 計算群組紅包，隨機紅包剩下的金額
                    // 將紅包改為取消
                    if ($row->update(['status' => 3])) {
                        // 呼叫第三方api轉入點數
                        $result = $this->transaction($request->AGENT_ID, $row->member_id, 1, $row->amount, 8, $row->remark, $row->member_id, 0);
                        if ($result) {
                            $redis = new REDIS();
                            $redis->delete([
                                'red_envelope'
                            ]);
                            DB::commit();
                            return response()->json([
                                'code' => 1000,
                                'message' => __('error_message.1000')
                            ]);
                        } else {
                            $row->update(['status' => 1]);
                            //第三方金額錯誤
                            return response()->json([
                                'code' => 1410,
                                'message' => __('error_message.1410')
                            ]);
                        }
                    } else {
                        //db寫入錯誤
                        return response()->json([
                            'code' => 2001,
                            'message' => __('error_message.2001')
                        ]);
                    }
                }
            } else {
                //紅包不存在
                return response()->json([
                    'code' => 1406,
                    'message' => __('error_message.1406')
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
            'red_envelope_id.required' => __('error_message.1301'),
            'red_envelope_id.digits_between' => __('error_message.1302'),

            'member_id.required' => __('error_message.2036'),
            'member_id.digits_between' => __('error_message.2035'),

            'room_id.required' => __('error_message.1118'),
            'room_id.digits_between' => __('error_message.1121'),

            'action.required' => __('error_message.2035'),
            'action.in' => __('error_message.2035'),

            'type.required' => __('error_message.1303'),
            'type.in' => __('error_message.1304'),

            'amount.required' => __('error_message.2026'),
            'amount.digits_between' => __('error_message.2026'),

            'number.required' => __('error_message.1404'),
            'number.digits_between' => __('error_message.1404'),

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