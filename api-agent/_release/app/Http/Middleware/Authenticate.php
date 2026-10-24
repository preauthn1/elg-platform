<?php

namespace App\Http\Middleware;

use Closure;
use DB;
use App;
use App\Helpers\Common;
use App\AgentAccessToken;
use App\Agent;
use App\AgentMenu;
use App\AES;

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
            // 設定語系
            if (App::getLocale() != $language && $language != '') {
                App::setLocale($language);
            }
            // 1. 檢查Key是否正確
            $params = str_replace(' ', '+', $request->params);
            $params = str_replace('\\', '', $params);
            if ($request->key == md5($params . env('API_MD5_KEY'))) {
                // 2. 檢查TOKEN
                $token = [REDACTED]'Bearer ', '', $request->header('Authorization'));
                $access_token = [REDACTED]'token', $token)->where('expire_date', '>', date('Y-m-d H:i:s'))->first();
                if (is_object($access_token)) {
                    // 3. 檢查用戶是否啓用
                    $user = Agent::where('id', $access_token->agent_id)->where('status', 1)->first();
                    if (is_object($user)) {
                        // 刪除沒有在加密中的參數
                        foreach ($request->request as $key => $val) {
                            $request->request->remove($key);
                        }
                        // TODO 4. 檢查用戶是否有權限
                        // $this->hasPermission($request->path(), $access_token->agent_id);
                        // 5.解密param
                        $aes = new AES(env('API_AES_KEY'), env('API_IV'));
                        $param = json_decode($aes->decrypt($params), true);
                        if (is_array($param)) {
                            $request->merge($param);
                        }
                        // 加上通用資料
                        $request->merge(array(
                            'ID' => $access_token->agent_id,
                            'HOST' => ($user->host_id != 0) ? $user->host_id : $user->id, //主要的帳號，如果是HOST ID不是0就是子帳號，他主要的帳號為HOST ID，如果是0，他主要的帳號是他自己
                            'PARENT' => $user->parent,
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

    private function hasPermission($path, $id)
    {
        list($route, $method, $action) = explode($path, '/');
        $row = AgentMenu::leftJoin('agent_permissions', 'agent_permissions.agent_menu_id', '=', 'agent_menus.id')->where('agent_id', $id)
            ->where('agent_menus.code', $method)
            ->where('agent_permissions.action', 'like', '*' . $action . '*')
            ->first();
        return is_object($row) ? true : false;
    }
}