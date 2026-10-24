<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\User;
use App\Member;
use App\Friend;
use App\Room;
use App\RoomMember;
use App\Message;
use App\MessageRead;
use App\FirebaseCloudMessage;
use App\Events\MessageSent;
use App\Events\NewMessage;
use Hash;
use Validator;
use App\REDIS;
use DB;

class ChatController extends Controller
{

    /**
     * room/list
     * -聊天室列表
     *
     * @bodyParam status integer 聊天室狀態(預設為1)1:沒隱藏,2:隱藏 Example: 1
     * 
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *        "id": "聊天室系統編號,ex:1",
     *        "type": "聊天室類型0:客服聊天，1:個人聊天，2:群組聊天室,ex:1",
     *        "code": "聊天室編號,ex:abcd",
     *        "name": "聊天室名稱,ex:XXX",
     *        "thumbnail": "聊天室縮圖,ex:http://xxx.xx.xxx",
     *        "members":"1,2,3",
     *        "message_id": "最後一則訊息編號",
     *        "message_member_id": "傳送最後一則訊息的會員編號",
     *        "message_username": "傳送最後一則訊息的會員帳號",
     *        "message_nickname": "傳送最後一則訊息的會員暱稱",
     *        "message_type": "傳送最後一則訊息的類型1:文字，2:圖片，3:紅包，4:聲音，5:影片，6:洗分，7:開分，8:開分贈點",
     *        "message_content": "傳送最後一則訊息的內容",
     *        "message_time": "傳送最後一則訊息的時間，EX:2019-08-23 11:37:25"
     *      }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            //最後一筆訊息
            // $last_message = DB::table('messages as m1')->leftJoin('messages as m2', function ($join) {
            //     $join->on('m1.room_id', '=', 'm2.room_id')
            //         ->where('m1.id', '<', DB::raw('m2.id'));
            // })
            //     ->whereNull('m2.id')
            //     ->select('m1.*');
            $sub = "SELECT MAX(m1.id) FROM messages AS m1 GROUP BY m1.room_id";
            // $sub = DB::table('messages as m1')->whereRaw('m1.id in ('.$s.')');
            $last_message = DB::table('messages as m1')->whereRaw('m1.id in (' . $sub . ')');

            //參與者
            $room_member = DB::table('room_members as rms')
            	->select('rms.room_id', DB::raw('GROUP_CONCAT(rms.member_id) as members'))
                ->where('rms.member_id', '!=', $request->ID)
                ->groupBy('rms.room_id');

            //聊天室列表
            $room_name = "(CASE WHEN (r.name is NULL OR r.name = '') THEN m.nickname ELSE r.name END) as name,";
            $room_thumbnail = "(CASE WHEN (r.thumbnail is NULL or r.thumbnail = '') THEN m.thumbnail ELSE r.thumbnail END) as thumbnail,";
            $member = "(SELECT GROUP_CONCAT(member_id) AS members FROM room_members WHERE member_id != '".$request->ID."' and room_id = r.id) as members ";
            $data = DB::table('room_members as rm')
            	->Join('rooms as r', 'r.id', 'rm.room_id')
                ->Join('room_members as other', function ($join) use ($request) {
                    $join->on('r.id', '=', 'other.room_id')
                        ->where('other.member_id', '!=', $request->ID);
                })
                ->Join('members as m', 'm.id', 'other.member_id');
            //隱藏的先不SHOW訊息，資料量太大，SQL太慢
            if ($request->status != 2 && $request->ID != 1) {
                $data = $data->Join(DB::raw("({$last_message->toSql()}) msg"), 'msg.room_id', 'r.id')
                    // ->mergeBindings($last_message)
                    ->Join('members as mm', 'mm.id', 'msg.member_id')
                    ->orderBy('msg.created_at', 'DESC');
                $select = ", msg.id as message_id,msg.member_id as message_member_id,mm.third_party_id as message_username,mm.nickname as message_nickname,msg.type as message_type,msg.content as message_content,DATE_ADD(msg.created_at,INTERVAL 8 HOUR) as message_time";
            } else {
                $select = '';
            }

            // $data = $data->leftJoin(DB::raw("({$room_member->toSql()})  other_rm"), 'other_rm.room_id', 'r.id')
            //     ->mergeBindings($room_member);
            if ($request->status) {
                $data = $data->where('rm.status', $request->status);
            } else {
                $data = $data->where('rm.status', 1);
            }

            $data = $data->where('rm.member_id', $request->ID)
                // ->select(DB::raw('r.id,r.type,r.code,' . $room_name . $room_thumbnail . 'other_rm.members' . $select))
                ->select(DB::raw('r.id,r.type,r.code,' . $room_name . $room_thumbnail . $member . $select))
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
     * room/enter
     * -進入聊天室(聊天室編號及好友系統編號擇一傳送即可，如皆傳送，以聊天室編號為主)
     *
     * @bodyParam code string 聊天室編號 Example: abcd
     * @bodyParam friend_id integer 好友系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *        "room": {
     *            "id":  "房間編號，EX:8",
     *            "type": "類型0:客服聊天，1:個人聊天，2:群組聊天室",
     *            "code": "聊天室編號，EHxX2SyGTvfoGXuXNewWRIms",
     *            "name": "聊天室名稱",
     *            "partner_username": "其他參與者帳號",
     *            "thumbnail": "聊天室頭像",
     *            "transaction_type": "開洗分模式0:不是客服1:一般，2:紅包",
     *            "isThird": "是否為玩家; 0:不是, 1:是"
     *        },
     *        "message預設為最後三十筆(時間順序)": [
     *            {
     *                "member_id": "會員編號，EX:8",
     *                "username": "第三方平台帳號",
     *                "nickname": "暱稱",
     *                "thumbnail": "頭像",
     *                "user_id": "客服編號，EX:8",
     *                "user_username": "客服帳號",
     *                "user_nickname": "客服暱稱",
     *                "type": "類型1:文字，2:圖片，3:紅包，4:聲音，5:影片，6:洗分，7:開分，8:開分贈點",
     *                "content": "聊天內容 or 洗分金額",
     *                "red_envelope_id": "紅包編號",
     *                "red_envelope_status": "紅包狀態1:未領取2:已領取3:已取消4:已過期",
     *                "transaction_id": "洗分編號",
     *                "transaction_status": "洗分狀態1:已同意2:已拒絕3:已過期4:等待用戶同意",
     *                "is_self": "屬於誰的對話0:自己，1:別人",
     *                "created_at": "2019-08-23 08:58:43"
     *            }
     *        ]
     *    }
     * }
     */
    public function enter(Request $request)
    {
        try {
            if (isset($request->code) || isset($request->friend_id)) {
                // 有聊天室編號
                if (isset($request->code) && $request->code != '') {
                    $room_name = DB::raw("(CASE WHEN (rooms.name is NULL or rooms.name = '') THEN m.nickname ELSE rooms.name END) as name");
                    $partner = DB::raw("GROUP_CONCAT(m.third_party_id) as partner_username");
                    $room_thumbnail = DB::raw("(CASE WHEN (rooms.thumbnail is NULL or rooms.thumbnail = '') THEN m.thumbnail ELSE rooms.thumbnail END) as thumbnail");
                    $room = Room::where('code', $request->code)
                    	->leftJoin('room_members as other', function ($join) use ($request) {
                        	$join->on('rooms.id', '=', 'other.room_id')
                            ->where('other.member_id', '!=', $request->ID)
                            ->where('other.status', 1);
                    	})
                        ->leftJoin('members as m', 'm.id', 'other.member_id')
                        ->select(
                        	'rooms.id', 
                        	'rooms.type', 
                        	'rooms.code', 
                        	$partner, 
                        	$room_name, 
                        	$room_thumbnail,
                        	DB::raw("(CASE WHEN m.third_party_id = '0' THEN 0 ELSE 1 END) as isThird")
                        )
                        ->groupBy('rooms.id')
                        ->first();
                    if (is_object($room)) {
                        // 取訊息
                        $data = [
                            'room' => $room
                        ];
                    } else {
                        // 聊天室不存在
                        return response()->json([
                            'code' => 1104,
                            'message' => __('error_message.1104')
                        ]);
                    }
                } else {
                    // 與好友建立聊天室
                    $friend = Friend::where('id', $request->friend_id)->where('self_id', $request->ID)
                        ->where('status', 1)
                        ->first();
                    // 驗證是否為好友
                    if (is_object($friend)) {
                        $room_name = DB::raw("(CASE WHEN (r.name is NULL or r.name = '') THEN m.nickname ELSE r.name END) as name");
                        $partner = DB::raw("m.third_party_id as partner_username");
                        $room = DB::table('rooms as r')
                        	->leftJoin('room_members as my', 'my.room_id', 'r.id')
                            ->leftJoin('room_members as other', 'my.room_id', 'other.room_id')
                            ->leftJoin('members as m', 'm.id', 'other.member_id')
                            ->where('other.member_id', $friend->other_id)
                            ->where('my.member_id', $request->ID)
                            ->select('r.id', 'r.type', 'r.code', $partner, $room_name, 'r.thumbnail')
                            ->first();
                        if (!is_object($room)) {
                            // 尚未建立過聊天室，建立聊天室
                            $room = Room::create([
                                'type' => 1,
                                'code' => $this->getRoomCode(),
                                'status' => 1
                            ]);
                            if ($room) {
                                // 新增聊天室成員
                                RoomMember::create([
                                    'room_id' => $room->id,
                                    'member_id' => $request->ID,
                                    'status' => 1
                                ]);
                                RoomMember::create([
                                    'room_id' => $room->id,
                                    'member_id' => $friend->other_id,
                                    'status' => 1
                                ]);
                                // 將房間編號寫入Friend
                                $friend->update([
                                    'room_id' => $room->id
                                ]);
                                $friend = Friend::where('other_id', $request->ID)->where('self_id', $friend->other_id)
                                    ->where('status', '>', 0)
                                    ->first();
                                $friend->update([
                                    'room_id' => $room->id
                                ]);
                            } else {
                                // 聊天室建立失敗
                                return response()->json([
                                    'code' => 2001,
                                    'message' => __('error_message.2001')
                                ]);
                            }
                        }
                        $room_name = DB::raw("(CASE WHEN (rooms.name is NULL or rooms.name = '') THEN m.nickname ELSE rooms.name END) as name");
                        $partner = DB::raw("m.third_party_id as partner_username");
                        $room_thumbnail = DB::raw("(CASE WHEN (rooms.thumbnail is NULL or rooms.thumbnail = '') THEN m.thumbnail ELSE rooms.thumbnail END) as thumbnail");
                        $room = Room::where('rooms.id', $room->id)
                        	->leftJoin('room_members as other', function ($join) use ($request) {
                            	$join->on('rooms.id', '=', 'other.room_id')
                                ->where('other.member_id', '!=', $request->ID)
                                ->where('other.status', 1);
                        	})
                            ->leftJoin('members as m', 'm.id', 'other.member_id')
                            ->select(
                            	'rooms.id', 
                            	'rooms.type', 
                            	'rooms.code', 
                            	$partner, 
                            	$room_name, 
                            	$room_thumbnail,
                            	DB::raw("(CASE WHEN m.third_party_id = '0' THEN 0 ELSE 1 END) as isThird")
                            )
                            ->first();
                        $data = [
                            'room' => $room
                        ];
                    } else {
                        // 不是好友
                        return response()->json([
                            'code' => 1102,
                            'message' => __('error_message.1102')
                        ]);
                    }
                }
                //查詢開洗分的類型
                $has_agent = DB::table('room_members as rm')->leftJoin('members as m', function ($join) use ($request) {
                    $join->on('m.id', '=', 'rm.member_id')
                        ->where('rm.status', 1);
                })->leftJoin('users as u', 'u.id', 'm.user_id')
                    ->where('rm.room_id', $data['room']['id'])->where('m.third_party_id', 0)
                    ->select('u.transaction_type')->first();
                if (is_object($has_agent)) {
                    $data['room']['transaction_type'] = $has_agent->transaction_type;
                } else {
                    //沒有代理帳號在會議室中
                    $data['room']['transaction_type'] = 0;
                }

                $message = $this->getMessageModel($request->ID, $room->id);
                $total = $message->getQuery()->getCountForPagination();
                $per_page = 30;
                if ($total <= $per_page) {
                    $page = 0;
                } else {
                    $page = $total - $per_page;
                }
                $data['message'] = $message->orderBy('messages.id', 'asc')
                    ->skip($page)
                    ->take($per_page)
                    ->get();
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $data
                ]);
            } else {
                return response()->json([
                    'code' => 1103,
                    'message' => __('error_message.1103')
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
     * room/history
     * -聊天室歷史訊息
     *
     * @bodyParam code string required 聊天室編號 Example: abcd
     * @bodyParam page numeric required 第幾頁 Example: 1
     * @bodyParam per_page numeric required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data(時間倒序)": [
     *            {
     *                "member_id": "會員編號，EX:8",
     *                "username": "第三方平台帳號",
     *                "nickname": "暱稱",
     *                "thumbnail": "頭像",
     *                "user_id": "客服編號，EX:8",
     *                "user_username": "客服帳號",
     *                "user_nickname": "客服暱稱",
     *                "type": "類型1:文字，2:圖片，3:紅包，4:聲音，5:影片，6:洗分，7:開分，8:開分贈點",
     *                "content": "聊天內容 or 洗分金額",
     *                "red_envelope_id": "紅包編號",
     *                "red_envelope_status": "紅包狀態1:未領取2:已領取3:已取消4:已過期",
     *                "transaction_id": "洗分編號",
     *                "transaction_status": "洗分狀態1:已同意2:已拒絕3:已過期4:等待用戶同意",
     *                "is_self": "屬於誰的對話0:自己，1:別人",
     *                "created_at": "2019-08-23 08:58:43"
     *            }
     *        ],
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
    public function history(Request $request)
    {
        try {
            $model = new Room();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_history, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // 有聊天室編號
            $room_name = DB::raw("(CASE WHEN rooms.name is NULL THEN m.nickname ELSE rooms.name END) as name");
            $room_thumbnail = DB::raw("(CASE WHEN rooms.thumbnail is NULL THEN m.thumbnail ELSE rooms.thumbnail END) as thumbnail");
            $room = Room::where('code', $request->code)->first();
            if (is_object($room)) {
                // 取訊息
                $model = $this->getMessageModel($request->ID, $room->id);
                $total = $model->getQuery()->getCountForPagination();
                $data = $model->orderBy('messages.id', 'desc')
                    ->skip(($request->page - 1) * $request->per_page)
                    ->take($request->per_page)
                    ->get();
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $data,
                    'pagination' => $this->getPagination($total, $request->per_page, $request->page)
                ]);
            } else {
                // 聊天室不存在
                return response()->json([
                    'code' => 1104,
                    'message' => __('error_message.1104')
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
     * room/changeStatus
     * -修改聊天室狀態
     *
     * @bodyParam code string required 聊天室編號 Example: abcd
     * @bodyParam status integer required 聊天室狀態(預設全部顯示)1:沒隱藏,2:隱藏 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function changeStatus(Request $request)
    {
        try {
            $model = new Room();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_status, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // 查看聊天室
            $room = Room::leftJoin('room_members as rm', 'rm.room_id', 'rooms.id')->select('rm.id')
            	->where('rm.member_id', $request->ID)->where('code', $request->code)->first();
            if (is_object($room)) {
                //修改狀態
                RoomMember::where('id', $room->id)->update(['status' => $request->status]);
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                ]);
            } else {
                // 聊天室不存在
                return response()->json([
                    'code' => 1104,
                    'message' => __('error_message.1104')
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
     * readMessage
     * -標記為已讀的最後編號
     *
     * @bodyParam code string required 聊天室編號 Example: abcd
     * @bodyParam message_id integer required 訊息編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function read(Request $request)
    {
        try {
            $model = new Room();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_read, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $data = array();
            $room = Room::leftJoin('room_members as m', 'm.room_id', 'rooms.id')
            	->where('code', $request->code)
                ->where('m.member_id', $request->ID)
                ->select('rooms.id')
                ->first();
            if (is_object($room)) {
                $data['room_id'] = $room->id;
                $data['message_id'] = $request->message_id;
                $data['member_id'] = $request->ID;
                MessageRead::create($data);
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000')
                ]);
            } else {
                return response()->json([
                    'code' => 1104,
                    'message' => __('error_message.1104')
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
     * sendMessage
     * -傳送訊息
     *
     * @bodyParam code string required 聊天室編號 Example: abcd
     * @bodyParam type integer required 訊息類型1:文字，2:圖片，3:紅包，4:聲音，5:影片 Example: 1
     * @bodyParam content string required 訊息內容(如果type 2，這裡就放base64的圖片) Example: 
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function send(Request $request)
    {
        try {
            $model = new Room();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_send, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $data = array();
            $room = Room::leftJoin('room_members as m', 'm.room_id', 'rooms.id')
            	->where('code', $request->code)
                ->where('m.member_id', $request->ID)
                ->select('rooms.id', 'rooms.code')
                ->first();
            if (is_object($room)) {
                return $this->insertMessage($room->id, $request->USER_ID, $request->ID, $request->type, $request->content);
            } else {
                return response()->json([
                    'code' => 1104,
                    'message' => __('error_message.1104')
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

    public function insertMessage($roomId, $userId, $memberId, $type, $content, $redEnvelopeId = null, $transactionId = null, $transactionStatus = null)
    {
        $room = Room::find($roomId);
        if (is_object($room)) {
            $data['room_id'] = $roomId;
            $data['user_id'] = $userId;
            $data['member_id'] =  $memberId;
            $data['type'] = $type;
            $need_upload = [
                2,
                4,
                5
            ];
            if (in_array($type, $need_upload)) {
                // 移動圖檔至本地及上傳到GCP
                if (isset($content) && $content != '') {
                    $path = $this->uploadBase64File($content, $roomId . '/');
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $data['content'] = $path['message'];
                }
            } else {
                $data['content'] = $content;
            }
            $data['red_envelope_id'] = $redEnvelopeId;
            $data['transaction_id'] = $transactionId;
            $data['transaction_status'] = $transactionStatus;
            //推播的時間加8小時
            $data['time'] = date('Y-m-d H:i:s', strtotime('+8hour'));
            $member = Member::find($memberId);
            $data['thumbnail'] = $member->thumbnail;
            $data['username'] = $member->username;
            $data['nickname'] = $member->nickname;
            Message::create($data);
            //判斷是客服發言還是玩家發言
            $is_customer = ($member->third_party_id == '0') ? true : false;
            //add user info後台要顯示客服名稱
            $user = User::find($data['user_id']);
            if (is_object($user)) {
                $data['user_username'] = $user->username;
                $data['user_nickname'] = $user->nickname;
            }

            $data['code'] = $room->code;
            $members = RoomMember::leftJoin('members as m', 'm.id', 'room_members.member_id')->where('room_id', $room->id)
                ->where('room_members.status', '>=', 1)
                ->select('m.id', 'm.username', 'm.third_party_id')
                ->get();
            //有新訊息時將隱藏的聊天室變為顯示
            RoomMember::where('room_id', $room->id)->update(['status' => 1]);

            // 發給聊天室裡的所有人
            foreach ($members as $row) {
                $encrypt = md5($row->id . env('API_MD5_KEY', 'k^QtGkDg7FXJ#@QC') . $row->username);
                event(new NewMessage($data, $encrypt));
                //不推播給自己也不推播給總代，因為總代數量太多
                if ($memberId != $row->id && $row->id != 1) {
                    $prefix = ($row->third_party_id == '0') ? '【Agent】' : '';
                    $content = $this->pushMesage($type, $is_customer);
                    $message = [
                        'member_id' => $row->id,
                        'title' => $prefix . __('push_message.' . $content),
                        'content' => $prefix . __('push_message.' . $content),
                        'url' => env('PUSH_URL') . 'Talk'
                    ];
                    FirebaseCloudMessage::create($message);
                }
            }

            // 發給正打開聊天室的
            event(new MessageSent($data, $room->code));

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000')
            ]);
        } else {
            return response()->json([
                'code' => 1104,
                'message' => __('error_message.1104')
            ]);
        }
    }
    private function getRoomCode()
    {
        $code = str_random(24);
        $is_exist = Room::where('code', $code)->count();
        // 驗證不重覆
        if ($is_exist) {
            return $this->getRoomCode();
        } else {
            return $code;
        }
    }

    private function getMessageModel($memberId, $roomId)
    {
        $is_self = DB::raw("(CASE WHEN messages.member_id = $memberId THEN 0 ELSE 1 END) as is_self");
        $username = DB::raw("(CASE WHEN messages.user_id = 0 AND m.third_party_id=0 THEN m.username  WHEN m.third_party_id=0 THEN u.username  ELSE m.third_party_id END) as username");

        $model = Message::leftJoin('members as m', 'm.id', 'messages.member_id')
            ->leftJoin('users as u', 'u.id', 'messages.user_id')
            ->leftJoin('red_envelopes as r', 'r.id', 'messages.red_envelope_id')
            ->leftJoin('transactions as t', 't.id', 'messages.transaction_id')
            ->where('messages.room_id', $roomId)->select('m.id as member_id', $username, 'm.nickname', 'm.thumbnail', 'u.id as user_id', 'u.username as user_username', 'u.nickname as user_nickname', 'messages.type', 'messages.content', 'messages.red_envelope_id', 'r.status as red_envelope_status', 'messages.transaction_id', 't.status as transaction_status', $is_self, DB::raw('DATE_ADD(messages.created_at,INTERVAL 8 HOUR) as created_at'));
      
            
            return $model;
    }

    private function pushMesage($type, $isCustomer)
    {
        //新增推播，content依類型不同有不同的(類型1:文字，2:圖片，3:紅包，4:聲音，5:影片，6:洗分，7:開分，8:開分贈點)
        switch ($type) {
            case 1:
                if ($isCustomer) {
                    //客服傳送
                    $content =  'new_message_by_cs';
                } else {
                    //一般訊息
                    $content =  'new_message_by_member';
                }
                break;
            case 2: //圖片
                $content =  'new_picture';
                break;
            case 3: //紅包
                $content =  'new_red_evenlope';
                break;
            case 6: //客服洗分
                $content =  'new_withdraw';
                break;
            case 7: //客服開分
            case 8: //客服開分贈點
                $content =  'new_deposit';
                break;
            default: //當一般訊息
                $content =  'new_message_by_member';
                break;
        }
        return $content;
    }

    private function messages()
    {
        return [
            'message_id.required' => __('error_message.1119'),
            'message_id.digits_between' => __('error_message.1120'),

            'code.required' => __('error_message.1118'),

            'type.required' => __('error_message.2040'),
            'type.in' => __('error_message.2040'),

            'content.required' => __('error_message.1122'),

            'status.required' => __('error_message.2033'),
            'status.in' => __('error_message.2033'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}