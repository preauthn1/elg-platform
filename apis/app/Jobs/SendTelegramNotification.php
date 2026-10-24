<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\SafelyDispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram bot 通知非核心業務，走 SafelyDispatchable：queue 掛掉不影響主流程。
 */
class SendTelegramNotification implements ShouldQueue
{
    use Queueable;
    use SafelyDispatchable;

    public function __construct(
        public string $message,
        public string $chatId,
    ) {}

    public function handle(): void
    {
        $token = [REDACTED]'bot-notify.driver.telegram.token');

        if (empty($token)) {
            return;
        }

        $url = 'https://example.invalid/bot' . $token . '/sendMessage?chat_id=' . $this->chatId . '&text=' . urlencode($this->message);

        try {
            Http::logAction('TG Bot')->connectTimeout(5)->get($url);
        } catch (\Throwable $e) {
            Log::error('SendTelegramNotification ' . $e->getMessage());
        }
    }
}
