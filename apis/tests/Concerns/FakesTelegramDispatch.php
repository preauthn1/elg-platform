<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Facades\BotNotify;
use App\Jobs\SendTelegramNotification;
use App\Services\BotNotifyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

/**
 * 讓 BotNotify 走真實的派發路徑，用 Bus::fake 攔下實際派發的 Telegram job。
 *
 * BotNotify 在 testing 環境會直接返回，而且在交易內呼叫時要等交易結束才派發，
 * 只驗 send() 有沒有被呼叫（BotNotify::spy()）看不出「交易內送出、接著 rollBack」的告警實際有沒有送出去。
 * 這裡換成只關掉 testing 早退（{@see BotNotifyService::shouldSkipDelivery()}）的子類，其餘都是真的程式碼：
 * 站台前綴、context、截斷，以及 send() 與 sendOnCommit() 各自的派發時機。
 */
trait FakesTelegramDispatch
{
    protected function fakeTelegramDispatch(): void
    {
        config([
            'bot-notify.default' => 'telegram',
            'bot-notify.driver.telegram.token' => 'test-token',
            'bot-notify.channel.debug' => 'test-chat',
            'bot-notify.channel.error' => 'test-chat',
            'bot-notify.channel.warn' => 'test-chat',
        ]);

        BotNotify::swap(new class extends BotNotifyService
        {
            protected function shouldSkipDelivery(): bool
            {
                return false;
            }
        });

        Bus::fake([SendTelegramNotification::class]);
    }

    /**
     * 實際派發、內容含指定字串的 Telegram 告警
     *
     * @return Collection<int, SendTelegramNotification>
     */
    protected function dispatchedTelegramAlerts(string $needle): Collection
    {
        return Bus::dispatched(
            SendTelegramNotification::class,
            fn (SendTelegramNotification $job): bool => str_contains($job->message, $needle),
        );
    }
}
