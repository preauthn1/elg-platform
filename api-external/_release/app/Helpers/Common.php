<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Schema;
use DB;
use App\Language;
use Log;

class Common
{
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
    public static function log_old($content, $action)
    {
        if(is_array($action)) {
            $action = json_encode($action);
        }
        Common::log2(0, $content, $action);
        $table_name = 'access_log_external_' . date('Ymd');
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
            'user' => 0,
            'content' => $content,
            'action' => $action,
            'ip' => Common::getIp()
        ));
    }

    public static function getIp()
    {
        if (!empty($_SERVER["HTTP_CLIENT_IP"])) {
            $ip = $_SERVER["HTTP_CLIENT_IP"];
        } elseif (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
        } else {
            $ip = $_SERVER["REMOTE_ADDR"];
        }
        return $ip;
    }
}