<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Schema;
use DB;
use App\Models\Language;
use Log;

class Common
{

    /* 將IP位址，排除掉GCP的機器IP */
    public static function ParseIPAddressAndFilterGooglePlatformIPAddress($ipAddressText)
    {
        $split = explode(',' , $ipAddressText);

        $newArray = array();

        foreach ($split as $eachIP) {

            //排除掉GCP或nginx層的IP
            if(!str_contains($eachIP, '192.230.120.') && 
               !str_contains($eachIP, '0.0.0.0'))
            {
                array_push( $newArray , $eachIP);
            }
        }

        return implode (", ", $newArray);
    }

    /* 是否 非空字串、非NULL、非0值 */
    public static function IsNotNullOrEmptyStringNotZeroValue($str)
    {
        return !Common::IsNullOrEmptyString($str) && $str != "0";
    }

    /* 是否 [非空字串]、[非NULL] */
    public static function IsNotNullOrEmptyString($str)
    {
        return !Common::IsNullOrEmptyString($str);
    }

    /* 是否 [空字串] 或 [NULL] */
    public static function IsNullOrEmptyString($str)
    {
        return (!isset($str) || trim($str) === '');
    }

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

    public static function getLanguageId($code)
    {
        $row = Language::where('code', $code)->where('status', 1)->first();
        return is_object($row) ? $row->id : 1;
    }

    public static function getIp()
    { 
        if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
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
        $table_name = 'access_log_agent_' . date('Ymd');
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
            'content' => substr($content, 0, 4294967295),
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


    public static function postToSocketWihtoutEncrpt($url, $data)
    {        
        //呼叫客服api (無加密版)
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

    public static function debugSQL($model) {
        $bindings = $model->getBindings();
        $prepare_sql = str_replace('?', '%s', $model->toSql());
        $sql = sprintf($prepare_sql, ...$bindings);
        return $sql;
    }
}