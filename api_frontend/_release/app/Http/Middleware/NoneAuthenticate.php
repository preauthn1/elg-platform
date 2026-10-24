<?php

namespace App\Http\Middleware;

use Closure;
use DB;
use App;
use App\Helpers\Common;
use App\AES;
use App\Models\MemberAccessToken;
use App\REDIS;

class NoneAuthenticate
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

            $black_list = ['0.0.0.0', '0.0.0.0'];
            $ips = Common::getIp();
            $ip = explode(',', $ips);
            if (in_array(trim($ip[0]), $black_list)) {
                return abort(404);
            }

            $redis_key = array(
                'api_post',
                $request->getRequestUri(),
                $request->key,
                $request->header('Authorization'),
                date('YmdHi') . substr(date('s'), 0, 1)
            );
            $redis = new REDIS();
            // 刪除Redis中相關的資料
//            $affects = $redis->delete(array(
//                date('YmdHi', strtotime('-1minute'))
//            ));
//            Common::log(0, $request->all(), "NoneAuthenticate delete affects " . $affects);

            if (!$redis->exists($redis_key)) {
                $language = $request->header('Accept-Language');
                // 1. 檢查Key是否正確
                $params = str_replace(' ', '+', $request->params);
                $params = str_replace('\\', '', $params);
                if ($request->key == md5($params . env('API_MD5_KEY'))) {
                    // 刪除沒有在加密中的參數
                    foreach ($request->request as $key => $val) {
                        $request->request->remove($key);
                    }
                    // 2.解密param
                    $aes = new AES(env('API_AES_KEY'), env('API_IV'));
                    $param = json_decode($aes->decrypt($params), true);
                    if (is_array($param)) {
                        $request->merge($param);
                    }
                    // 3. 檢查TOKEN
                    $token = [REDACTED]'Bearer ', '', $request->header('Authorization'));
                    if ($token != '') {
                        $access_token = [REDACTED]'token', $token)->where('expire_date', '>', date('Y-m-d H:i:s'))->first();
                        if (is_object($access_token)) {
                            $request->merge(array(
                                'ID' => $access_token->member_id
                            ));
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
                list($url, $action) = explode("api/", $request->getRequestUri());
                Common::log(0, $request->all(), $action);
                if ($code == 1000) {
                    $response = $next($request);
                    $result = json_decode(json_encode($response, true), JSON_UNESCAPED_UNICODE);
                    $log = [
                        'request' => $request->all(),
                        'response' => $result
                    ];
                    Common::log(0, $log, $action);
                } else {
                    $response = [
                        'code' => $code,
                        'message' => $message
                    ];
                    Common::log(0, $response, $action);
                    $response = response()->json($response);
                }
                //$redis->set($redis_key, $response);
                //$redis->expire($redis_key, 60);
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