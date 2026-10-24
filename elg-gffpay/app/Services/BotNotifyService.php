<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BotNotifyService
{
    public string $channel = 'debug';

    public function channel(string $channel): BotNotifyService
    {
        $this->channel = $channel;

        return $this;
    }

    public function send(string $message, array $context = []): void
    {
        $name = config('app.name');
        $env = config('app.env');
        $message = sprintf('[%s-%s] %s', $name, $env, $message);

        if ($context) {
            $message .= PHP_EOL . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        $driver = config('bot-notify.default');

        switch ($driver) {
            case 'telegram':
                $this->telegram($message);
                break;

            default:
                Log::error('BotNotifyService ' . $driver . ' not found');
                break;
        }
    }

    public function telegram(string $message, string $chatId = ''): void
    {
        $chatId = $chatId ?: config('bot-notify.channel.' . $this->channel);
        $token = [REDACTED]'bot-notify.driver.telegram.token');

        if (empty($chatId) || empty($token)) {
            return;
        }

        $url = 'https://example.invalid/bot' . $token . '/sendMessage?chat_id=' . $chatId . '&text=' . urlencode($message);

        try {
            $client = Http::connectTimeout(5);
            $client->logAction('TG Bot ')->get($url);
        } catch (\Throwable $e) {
            Log::error('BotNotifyService ' . $e->getMessage());
        }
    }
}
