<?php

namespace App\Services;

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
        $message = "[" . env('APP_NAME') . "]" . PHP_EOL .
            "[url]" . url()->full() . PHP_EOL . $message;
        $chat_id = env('TG_' . strtoupper($channel) . '_CHAT_ID', env('TG_ERROR_CHAT_ID', -407577911));
        $url = $this->url . '?chat_id=' . $chat_id . '&text=' . urlencode($message);
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
}
