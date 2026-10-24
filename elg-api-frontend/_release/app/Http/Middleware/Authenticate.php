<?php

namespace App\Http\Middleware;

use Illuminate\Support\Facades\Schema;
use App\Helpers\Common;
use Closure;
use DB;
use App\Models\Language;
use App;
use App\Models\MemberAccessToken;
use App\Models\Member;
use App\AES;
use App\REDIS;

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
            $redis_key = array(
                'api_post',
                $request->getRequestUri(),
                $request->key,
                $request->header('Authorization'),
                date('YmdHi') . substr(date('s'), 0, 1)
            );
            $redis = new REDIS();
            // 刪除Redis中相關的資料
//            $redis->delete(array(
//                date('YmdHi', strtotime('-1minute'))
//            ));
            $actions = ['getPrize']; //每次都重抓的API
            list($url, $action) = explode("api/", $request->getRequestUri());
            if (!$redis->exists($redis_key) || in_array($action, $actions)) {
                $user = array(
                    'id' => 0
                );
                $language = $request->header('Accept-Language'); // 語系
                // 設定語系
                if (App::getLocale() != $request->header('Accept-Language') && $request->header('Accept-Language') != '') {
                    App::setLocale($request->header('Accept-Language'));
                }
                // 1. 檢查Key是否正確
                $params = str_replace(' ', '+', $request->params);
                $params = str_replace('\\', '', $params);
                Common::log(0, $request->header(), $action . 'header');
                if ($request->key == md5($params . env('API_MD5_KEY'))) {
                    // 2. 檢查TOKEN
                    $token = [REDACTED]'Bearer ', '', $request->header('Authorization'));
                    $access_token = [REDACTED]'token', $token)->where('expire_date', '>', date('Y-m-d H:i:s'))->first();
                    if (is_object($access_token)) {
                        // 3. 檢查用戶是否為有效帳號(狀態為1或2), 0:為後台鎖定，1:正常帳號，2:密碼錯誤
                        $user = Member::where('id', $access_token->member_id)->first();
                        if (is_object($user) && $user->status == 1) {
                            // 刪除沒有在加密中的參數
                            foreach ($request->request as $key => $val) {
                                $request->request->remove($key);
                            }
                            // 5.解密param
                            $aes = new AES(env('API_AES_KEY'), env('API_IV'));
                            $param = json_decode($aes->decrypt($params), true);
                            if (is_array($param)) {
                                $request->merge($param);
                            }
                            // 加上通用資料
                            $device = Common::checkIsMobile();
                            $request->merge(array(
                                'MY_PARENT_AGENT' => $user->parent,
                                'ID' => $access_token->member_id,
                                'CODE' => 1000,
                                'TOKEN' => $token,
                                'LANGUAGE' => Common::getLanguageId($language),
                                'IS_MOBILE' => ($device > 0) ? true : false,
                                'IS_PAD' => ($device == 2) ? true : false
                            ));
                            $code = 1000;
                        } else if (is_object($user)) {
                            $code = 9998; //帳號不存在要登入，前端的TOKEN EROOR會登出，所以改編號
                            $message = __('error_message.90062');
                        } else {
                            $code = 9998; //帳號不存在要登入，前端的TOKEN EROOR會登出，所以改編號
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
                Common::log($user['id'], $request->all(), $action);
                if ($code == 1000) {
                    $response = $next($request);
                    $result = json_decode(json_encode($response, true), JSON_UNESCAPED_UNICODE);
                    $log = [
                        'request' => $request->all(),
                        'response' => $result
                    ];
                    Common::log($user['id'], $log, $action);
                } else {
                    $response = [
                        'code' => $code,
                        'message' => $message
                    ];
                    Common::log(0, $response, $action);
                    $response = response()->json($response);
                }
                //$redis->set($redis_key, $response);
            } else {
                $response = $redis->get($redis_key);
                $response = response()->json($response['original']);
                Common::log(0, $response, 'respons redis');
            }
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