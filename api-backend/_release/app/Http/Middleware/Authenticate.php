<?php

namespace App\Http\Middleware;

use Closure;
use App\Language;
use App;
use App\Helpers\Common;
use App\UserAccessToken;
use App\User;
use App\AES;
use App\UserMenu;
use DB;

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
            $params = str_replace('\\', '', $request->params);
            if ($request->key == md5($params . env('API_MD5_KEY'))) {
                // 2. 檢查TOKEN
                $token = [REDACTED]'Bearer ', '', $request->header('Authorization'));
                $access_token = [REDACTED]'token', $token)->where('expire_date', '>', date('Y-m-d H:i:s'))->first();
                if (is_object($access_token)) {
                    // 3. 檢查用戶是否啓用
                    $user = User::where('id', $access_token->user_id)->where('status', 1)->first();
                    if (is_object($user)) {
                        // 刪除沒有在加密中的參數
                        foreach ($request->request as $key => $val) {
                            $request->request->remove($key);
                        }
                        // TODO 4. 檢查用戶是否有權限
                        // 5.解密param
                        $aes = new AES(env('API_AES_KEY'), env('API_IV'));
                        $param = json_decode($aes->decrypt($params), true);
                        if (is_array($param)) {
                            $request->merge($param);
                        }
                        // 加上通用資料
                        $request->merge(array(
                            'ID' => $access_token->user_id,
                            'CODE' => 1000,
                            'TOKEN' => $token,
                            'LANGUAGE' =>  Common::getLanguageId($language)
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
                $log = [
                    'request' => $request->all(),
                    'response' => $result
                ];
                Common::log($user['id'], $log, $action);
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

    private function checkPermission($userId, $action)
    {
        UserMenu::leftJoin('user_permissions as p', 'p.menu_id', '=', 'user_menus.id')->where('user_id', $userId)
            ->where('url', $action)
            ->select('user_menus.id', 'user_menus.parent', 'user_menus.code', 'user_menus.name', 'user_menus.url', 'user_menus.symbol', 'user_permissions.action')
            ->get();
    }
}