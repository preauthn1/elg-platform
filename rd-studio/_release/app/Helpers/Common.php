<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Schema;
use DB;
use App\Models\Language;
use Log;

class Common
{

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

    public static function decryptVendorInfo($row)
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
        $ip = '0.0.0.0';
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

    public static function log_old($user = 0, $content, $action)
    {
        Common::log2($user, $content, $action);
        $table_name = 'access_log_cron_' . date('Ymd');
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
            'user' => $user,
            'content' => substr($content, 0, 500000),
            'action' => $action,
            'ip' => '-'
        ));
    }

    //檢查遊戲商是否維護
    public static function check_vendor_maintenances($vendor_id)
    {
        $result = false;
        $model = DB::table('vendor_maintenances')
            ->where('vendor_id', $vendor_id)
            ->where('status', 1)
            ->where('start_time', '<=', date('Y-m-d H:i:s'))
            ->where('end_time', '>=', date('Y-m-d H:i:s'))
            ->orderBy('end_time')
            ->select('type')
            ->first();
        if (is_object($model)) {
            $result = true;
        }
        return $result;
    }

    public static  function isWindows() {
        if(strcasecmp(substr(PHP_OS, 0, 3), 'WIN') == 0){
            return true;
        }
        return false;
    }

}