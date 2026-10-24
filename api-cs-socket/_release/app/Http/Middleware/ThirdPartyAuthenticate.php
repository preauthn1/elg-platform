<?php

namespace App\Http\Middleware;

use Closure;
use DB;
use App;
use App\Helpers\Common;
use App\User;
use App\UserToken;
use App\Language;

class ThirdPartyAuthenticate
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
            $user_id = 0;
            list($url, $action) = explode("api/", $request->getRequestUri());
            $language = $request->header('Accept-Language');
            // 1. 檢查Key是否正確
            $params = str_replace(' ', '+', $request->params);
            $params = str_replace('\\', '', $params);
            Common::log(0, $request->header(), $action . 'header');
            if ($request->key == md5($params . env('THIRD_PARTY_MD5_KEY'))) {
                // 刪除沒有在加密中的參數
                foreach ($request->request as $key => $val) {
                    $request->request->remove($key);
                }
                // 2.解密param
                $decrypt = Common::decrypt(env('THIRD_PARTY_AES_KEY'), env('THIRD_PARTY_IV'), $params);
                $param = json_decode($decrypt, true);
                if (is_array($param)) {
                    $request->merge($param);
                }
                // 3. 檢查TOKEN
                $token = [REDACTED]'Bearer ', '', $request->header('Authorization'));
                if ($token != '') {
                    $access_token = [REDACTED]'token', $token)->where('expire_date', '>', date('Y-m-d H:i:s'))->first();
                    if (is_object($access_token)) {
                        $request->merge(array(
                            'ID' => $access_token->user_id
                        ));
                        $user_id = $access_token->user_id;
                    }
                }
                // 加上通用資料
                $request->merge(array(
                    'LANGUAGE' => Common::getLanguageId($language)
                ));
                $code = 1000;
            } else {
                $code = 9999;
                $message = __('error_message.9999');
            }
            // 設定語系
            if (App::getLocale() != $request->header('Accept-Language') && $request->header('Accept-Language') != '') {
                App::setLocale($request->header('Accept-Language'));
            }
            Common::log($user_id, $request->all(), $action);
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
            Common::log($user_id, $content, $action);
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