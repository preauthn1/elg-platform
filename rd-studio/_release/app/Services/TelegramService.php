<?php

namespace App\Services;

class TelegramService
{
    protected $url;
    protected $token;
	
	public function __construct()
    {
        $this->token = [REDACTED]'TG_TOKEN');
        $this->url = 'https://example.invalid/bot' . $this->token . '/sendmessage';
    }

    public function sendMessage($message, $channel = 'error', $markdown = '', $isUrlencode = false)
    {
        try {
            $chat_ids = explode(
                ',',
                env('TG_' . strtoupper($channel) . '_CHAT_ID', env('TG_ERROR_CHAT_ID', -407577911))
            );

            $message = '[' . env('APP_NAME') . ']' . $message;
            if (is_array($chat_ids)) {
                foreach($chat_ids as $chat_id) {
                    if ($isUrlencode == true) {
                        $url = $this->url . '?chat_id=' . $chat_id . '&text=' . urlencode($message);
                    } else {
                        $url = $this->url . '?chat_id=' . $chat_id . '&text=' . $message;
                    }
                    if ($markdown != '') {
                        $url .= "&parse_mode=" . $markdown;
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
        } catch (\Exception $e) {
        }
    }

    public function sendMessageByChatId($message, $channel = -407577911, $markdown = '', $isUrlencode = false)
    {
        try {
            $chat_ids = explode(',', $channel);

            $message = '[' . env('APP_NAME') . ']' . $message;
            if (is_array($chat_ids)) {
                foreach($chat_ids as $chat_id) {
                    if ($isUrlencode == true) {
                        $url = $this->url . '?chat_id=' . $chat_id . '&text=' . urlencode($message);
                    } else {
                        $url = $this->url . '?chat_id=' . $chat_id . '&text=' . $message;
                    }
                    if ($markdown != '') {
                        $url .= "&parse_mode=" . $markdown;
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
        } catch (\Exception $e) {
        }
    }
}
