<?php

namespace App\Helpers;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use DB;
use App\Language;

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

    public static function getLanguageId($code)
    {
        $row = Language::where('code', $code)->where('status', 1)->first();
        return is_object($row) ? $row->id : 1;
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

    public static function log($userId, $content, $action)
    {
        $table_name = 'access_log_' . date('Ymd');
        Common::createLog($table_name, $userId, $content, $action);
    }

    public static function cron_log($userId, $content, $action)
    {
        $table_name = 'cron_log_' . date('Ymd');
        Common::createLog($table_name, $userId, $content, $action);
    }

    private static function createLog($table_name, $userId, $content, $action)
    {
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
            'ip' => '-'
        ));
    }
    public static function uploadBase64File($file, $is_public = true)
    {
        try {
            $file = str_replace(' ', '+', $file);
            // 在本地寫入檔案
            $destinationPath = base_path() . '/storage/uploads/';
            $pos = strpos($file, ';');
            $type = explode(':', substr($file, 0, $pos))[1];
            $mime = explode('/', $type);

            $fileName = (date('U') . rand(1, 100)) . '.' . $mime[1];
            $path = $destinationPath . $fileName;
            $file = substr($file, strpos($file, ',') + 1, strlen($file));
            $dataBase64 = base64_decode($file);
            file_put_contents($path, $dataBase64);

            // 上傳圖檔到GOOGLE CLOUD STORAGE
            $storageClient = new StorageClient([
                'projectId' => env('GOOGLE_CLOUD_PROJECT_ID', 'your-project-id'),
                'keyFilePath' => env('GOOGLE_CLOUD_KEY_FILE', null)
            ]);
            $bucket = $storageClient->bucket(env('GOOGLE_CLOUD_STORAGE_BUCKET', 'your-bucket-name'));
            $adapter = new GoogleStorageAdapter($storageClient, $bucket);
            $filesystem = new Filesystem($adapter);
            // 公開的直接存網址，非公開的存檔名需要檢視時再取
            if ($is_public) {
                $filesystem->write($fileName, fopen($path, 'r'), array(
                    'visibility' => 'public'
                ));
                $fileName = $adapter->getUrl($fileName);
            } else {
                $filesystem->write($fileName, fopen($path, 'r'), array(
                    'visibility' => 'private'
                ));
            }
            return array(
                'code' => 1000,
                'message' => $fileName
            );
        } catch (\Exception $e) {
            return array(
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            );
        }
    }
}