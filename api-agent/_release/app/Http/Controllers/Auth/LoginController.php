<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Agent;
use App\AgentAccessToken;
use App\AgentLoginLog;
use App\DES;
use Hash;
use App\REDIS;
use PhpTelegramBot\Laravel\PhpTelegramBotContract;


class LoginController extends Controller
{

    /**
     * login
     * -會員登入(不需要token)
     *
     * @bodyParam username string required 代理帳號 Example: username
     * @bodyParam password string required 代理密碼 Example: abc123456
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
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
            $row = Agent::where('username', $request->username)->where('status', 1)->first();
            if (is_object($row)) {
                $data = array();
                $id = $data['agent_id'] = $row->id;
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
                    AgentAccessToken:[REDACTED]'agent_id', $row->id)->where('expire_date', '>', $now)->update([
                        'expire_date' => $now
                    ]);
                    // 新增TOKEN
                    $token = [REDACTED]'token'] = Str::random(32);
                    $data['expire_date'] = date('Y-m-d H:i:s', strtotime('+ ' . env('TOKEN_EXPIRED') . 'minute'));
                    $data['admin'] = $row->id;
                    AgentAccessToken:[REDACTED];
                    // 更新最後登入時間
                    $agent = Agent::find($row->id);
                    $agent_update = ['last_login' => date('Y-m-d H:i:s')];
                    if (isset($new_password)) {
                        $agent_update['password'] = $new_password;
                    }
                    $agent->update($agent_update);
                    $data = array();
                    $data['id'] = $agent['id'];
                    $data['username'] = $agent['username'];
                    $data['parent'] = $agent['parent'];
                    $data['code'] = $agent['code'];
                    $data['agent_backend_transaction'] = $agent['agent_backend_transaction'];
                    $data['balance'] = $agent['balance'];
                    $data['token'] = $token;
                    $result = array(
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $data
                    );
                    $status = 1;
                } else {
                    $result = array(
                        'code' => 9005,
                        'message' => __('error_message.9005')
                    );
                }
            } else {
                $result = array(
                    'code' => 9005,
                    'message' => __('error_message.9005')
                );
            }
            // 新增登入記錄
            $log = array();
            $log['agent_id'] = $id;
            $log['ip'] = $this->getIp();
            $log['status'] = $status;
            $log['admin'] = $id;
            AgentLoginLog::create($log);

            //清redis
            $redis = new REDIS();
            $redis->delete(array('agent_menus', $id));

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
            // 更新TOKEN到期時間
            $data['expire_date'] = date('Y-m-d H:i:s');
            AgentAccessToken:[REDACTED]'token', $request->TOKEN)->update($data);

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