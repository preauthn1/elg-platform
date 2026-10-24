<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Schema;
use DB;
use App\Models\Language;
use Log;

class Common
{

    public static function encrypt($key, $iv, $data)
    {
        // openssl 實現的 AES 加密
        $cryptText = openssl_encrypt($data, "aes-256-cbc", md5($key), OPENSSL_RAW_DATA, $iv);
        return base64_encode($cryptText);
    }

    public static function decrypt($key, $iv, $data)
    {
        // openssl 實現的 AES 解密
        $cryptText = base64_decode($data);
        return trim(openssl_decrypt($cryptText, 'aes-256-cbc', md5($key), OPENSSL_RAW_DATA, $iv));
    }

    public static function decryptVendorInfo($row)
    {
        $key = md5($row->created_at);
        $iv = date('UYm', strtotime($row->created_at));
        $json = Common::decrypt($key, $iv, $row->info);
        return json_decode($json);
    }

    public static function parseVendorInfo($row)
    {
        if (json_decode($row->info)) {
            $json = $row->info;
        } else {
            $key = md5($row->created_at);
            $iv = date('UYm', strtotime($row->created_at));
            $json = Common::decrypt($key, $iv, $row->info);
        }
        return json_decode($json);
    }

    public static function getLanguageId($code)
    {
        $row = Language::where('code', $code)->where('status', 1)->first();
        return is_object($row) ? $row->id : 1;
    }

    // 判斷是否為行動裝置
    public static function checkIsMobile()
    {
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            //Detect special conditions devices
            $iPod = stripos($_SERVER['HTTP_USER_AGENT'], "iPod");
            $iPhone = stripos($_SERVER['HTTP_USER_AGENT'], "iPhone");
            $iPad = stripos($_SERVER['HTTP_USER_AGENT'], "iPad");
            if (stripos($_SERVER['HTTP_USER_AGENT'], "Android") && stripos($_SERVER['HTTP_USER_AGENT'], "mobile")) {
                $Android = true;
                $AndroidTablet = false;
            } else if (stripos($_SERVER['HTTP_USER_AGENT'], "Android")) {
                $Android = false;
                $AndroidTablet = true;
            } else {
                $Android = false;
                $AndroidTablet = false;
            }
            $webOS = stripos($_SERVER['HTTP_USER_AGENT'], "webOS");
            $BlackBerry = stripos($_SERVER['HTTP_USER_AGENT'], "BlackBerry");
            $RimTablet = stripos($_SERVER['HTTP_USER_AGENT'], "RIM Tablet");
            //do something with this information
            if ($iPad || $AndroidTablet || $RimTablet){
                return 2;
            } else if ($iPod || $iPhone || $Android || $webOS || $BlackBerry)  {
                return 1;
            } else {
                return 0;
            }
        } else {
            return 0;
        }
    }

    public static function getIp()
    {
        if (isset($_SERVER["HTTP_ALI_CDN_REAL_IP"])) {
            $ip = $_SERVER["HTTP_ALI_CDN_REAL_IP"];
        } else if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
            $ip = $_SERVER["HTTP_CF_CONNECTING_IP"];
        } else if (!empty(request()->header('V-HTTP-CLIENT-IP'))) {
            $ip = request()->header('V-HTTP-CLIENT-IP');
        } elseif (!empty($_SERVER["HTTP_CLIENT_IP"])) {
            $ip = $_SERVER["HTTP_CLIENT_IP"];
        } elseif (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
        } else {
            $ip = $_SERVER["REMOTE_ADDR"];
        }
        return $ip;
    }

    public static function log($userId, $content, $action) {
        // 刪除PASSWORD相關的KEY
        if (is_array($content)) {
            $content = collect($content)->map(function ($value, $key) {
                if(is_array($value) && isset($value['password'])) {
                    $value['password'] = '******';
                }
                return $value;
            })->all();
        }
        $ip = Common::getIp();
        $context = [
            'userID' => $userId,
            'content' => $content,
            'action' => $action,
            'ip'=>$ip
        ];
        try {
            $action = is_array($context['action'])?json_encode($action):$action;

            $message = sprintf("user[%d] access %s from %s",
                $context['userID'],
                $action,
                $context['ip']);
            Log::info($message, $context);
        } catch (Exception $e) {
            Log::error($e->getMessage(), $context);
        }
    }
    public static function log_old($userId, $content, $action)
    {
        //Common::log2($userId, $content, $action);
        $table_name = 'access_log_frontend_' . date('Ymd');
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

    public static function postToSocket($url, $data)
    {
        //將資料加密
        $cryptText = openssl_encrypt(json_encode($data), "aes-256-cbc", md5(env('THIRD_PARTY_AES_KEY')), OPENSSL_RAW_DATA, env('THIRD_PARTY_IV'));
        $params =  base64_encode($cryptText);
        $key = md5($params . env('THIRD_PARTY_MD5_KEY'));
        $data = [
            'params' => $params,
            'key' => $key
        ];
        //呼叫客服api
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('CS_API_URL') . $url);
        Common::log(0, env('CS_API_URL') . $url, $url . '_post');
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        Common::log(0, $result, $url . '_post');
        curl_close($ch);
        return json_decode($result, true);
    }

    public static function newlog($action, $url, $method, $parameters, $statusCode, $result)
    {
        $context = [
            'request' => [
                'url' => $url,
                'method' => $method,
                'parameters' => $parameters,
            ],
            'response' => [
                'statusCode' => $statusCode,
                'body' => $result,
            ]
        ];
        
        try {
            $message = $action;
            Log::info($message, $context);
        } catch (Exception $e) {
            Log::error($e->getMessage(), $context);
        }
    }
}