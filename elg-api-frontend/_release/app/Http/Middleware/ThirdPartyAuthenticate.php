<?php

namespace App\Http\Middleware;

use Illuminate\Support\Facades\Schema;
use Closure;
use DB;
use App\Models\Language;
use App;
use App\Models\MemberAccessToken;
use App\Models\Member;
use App\Models\Agent;
use App\AES;
use App\REDIS;
use App\Helpers\Common;

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
            $this->log(0, $request->header(), $action . 'header');
            if ($request->key == md5($params . env('THIRD_PARTY_MD5_KEY'))) {
                // 刪除沒有在加密中的參數
                foreach ($request->request as $key => $val) {
                    $request->request->remove($key);
                }
                // 2.解密param
                $aes = new AES(env('THIRD_PARTY_AES_KEY'), env('THIRD_PARTY_IV'));
                $param = json_decode($aes->decrypt($params), true);
                if (is_array($param)) {
                    $request->merge($param);
                }
                $this->log($user_id, $request->all(), $action);
                // 3. 檢查用戶是否為有效帳號(狀態為0或1)
                if (isset($request->username)) {
                    $array = [
                        1
                    ];
                    $user = Member::where('username', $request->username)->whereIn('status', $array)->first();
                    if (is_object($user)) {
                        $user_id = $user->id;
                        // 加上通用資料
                        $request->merge(array(
                            'ID' => $user->id,
                            'CODE' => 1000,
                            'LANGUAGE' => $this->getLanguageId($language)
                        ));
                        $code = 1000;
                    } else {
                        $code = 1005;
                        $message = __('error_message.1005');
                    }
                } else if (isset($request->from)) {
                    $agent = Agent::where('username', $request->from)->first();
                    if (is_object($agent)) {
                        // 加上通用資料
                        $request->merge(array(
                            'AGENT_ID' => isset($agent->id) ? $agent->id : 0,
                            'CODE' => 1000,
                            'LANGUAGE' => $this->getLanguageId($language)
                        ));
                        $code = 1000;
                    } else {
                        $code = 10051;
                        $message = __('error_message.1005');
                    }
                }
            } else {
                $code = 9999;
                $message = __('error_message.9999');
            }
            // 設定語系
            if (App::getLocale() != $request->header('Accept-Language') && $request->header('Accept-Language') != '') {
                App::setLocale($request->header('Accept-Language'));
            }
            if ($code == 1000) {
                $response = $next($request);
                $result = json_decode(json_encode($response, true), JSON_UNESCAPED_UNICODE);
                $log = [
                    'request' => $request->all(),
                    'response' => $result
                ];
                $this->log($user_id, $log, $action);
            } else {
                $response = [
                    'code' => $code,
                    'message' => $message
                ];
                $this->log(0, $response, $action);
                $response = response()->json($response);
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

    private function getLanguageId($code)
    {
        $row = Language::where('code', $code)->where('status', 1)->first();
        return is_object($row) ? $row->id : 1;
    }

    private function log($userId, $content, $action)
    {
        $table_name = 'access_log_third_party_' . date('Ymd');
        // 如果LOG表不存在就建立
        if (!Schema::connection("log")->hasTable($table_name)) {
            DB::connection("log")->statement("
            CREATE TABLE IF NOT EXISTS `$table_name` (
                `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號',
                `content` longtext NOT NULL COMMENT '內容',
                `user` int(11) NOT NULL DEFAULT '0' COMMENT '操作者',
                `action` varchar(50) NOT NULL COMMENT '動作',
                `ip` varchar(255) NOT NULL COMMENT 'IP',
                `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
                PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT;");
        }
        if (is_array($content)) {
            // 刪除PASSWORD相關的KEY
            $del_value = 'password';
            while (($key = array_search($del_value, $content)) !== false) {
                unset($content[$key]);
            }
            $content = json_encode($content, JSON_UNESCAPED_UNICODE);
        }
        DB::connection("log")->table($table_name)->insert(array(
            'user' => $userId,
            'content' => substr($content, 0, 500000),
            'action' => $action,
            'ip' => Common::getIp()
        ));
    }
}