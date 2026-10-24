<?php

namespace App\Http\Middleware;

use Closure;
use DB;
use App;
use App\Helpers\Common;
use App\User;
use App\UserToken;
use App\Language;

class Authenticate
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
            $user = array(
                'id' => 0
            );
            list($url, $action) = explode("api/", $request->getRequestUri());
            $language = $request->header('Accept-Language');
            // 1. 檢查Key是否正確
            $params = str_replace(' ', '+', $request->params);
            $params = str_replace('\\', '', $params);
            Common::log(0, $request->header(), $action . 'header');
            if ($request->key == md5($params . env('API_MD5_KEY'))) {
                // 2. 檢查TOKEN
                $token = [REDACTED]'Bearer ', '', $request->header('Authorization'));
                $access_token = [REDACTED]'token', $token)->where('expire_date', '>', date('Y-m-d H:i:s'))->first();
                if (is_object($access_token)) {
                    // 3. 檢查用戶是否為有效帳號(狀態為1)
                    $user = User::where('id', $access_token->user_id)->where('status', 1)->first();
                    if (is_object($user)) {
                        // 刪除沒有在加密中的參數
                        foreach ($request->request as $key => $val) {
                            $request->request->remove($key);
                        }
                        // 5.解密param
                        $decrypt = Common::decrypt(env('API_AES_KEY'), env('API_IV'), $params);
                        $param = json_decode($decrypt, true);
                        if (is_array($param)) {
                            $request->merge($param);
                        }
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
                        // 加上通用資料
                        $request->merge(array(
                            'ID' => $access_token->user_id,
                            'ROLE' => $user->role_id,
                            'HOST' => $user->host_id,
                            'AGENT_ID' => $agent_id,
                            'CODE' => 1000,
                            'TOKEN' => $token,
                            'LANGUAGE' => Common::getLanguageId($language)
                        ));
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
                $code = 9999;
                $message = __('error_message.9999');
            }
            // 設定語系
            if (App::getLocale() != $request->header('Accept-Language') && $request->header('Accept-Language') != '') {
                App::setLocale($request->header('Accept-Language'));
            }
            Common::log($user['id'], $request->all(), $action);
            if ($code == 1000) {
                $response = $next($request);
                $result = json_decode(json_encode($response, true), JSON_UNESCAPED_UNICODE);
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
            Common::log($user['id'], $content, $action);
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
}