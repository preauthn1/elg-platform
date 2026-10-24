<?php

namespace App\Http\Middleware;

use Illuminate\Support\Facades\Schema;
use Closure;
use DB;
use App;
use App\Helpers\Common;
use App\AES;
use App\Language;

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
            if (App::getLocale() != $language && $language != '') {
                App::setLocale($language);
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
                return $response;
            } else {
                $array = [
                    'code' => $code,
                    'message' => $message
                ];
                Common::log(0, $array, $action);
                return response()->json($array);
            }
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