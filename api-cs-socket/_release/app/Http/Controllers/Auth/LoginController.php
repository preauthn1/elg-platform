<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\User;
use App\UserToken;
use App\Member;
use Hash;

class LoginController extends Controller
{

    /**
     * login
     * -會員登入(Header不需要token)
     *
     * @bodyParam username string required 會員帳號 Example: username
     * @bodyParam password string required 會員密碼 Example: abc123456
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data":  {
     *        "id": "客服後台帳號的系統編號",
     *        "member_id": "聊天室帳號的系統編號",
     *        "member_username": "聊天室帳號的會員帳號",
     *        "role_id": "角色編號1:總代理2:代理3:客服",
     *        "username": "test",
     *        "nickname": "Test",
     *        "thumbnail": "頭像，ex:http://xxxx.xxxx.xxx",
     *        "last_login": "2019-01-01 00:00:00",
     *        "token": "tW7ryWHJnz9261aY4cCLUGJxjfeacVXS",
     *        "domain": "營運商域名",
     *        "share_code": "營運商分享碼"
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
            // 帳號是否存在
            $row = User::where('username', $request->username)->first();
            if (is_object($row)) {
                // 是否為有效帳號(狀態為1)
                $array = [
                    1
                ];
                $data = array();
                $id = $data['user_id'] = $row->id;
                if (in_array($row['status'], $array)) {
                    // 驗證密碼是否正確
                    //先驗證vworld的帳號
                    $vworld = false;
                    if (strtoupper(md5($request->password)) == $row->password) {
                        $vworld = true;
                        //如果相同，更新密碼
                        $new_password = [REDACTED];
                    }
                    if ($vworld || Hash::check($request->password, $row->password)) {
                        // 將舊TOKEN改為無效
                        $now = date('Y-m-d H:i:s');
                        UserToken:[REDACTED]'user_id', $row->id)->where('expire_date', '>', $now)->update([
                            'expire_date' => $now
                        ]);
                        // 新增TOKEN
                        $token = [REDACTED]'token'] = Str::random(32);
                        $data['expire_date'] = date('Y-m-d H:i:s', strtotime('+ ' . env('TOKEN_EXPIRED') . 'minute'));
                        $data['admin'] = $row->id;
                        UserToken:[REDACTED];
                        // 更新最後登入時間
                        $user = User::find($row->id);
                        $user_update = [
                            'last_login' => date('Y-m-d H:i:s')
                        ];
                        if (isset($new_password)) {
                            $user_update['password'] = $new_password;
                        }
                        $user->update($user_update);

                        if ($user->role_id == 1) {
                            if ($user->host_id != 0) {
                                // 營運商子帳號
                                $agent_id = $user->host_id;
                            } else {
                                // 營運商帳號
                                $agent_id = $user->id;
                            }
                        } elseif ($user->role_id == 2) {
                            // 代理商帳號
                            $agent_id = $user->id;
                        } else if ($user->role_id == 3) {
                            // 營運商帳號
                            $agent_id = $user->parent_id;
                        }
                        $data = array();
                        $data['id'] = $user['id'];
                        if (isset($agent_id)) {
                            $member = Member::where('user_id', $agent_id)->where('third_party_id', 0)->first();
                            if (is_object($member)) {
                                $data['member_id'] = $member->id;
                                $data['member_username'] = $member->username;
                                if (isset($request->device_id) && $request->device_id != '') {
                                    //同組 Device token 允許一位會員及一位代理帳號同時擁有
                                    Member::where('device_id', $request->device_id)->where('third_party_id', '0')->where('user_id', '!=', $agent_id)->update(['device_id' => null]);
                                    $member->update(['device_id' => $request->device_id]);
                                    $data['device_id'] = $request->device_id;
                                }
                            } else {
                                $data['member_id'] = '';
                                $data['member_username'] = '';
                            }
                        }
                        $data['role_id'] = $user['role_id'];
                        $data['username'] = $user['username'];
                        $data['nickname'] = $user['nickname'];
                        $data['thumbnail'] = $user['thumbnail'];
                        $data['last_login'] = $user['last_login'];
                        $data['token'] = $token;
                        $data['domain'] = $user['domain'];
                        $data['share_code'] = $user['share_code'];
                        $result = array(
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => $data
                        );
                        $status = 1;
                    } else {
                        $result = array(
                            'code' => 90051,
                            'message' => __('error_message.9005')
                        );
                    }
                } else {
                    $result = array(
                        'code' => 9006,
                        'message' => __('error_message.9006')
                    );
                }
            } else {
                $result = array(
                    'code' => 9005,
                    'message' => __('error_message.9005')
                );
            }
            return response()->json($result);
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
            // 更新TOKEN過期時間
            $data['expire_date'] = date('Y-m-d H:i:s');
            UserToken:[REDACTED]'token', $request->TOKEN)->update($data);

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