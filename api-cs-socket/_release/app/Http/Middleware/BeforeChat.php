<?php

namespace App\Http\Middleware;

use Closure;
use DB;
use App;
use App\Helpers\Common;
use App\User;
use App\UserToken;
use App\Member;
use App\Friend;
use App\Language;
use App\ThirdParty;

class BeforeChat
{

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        try {
            $my_id = 0;
            list($url, $action) = explode("api/", $request->getRequestUri());
            $language = $request->header('Accept-Language');
            $sign = $request->header('Accept-Language');
            // 1. 檢查Key是否正確
            $params = str_replace(' ', '+', $request->params);
            $params = str_replace('\\', '', $params);
            if ($request->key == md5($params . env('API_MD5_KEY', 'k^QtGkDg7FXJ#@QC'))) {
                // 2.刪除沒有在加密中的參數
                foreach ($request->request as $key => $val) {
                    $request->request->remove($key);
                }
                // 3.解密param
                $decrypt = Common::decrypt(env('API_AES_KEY', 'mu5*rwp5j%3U4kZG'), env('API_IV', '9240533016891799'), $params);
                $param = json_decode($decrypt, true);
                if (is_array($param)) {
                    $request->merge($param);
                }
                // 4. 檢查Sign OR Token
                $is_client = 0;
                $user_id = 0;
                // 4.1 檢查TOKEN
                if (strpos($request->header('Authorization'), 'Bearer ') !== false) {
                    $token = [REDACTED]'Bearer ', '', $request->header('Authorization'));
                    $access_token = [REDACTED]'token', $token)->where('expire_date', '>', date('Y-m-d H:i:s'))->first();
                    if (is_object($access_token)) {
                        // 3. 檢查用戶是否為有效帳號(狀態為1)
                        $user = User::where('id', $access_token->user_id)->where('status', 1)->first();
                        if (is_object($user)) {
                            $user_id = $user->id;
                            if ($user->role_id == 1) {
                                if ($user->host_id != 0) {
                                    $agent_id = $user->host_id;
                                } else {
                                    $agent_id = $user->id;
                                }
                            } else if ($user->role_id == 3) {
                                $agent_id = $user->parent_id;
                            } else {
                                $agent_id = $user->id;
                            }
                            $agent_cs = Member::where('third_party_id', 0)->where('user_id', $agent_id)->first();
                            if (is_object($agent_cs)) {
                                $my_id = $agent_cs->id;
                            } else {
                                $code = 1101;
                                $message = __('error_message.1101');
                            }
                            $code = 1000;
                        } else {
                            $code = 1005;
                            $message = __('error_message.1005');
                        }
                    } else {
                        $code = 9998;
                        $message = __('error_message.9998');
                    }
                } else {
                    $is_client = 1;
                    // 4.2 檢查Sign
                    $sign = str_replace('Signature ', '', $request->header('Authorization'));
                    $user = User::where('API_USER', $request->API_USER)->where('API_KEY', $request->API_KEY)
                        ->where('status', 1)
                        ->first();
                    if (is_object($user)) {
                        $agent_id = $user->id;
                        $encrypt = md5($request->API_USER . env('API_MD5_KEY') . $request->API_KEY . env('API_IV') . $request->TIMESTAMP . env('API_AES_KEY') . $request->THIRD_PARTY_ID);
                        if ($sign == $encrypt) {
                            // 5. 檢查用戶是否有帳號
                            $member = Member::where('third_party_id', $request->THIRD_PARTY_ID)->where('user_id', $agent_id)->first();
                            if (!is_object($member)) {
                                $data = array(
                                    'user_id' => $agent_id,
                                    'third_party_id' => $request->THIRD_PARTY_ID,
                                    'username' => $request->THIRD_PARTY_ID,
                                    'status' => 1
                                );
                                // 還不是用戶時建立用戶
                                if ($rs = Member::create($data)) {
                                    //更新用戶資料
                                    $third_party = new ThirdParty($agent_id);
                                    $data = $third_party->getInfo($rs->id);
                                    if ($data != -1) {
                                        $update_date = array();
                                        $update_date['nickname'] = $data['nickname'];
                                        $update_date['thumbnail'] = $data['image'];
                                        if (isset($data['device_id']) && $data['device_id'] != '') {
                                            //同組 Device token 允許一位會員及一位代理帳號同時擁有
                                            Member::where('device_id', $request->device_id)->where('third_party_id', '!=', '0')->update(['device_id' => null]);
                                            $update_date['device_id'] = $data['device_id'];
                                        }
                                        Member::where('id', $rs->id)->update($update_date);
                                    }
                                    $customer = Member::where('user_id', $agent_id)->where('third_party_id', 0)->first();
                                    $my_id = $rs->id;
                                    // 加入客服帳號為朋友
                                    $data = array(
                                        'self_id' => $my_id,
                                        'other_id' => $customer->id,
                                        'status' => 1
                                    );
                                    Friend::create($data);
                                    // 客服帳號加入新帳號為好友
                                    $data = array(
                                        'self_id' => $customer->id,
                                        'other_id' => $my_id,
                                        'status' => 1
                                    );
                                    Friend::create($data);
                                    $top_agent_id =  $this->getTopAgent($agent_id);
                                    $top_agent_customer = Member::where('user_id', $top_agent_id)->where('third_party_id', 0)->first();
                                    //如果是同一個客服就不重覆加入了
                                    if ($customer->id != $top_agent_customer->id) {
                                        // TODO 先寫死，加入平台管理員為好友
                                        $data = array(
                                            'self_id' => $my_id,
                                            'other_id' => $top_agent_customer->id,
                                            'status' => 1
                                        );
                                        Friend::create($data);
                                        // 平台管理員為好友加入新帳號為好友
                                        $data = array(
                                            'self_id' => $top_agent_customer->id,
                                            'other_id' => $my_id,
                                            'status' => 1
                                        );
                                        Friend::create($data);
                                    }
                                } else {
                                    // 帳號建立失敗
                                    $code = 2001;
                                    $message = __('error_message.2001');
                                }
                            } else if ($action == 'friend/list' || $action == 'member/self') {
                                $my_id = $member->id;
                                //更新用戶資料
                                $third_party = new ThirdParty($agent_id);
                                $data = $third_party->getInfo($my_id);
                                if ($data != -1) {
                                    $member_update = ['nickname' => $data['nickname'], 'thumbnail' => $data['image']];
                                    if ($data['device_id'] != '') {
                                        $member_update['device_id'] = $data['device_id'];
                                    }
                                    Member::where('id', $my_id)->update($member_update);
                                }
                            } else {
                                $my_id = $member->id;
                            }
                            $code = 1000;
                        } else {
                            $code = 9998;
                            $message = __('error_message.9998');
                        }
                    } else {
                        // 查無此營運商
                        $code = 9996;
                        $message = __('error_message.9996');
                    }
                }
                if ($code == 1000) {
                    // 加上通用資料
                    $request->merge(array(
                        'ID' => $my_id,
                        'AGENT_ID' => $agent_id,
                        'USER_ID' => $user_id,
                        'IS_CLIENT' => $is_client,
                        'CODE' => 1000,
                        'LANGUAGE' => Common::getLanguageId($language)
                    ));
                }
            } else {
                // KEY驗證錯誤
                $code = 9999;
                $message = __('error_message.9999');
            }
            // 設定語系
            if (App::getLocale() != $request->header('Accept-Language') && $request->header('Accept-Language') != '') {
                App::setLocale($request->header('Accept-Language'));
            }
            Common::log($my_id, $request->all(), $action);
            if ($code == 1000) {
                $response = $next($request);
                $result = json_decode(json_encode($response, JSON_UNESCAPED_UNICODE), true);
                $content = [
                    'request' => $request->all(),
                    'response' => $result
                ];
            } else {
                $content = [
                    'code' => $code,
                    'message' => $message
                ];
                $response = response()->json($content);
            }
            Common::log($my_id, $content, $action);
            // Common::log($my_id, $response, $action);
            return $response;
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

    private function getTopAgent($agentId)
    {
        static $top_agent_id = 0;
        //子帳號的資料也同時查出來
        $user = User::where('id', $agentId)->first();
        if (is_object($user)) {
            if ($user->role_id != 1) {
                //如果不是最上層的帳號，就繼續找
                $this->getTopAgent($user->parent_id);
            } else {
                //如果是最上層的帳號
                $top_agent_id =  $user->id;
            }
        }
        return $top_agent_id;
    }
}