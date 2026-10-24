<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\User;
use App\Member;
use App\Note;
use App\NoteReply;
use DB;
use Validator;
use App\REDIS;

class NoteController extends Controller
{

    /**
     * note/list
     * -記事列表
     *
     * @bodyParam room_id integer 聊天室系統編號 Example:1
     * @bodyParam username integer 會員帳號 Example:abc
     * @bodyParam status boolean 記事狀態0:未結案,1:已結案 Example: 1 or 0
     * @bodyParam start_time dateTime 開始時間 Example:2019-01-01 00:00:00
     * @bodyParam end_time dateTime 結束時間 Example:2019-01-01 00:00:00
     * @bodyParam page numeric required 第幾頁 Example: 1
     * @bodyParam per_page numeric required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *        "id": "系統編號,ex:1",
     *        "room_id": "聊天室系統編號,ex:1",
     *        "member_id": "會員系統編號,ex:1",
     *        "member_username": "會員帳號,ex:abc",
     *        "title": "標題,ex:test",
     *        "created_at": "建立時間,ex:2019-01-01 00:00:00",
     *        "create_user": "建立者,ex:b",
     *        "update_user": "最後修改者,ex:a",
     *        "status": "狀態0:未結案，1:已結案"
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
            $model = new Note();
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
                'note',
                'list',
                $request->ID,
                $request->room_id,
                $request->username,
                $request->status,
                $request->start_time,
                $request->end_time,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'note',
                'list',
                $request->ID,
                $request->room_id,
                $request->username,
                $request->status,
                $request->start_time,
                $request->end_time,
                'total'
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                if (isset($request->room_id) && $request->room_id != '') {
                    $model = $model->where('notes.room_id', $request->room_id);
                }
                if (isset($request->username) && $request->username != '') {
                    $model = $model->where('m.third_party_id', 'like', '%' . $request->username . '%');
                }
                if (isset($request->status) && $request->status != '') {
                    $model = $model->where('notes.status', $request->status);
                }
                if (isset($request->start_time) && $request->start_time != '') {
                    $model = $model->where('notes.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')));
                }
                if (isset($request->end_time) && $request->end_time != '') {
                    $model = $model->where('notes.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour')));
                }
                $model = $model->where('notes.status', '<', 2)
                    ->leftJoin('users as cu', 'cu.id', 'notes.created_user')
                    ->leftJoin('users as uu', 'uu.id', 'notes.updated_user')
                    ->leftJoin('members as m', 'm.id', 'notes.member_id');
                $total = $model->count();
                $data = $model->select('notes.id', 'notes.room_id', 'notes.member_id', 'm.third_party_id as member_username', 'title', DB::raw('DATE_ADD(notes.created_at,INTERVAL 8 HOUR) as created_at'), 'cu.nickname as create_user', 'uu.nickname as update_user', 'notes.status')
                    ->orderBy('notes.id', 'desc')
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
     * note/add
     * -新增記事
     *
     * @bodyParam room_id integer required 聊天室系統編號 Example: 1
     * @bodyParam title string required 內容 Example: xxx
     * @bodyParam status boolean required 狀態0:未結案,1:已結案
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "記事編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new Note();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // 寫入數據庫
            $data = array();
            $data['room_id'] = $request->room_id;
            $data['member_id'] = $request->ID;
            $data['title'] = $request->title;
            $data['status'] = $request->status;
            $data['created_user'] = $data['updated_user'] = $request->USER_ID;

            if ($result = $model->create($data)) {
                // 刪除Redis列表
                $redis = new REDIS();
                $redis->delete([
                    'note'
                ]);
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
     * note/edit
     * -編輯記事
     *
     * @bodyParam note_id integer required 記事系統編號 Example: 1
     * @bodyParam title string required 內容 Example: xxx
     * @bodyParam status boolean required 狀態0:未結案,1:已結案
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new Note();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            if ($row = $model->find($request->note_id)) {
                // 更新記事
                $data = [
                    'title' => $request->title,
                    'status' => $request->status,
                    'updated_user' => $request->USER_ID
                ];
                if ($row->update($data)) {
                    $redis = new REDIS();
                    $redis->delete([
                        'note'
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
     * note_content/list
     * -記事內容列表
     *
     * @bodyParam note_id required integer 記事編號 Example:1
     * @bodyParam start_time dateTime 開始時間 Example:2019-01-01 00:00:00
     * @bodyParam end_time dateTime 結束時間 Example:2019-01-01 00:00:00
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *        "id": "系統編號,ex:1",
     *        "content": "內容,ex:hello world",
     *        "create_user": "建立者，ex:abc",
     *        "created_at": "建立時間，ex:2019-01-01 00:00:00"
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
    public function contentList(Request $request)
    {
        try {
            $model = new NoteReply();
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
                'note',
                'list',
                $request->ID,
                $request->note_id,
                $request->start_time,
                $request->end_time,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'note',
                'list',
                $request->ID,
                $request->note_id,
                $request->start_time,
                $request->end_time,
                'total'
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                if (isset($request->start_time) && $request->start_time != '') {
                    $model = $model->where('note_replies.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')));
                }
                if (isset($request->end_time) && $request->end_time != '') {
                    $model = $model->where('note_replies.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour')));
                }
                $model = $model->where('note_replies.note_id', $request->note_id)
                    ->where('note_replies.status', 1)
                    ->leftJoin('users as cu', 'cu.id', 'note_replies.user_id');
                $total = $model->count();
                $data = $model->select('note_replies.id', 'content', 'cu.nickname as create_user', DB::raw('DATE_ADD(note_replies.created_at,INTERVAL 8 HOUR) as created_at'))
                    ->orderBy('note_replies.id', 'desc')
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
     * note_content/add
     * -新增記事內容
     *
     * @bodyParam note_id integer required 記事系統編號 Example: 1
     * @bodyParam content string required 內容 Example: xxx
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "記事編號,ex:1"
     * }
     */
    public function contentCreate(Request $request)
    {
        try {
            $model = new NoteReply();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // 寫入數據庫
            $data = array();
            $data['note_id'] = $request->note_id;
            $data['user_id'] = $request->USER_ID;
            $data['content'] = $request->content;
            $data['status'] = 1;
            $data['updated_user'] = $request->USER_ID;

            if ($result = $model->create($data)) {
                // 刪除Redis列表
                $redis = new REDIS();
                $redis->delete([
                    'note'
                ]);
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
     * note_content/edit
     * -編輯記事內容
     *
     * @bodyParam content_id integer required 記事內容系統編號 Example: 1
     * @bodyParam content string required 記事內容系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function contentUpdate(Request $request)
    {
        try {
            $model = new NoteReply();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // 先確認記事內容是否存在
            if ($model->find($request->content_id)) {
                // 更新記事狀態
                if ($model->find($request->content_id)->update(array(
                    'content' => $request->content,
                    'updated_user' => $request->USER_ID
                ))) {
                    $key = array(
                        'note'
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
                    'code' => 1110,
                    'message' => __('error_message.1110')
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
     * note_content/delete
     * -刪除記事
     *
     * @bodyParam content_id numeric required 記事內容系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function contentDelete(Request $request)
    {
        try {
            $model = new NoteReply();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_delete, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // 先確認記事內容是否存在
            if ($model->find($request->content_id)) {
                // 更新記事狀態
                if ($model->find($request->content_id)->update(array(
                    'status' => 0,
                    'updated_user' => $request->USER_ID
                ))) {
                    $key = array(
                        'note'
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
                    'code' => 1105,
                    'message' => __('error_message.1105')
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
            'member_id.digits_between' => __('error_message.2035'),

            'note_id.required' => __('error_message.1106'),
            'note_id.digits_between' => __('error_message.1107'),

            'content_id.required' => __('error_message.1113'),
            'content_id.digits_between' => __('error_message.1114'),

            'username.r_alpha_num' => __('error_message.2020'),

            'title.required' => __('error_message.1108'),
            'title.between' => __('error_message.1109'),

            'content.required' => __('error_message.1111'),
            'content.between' => __('error_message.1112'),

            'status.required' => __('error_message.2009'),
            'status.boolean' => __('error_message.2009'),

            'start_time.date_format' => __('error_message.2029'),
            'end_time.date_format' => __('error_message.2030'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}