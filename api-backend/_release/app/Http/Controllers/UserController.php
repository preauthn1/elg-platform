<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\User;
use Hash;
use Validator;
use App\REDIS;

class UserController extends Controller
{

    /**
     * user/list
     * -管理者列表
     *
     * @bodyParam status boolean option 管理員狀態 Example: 1 or 0
     * @bodyParam page numeric required 第幾頁 Example: 1
     * @bodyParam per_page numeric required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *      {
     *        "id": "系統編號,ex:1",
     *        "parent": "上層系統編號(0的話是第一層),ex:0",
     *        "username": "管理員帳號,ex:Admin",
     *        "nickname": "管理員暱稱,ex:XXX",
     *        "email": "管理員email,ex:user@example.invalid",
     *        "mobile": "手機號碼,ex:0912345678",
     *        "timezone_id": "時區系統編號,ex:1",
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
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'user',
                'list',
                $request->status,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'user',
                'list',
                $request->status,
                'total'
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                if (isset($request->status)) {
                    $model = $model->where('status', $request->status);
                }
                $total = $model->count();
                $data = $model->select('id', 'parent', 'username', 'nickname', 'email', 'mobile', 'timezone_id', 'status', 'last_login')
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
     * -管理者詳細資料
     *
     * @bodyParam id numeric required 管理員系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data":{
     *        "parent": "上層系統編號(0的話是第一層),ex:0",
     *        "username": "管理員帳號,ex:Admin",
     *        "nickname": "管理員暱稱,ex:XXX",
     *        "email": "管理員email,ex:user@example.invalid",
     *        "mobile": "手機號碼,ex:0912345678",
     *        "timezone_id": "時區系統編號,ex:1",
     *        "status": "狀態-0凍結1正常,ex:1",
     *        "last_login": "最後登入時間,ex:2019-04-01 09:36:46"
     *    }
     * }
     */
    public function show(Request $request)
    {
        try {
            $id = $request->id;
            // 驗證POST資料
            if (is_numeric($id)) {
                $row = User::select('parent', 'username', 'nickname', 'email', 'mobile', 'timezone_id', 'status', 'last_login')->find($id);
                if ($row) {
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $row
                    ]);
                } else {
                    return response()->json([
                        'code' => 1053,
                        'message' => __('error_message.1053')
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 1052,
                    'message' => __('error_message.1052')
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
     * -新增管理者
     *
     * @bodyParam parent numeric 上層的系統編號(沒選擇就是建立自己的下線) Example: 1
     * @bodyParam username string required 帳號(只可輸入英數，不可重覆，字數長度為4-10) Example: xxx
     * @bodyParam nickname string 暱稱 Example: xxx
     * @bodyParam email email required 電子信箱 Example: user@example.invalid
     * @bodyParam password string required 管理員密碼(字數長度為4-15)
     * @bodyParam password_confirmation string required 確認密碼
     * @bodyParam mobile string 聯絡電話 Example: +886912345678
     * @bodyParam timezone_id numeric 時區系統編號 Example: 1
     * @bodyParam status boolean 狀態-0凍結1正常 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "管理者編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new User();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 寫入數據庫
            $data = array();
            $data['parent'] = $request->parent ? $request->parent : $request->ID; // 沒有傳送parent，建立者為此帳號的上層
            $data['username'] = $request->username;
            $data['nickname'] = $request->nickname;
            $data['email'] = $request->email;
            $data['password'] = Hash::make($request->password);
            $data['mobile'] = $request->mobile;
            $data['timezone_id'] = $request->timezone_id;
            $data['status'] = isset($request->status) ? $request->status : 0;
            $data['admin'] = $request->ID;

            if ($result = $model->create($data)) {
                // 刪除Redis列表
                $key = array(
                    'user',
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
     * -編輯管理者
     *
     * @bodyParam id string required 管理員系統編號 Example: 1
     * @bodyParam parent numeric 上層的系統編號 Example: 1
     * @bodyParam nickname string 暱稱 Example: xxx
     * @bodyParam email email 電子信箱 Example: user@example.invalid
     * @bodyParam password string 管理員密碼(字數長度為4-15)
     * @bodyParam password_confirmation string 如需修改密碼，需傳確認密碼
     * @bodyParam mobile string 聯絡電話 Example: 0912345678
     * @bodyParam timezone_id numeric 時區系統編號 Example: 1
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
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            if ($row = $model->find($request->id)) {
                $data = array();
                $data['parent'] = isset($request->parent) ? $request->parent : $row->parent;
                $data['nickname'] = isset($request->nickname) ? $request->nickname : $row->nickname;
                $data['email'] = isset($request->email) ? $request->email : $row->email;
                if ($request->password != '') {
                    $data['password'] = Hash::make($request->password);
                }
                $data['mobile'] = isset($request->mobile) ? $request->mobile : $row->mobile;
                $data['timezone_id'] = isset($request->timezone_id) ? $request->timezone_id : $row->timezone_id;
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($model->find($request->id)->update($data)) {
                    $key = array(
                        'user',
                        'list'
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
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            if ($row = User::find($request->ID)) {
                // 驗證舊密碼是否正確
                if (Hash::check($request->password, $row->password)) {
                    $data = array();
                    $data['password'] = Hash::make($request->new_password);
                    $data['admin'] = $request->ID;
                    if (User::find($request->ID)->update($data)) {
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
     * -修改其他管理者密碼
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
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 先確認帳號是否存在
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
            if (! $validator->passes()) {
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
                        'user',
                        'list'
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

    private function messages()
    {
        return [
            'id.required' => __('error_message.1057'),
            'id.digits_between' => __('error_message.1052'),

            'parent.digits_between' => __('error_message.1062'),

            'username.required' => __('error_message.1026'),
            'username.r_alpha_num' => __('error_message.1025'),
            'username.between' => __('error_message.1024'),
            'username.unique' => __('error_message.1002'),

            'nickname.between' => __('error_message.2024'),

            'password.required' => __('error_message.1016'),
            'password.between' => __('error_message.1017'),
            'password.confirmed' => __('error_message.2025'),

            'new_password.required' => __('error_message.1019'),
            'new_password.between' => __('error_message.1020'),
            'new_password.confirmed' => __('error_message.1021'),

            'email.required' => __('error_message.1013'),
            'email.email' => __('error_message.1014'),
            'email.between' => __('error_message.1015'),

            'mobile.digits_between' => __('error_message.1036'),

            'timezone_id.digits_between' => __('error_message.1054'),

            'status.boolean' => __('error_message.2009'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}
