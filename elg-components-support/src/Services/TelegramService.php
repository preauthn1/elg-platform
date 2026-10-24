<?php

namespace target\support\Services;

class TelegramService
{
    protected $token;
    protected $url;

    public function __construct()
    {
        $this->token = [REDACTED]'TG_TOKEN');
        $this->url = 'https://example.invalid/bot' . $this->token . '/sendmessage';
    }

    public function sendMessage($message, $channel = 'error', $markdown = '')
    {
        $message = "[" . env('APP_NAME') . "]" . PHP_EOL . $message;
        $chat_id = env('TG_' . strtoupper($channel) . '_CHAT_ID', env('TG_ERROR_CHAT_ID', -407577911));
        $url = $this->url . '?chat_id=' . $chat_id . '&text=' . urlencode($message);
        if ($markdown != '') {
            $url .= '&parse_mode=' . $markdown;
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, 5);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1); // 不直接出現回傳值
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }
}
