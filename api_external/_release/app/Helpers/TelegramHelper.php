<?php

namespace App\Helpers;

class TelegramHelper
{
   
    public static function sendMessage($message, $markdown = '', $channel = '')
    {
        $message = "[" . env('APP_NAME') . "]" . PHP_EOL . $message;
        $target_env_name = 'TG_' . strtoupper($channel) . '_CHAT_ID';
        $chat_id = env($target_env_name, env('TG_CHAT_ID', -407577911));

        $url = self::getApiUrl() . '?chat_id=' . $chat_id . '&text=' . urlencode($message);

        if ($markdown != '') {
            $url .= '&parse_mode=' . $markdown;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER , 1); // 不直接出現回傳值
        $output = curl_exec($ch);
        curl_close($ch);
    }

    private static function getApiUrl()
    {
        return 'https://example.invalid/bot' . env('TG_TOKEN') . '/sendmessage';
    }

}