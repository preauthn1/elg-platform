<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Room;
use App\Member;
use App\Friend;
use Hash;
use Validator;
use App\REDIS;
use DB;

class FriendController extends Controller
{

    /**
     * friend/list
     * -好友列表
     *
     * @bodyParam status integer required 1:好友,2:黑名單 Example: 1
     * @bodyParam username integer 帳號查詢 Example: test
     * @bodyParam page numeric required 第幾頁 Example: 1
     * @bodyParam per_page numeric required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *        "friend_id": "好友系統編號,ex:1",
     *        "member_id": "會員系統編號,ex:1",
     *        "room_id": "聊天室系統編號(沒聊過天會是空值),ex:1",
     *        "type": "類型0:客服聊天，1:個人聊天，2:群組聊天室,ex:1",
     *        "third_party_id": "第三方使用者帳號,ex:Admin",
     *        "username": "好友ID,ex:Admin",
     *        "nickname": "使用者暱稱(如果有設定別稱會顯示別稱),ex:XXX",
     *        "thumbnail": "縮圖,ex:http://xxx.xx.xxx"
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
            $model = new Friend();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $type = DB::raw("(CASE WHEN m.third_party_id = '0' THEN 0 ELSE 1 END) as type");
            $nickname = DB::raw("(CASE WHEN f.nickname is NULL THEN m.nickname ELSE f.nickname END) as nickname");

            $data = DB::table('friends as f')->leftJoin('members as m', 'm.id', 'f.other_id')
                ->where('f.self_id', $request->ID)
                ->where('f.status', $request->status);
            if (isset($request->username) && $request->username != '') {
                $data = $data->where(function ($query) use ($request) {
                    $query->where('m.third_party_id', 'like', '%' . $request->username . '%')
                        ->orWhere('m.username', 'like', '%' . $request->username . '%');
                });
            }
            $page = isset($request->page) ? $request->page : 1;
            $per_page = isset($request->per_page) ? $request->per_page : 1000;
            $total = $data->getCountForPagination();
            $data = $data->select('f.id as friend_id', 'm.id as member_id', 'f.room_id', $type, 'm.third_party_id', 'm.username', $nickname, 'm.thumbnail')
                ->orderBy('type')
                ->orderBy('nickname')
                ->skip(($page - 1) * $per_page)
                ->take($per_page)
                ->get();
            if (is_object($data)) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $data,
                    'pagination' => $this->getPagination($total, $per_page, $page)
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
     * friend/show
     * -好友詳情
     *
     * @bodyParam member_id numeric required 會員系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *        "friend_id": "好友系統編號,ex:1",
     *        "member_id": "會員系統編號,ex:1",
     *        "type": "類型0:客服聊天，1:個人聊天，2:群組聊天室,ex:1",
     *        "third_party_id": "第三方使用者帳號,ex:Admin",
     *        "username": "好友ID,ex:Admin",
     *        "nickname": "使用者暱稱(如果有設定別稱會顯示別稱),ex:XXX",
     *        "thumbnail": "縮圖,ex:http://xxx.xx.xxx",
     *        "status": "狀態1:好友,2:黑名單,ex:1"
     *      }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Friend();
            $validator = Validator::make($request->all(), $model->rules_view, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $type = DB::raw("(CASE WHEN m.third_party_id = 0 THEN 0 ELSE 1 END) as type");
            $nickname = DB::raw("(CASE WHEN f.nickname is NULL THEN m.nickname ELSE f.nickname END) as nickname");
            $data = DB::table('friends as f')->leftJoin('members as m', 'm.id', 'f.other_id')
                ->where('self_id', $request->ID)
                ->where('m.id', $request->member_id)
                ->select('f.id as friend_id', 'm.id as member_id', $type, 'm.third_party_id', 'm.username', $nickname, 'm.thumbnail', 'f.status')
                ->first();
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
     * friend/add
     * -新增好友
     *
     * @bodyParam member_id numeric required 會員系統編號 Example: 1
     * @bodyParam nickname string 暱稱 Example: xxx
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new Friend();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            //查詢玩家目前狀態是否在鎖分中(鎖分模式中禁止新增好友及被新增)
            //新增好友的人
            $third_party_info = $this->getMemberInfo($request->AGENT_ID, $request->ID);
            if (is_array($third_party_info)) {
                if ($third_party_info['event_mode'] != 1) {
                    //被新增的好友
                    $third_party_info = $this->getMemberInfo($request->AGENT_ID, $request->member_id);
                    if (is_array($third_party_info)) {
                        if ($third_party_info['event_mode'] != 1) {
                            // 檢查是否同一個營運商(10/03解除限制)
                            // $member = Member::where('user_id', $request->AGENT_ID)->where('id', $request->member_id)->first();
                            $member = Member::where('id', $request->member_id)->first();
                            if (is_object($member)) {
                                // 檢查是否已經加過好友
                                $row = $model->where('self_id', $request->ID)
                                    ->where('other_id', $request->member_id)
                                    ->first();
                                if (!is_object($row)) {
                                    // 建立自己的好友
                                    $data = array();
                                    $data['self_id'] = $request->ID;
                                    $data['other_id'] = $request->member_id;
                                    $data['status'] = 1;
                                    if ($model->create($data)) {
                                        // 幫對方加好友
                                        $data = array();
                                        $data['self_id'] = $request->member_id;
                                        $data['other_id'] = $request->ID;
                                        $data['status'] = 1;
                                        if ($model->create($data)) {
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
                                            'code' => 2001,
                                            'message' => __('error_message.2001')
                                        ]);
                                    }
                                } else {
                                    if ($row->status == 0) {
                                        // 以前加過的只要更新狀態
                                        $row->update([
                                            'status' => 1
                                        ]);
                                        return response()->json([
                                            'code' => 1000,
                                            'message' => __('error_message.1000')
                                        ]);
                                    } else {
                                        // 好友已存在
                                        return response()->json([
                                            'code' => 1117,
                                            'message' => __('error_message.1117')
                                        ]);
                                    }
                                }
                            } else {
                                // 帳號不存在
                                return response()->json([
                                    'code' => 1101,
                                    'message' => __('error_message.1101')
                                ]);
                            }
                        } else {
                            //鎖分模式中禁止被加好友
                            return response()->json([
                                'code' => 20421,
                                'message' => __('error_message.2042')
                            ]);
                        }
                    } else {
                        //無法查到會員資料
                        return response()->json([
                            'code' => 20041,
                            'message' => __('error_message.2004')
                        ]);
                    }
                } else {
                    //鎖分模式中禁止加好友
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
     * friend/edit
     * -編輯好友資訊
     *
     * @bodyParam friend_id numeric required 好友系統編號 Example: 1
     * @bodyParam nickname string 暱稱 Example: xxx
     * @bodyParam status boolean 狀態-1:好友2黑名單 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new Friend();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $row = $model->where('id', $request->friend_id)
                ->where('self_id', $request->ID)
                ->where('status', '>', 0)
                ->first();
            if (is_object($row)) {
                $data = array();
                $data['nickname'] = (isset($request->nickname) && $request->nickname != '') ? $request->nickname : $row->nickname;
                $data['status'] = (isset($request->status) && $request->status != '') ? $request->status : $row->status;
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
                    'code' => 1102,
                    'message' => __('error_message.1102')
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
     * friend/delete
     * -刪除好友
     *
     * @bodyParam friend_id numeric required 好友系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function delete(Request $request)
    {
        try {
            $model = new Friend();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_delete, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // 先確認好友是否存在
            $row = $model->where('id', $request->friend_id)
                ->where('self_id', $request->ID)
                ->where('status', '>', 0)
                ->first();
            if (is_object($row)) {
                // 更新好友狀態
                if ($model->find($request->friend_id)->update(array(
                    'status' => 0
                ))) {
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
                    'code' => 1102,
                    'message' => __('error_message.1102')
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
            'member_id.required' => __('error_message.2036'),
            'member_id.digits_between' => __('error_message.2035'),

            'friend_id.required' => __('error_message.1115'),
            'friend_id.digits_between' => __('error_message.1116'),

            'nickname.between' => __('error_message.2024'),

            'status.required' => __('error_message.2033'),
            'status.in' => __('error_message.2033')
        ];
    }
}