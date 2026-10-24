<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\User;
use App\UserAccessToken;
use App\UserLoginLog;
use App\DES;
use Hash;

class LoginController extends Controller
{

    /**
     * login
     * -會員登入(不需要token)
     *
     * @bodyParam username string required 會員帳號 Example: username
     * @bodyParam password string required 會員密碼 Example: abc123456
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data":  {
     *        "id": 1,
     *        "username": "Admin",
     *        "token": "roOAsDmfT9nP1Y9uKD8aMylk6HcYPlfz"
     *    }
     * }
     */
    public function login(Request $request)
    {
        try {
            // 帳號密碼不可為空
            if ($request->username == '' || $request->password == '') {
                return response()->json([
                    'code' => 9004,
                    'message' => __('error_message.9004')
                ]);
            }
            $id = 0;
            $status = 0;
            // 是否為有效帳號
            $row = User::where('username', $request->username)->where('status', 1)->first();
            if (is_object($row)) {
                $data = array();
                $id = $data['user_id'] = $row->id;
                // 驗證密碼是否正確
                if (Hash::check($request->password, $row->password)) {
                    // 將舊TOKEN改為無效
                    $now = date('Y-m-d H:i:s');
                    UserAccessToken:[REDACTED]'user_id', $row->id)->where('expire_date', '>', $now)->update([
                        'expire_date' => $now
                    ]);
                    // 新增TOKEN
                    $token = [REDACTED]'token'] = Str::random(32);
                    $data['expire_date'] = date('Y-m-d H:i:s', strtotime('+ ' . env('TOKEN_EXPIRED') . 'minute'));
                    $data['admin'] = $row->id;
                    UserAccessToken:[REDACTED];
                    // 更新最後登入時間
                    $user = User::find($row->id);
                    $user->update(array(
                        'last_login' => date('Y-m-d H:i:s')
                    ));
                    $data = array();
                    $data['id'] = $user['id'];
                    $data['username'] = $user['username'];
                    $data['token'] = $token;
                    $response = [
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $data
                    ];
                    $status = 1;
                } else {
                    $response = [
                        'code' => 9005,
                        'message' => __('error_message.9005')
                    ];
                }
            } else {
                $response = [
                    'code' => 9005,
                    'message' => __('error_message.9005')
                ];
            }
            // 新增登入記錄
            $log = array();
            $log['user_id'] = $id;
            $log['ip'] = $this->getIp();
            $log['status'] = $status;
            $log['admin'] = $id;
            UserLoginLog::create($log);

            return response()->json($response);
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
     * logout
     * -會員登出
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */
    public function logout(Request $request)
    {
        try {
            $data = array();
            // 修改TOKEN到期時間
            $data['expire_date'] = date('Y-m-d H:i:s');
            UserAccessToken:[REDACTED]'token', $request->TOKEN)->update($data);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000')
            ], 200);
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
}
