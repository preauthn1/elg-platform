<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendTelegramNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Telegram 告警通知設施。用法：BotNotify::channel(<頻道名>)->send($message, $context)。
 *
 * 🔴 **未另行指定收件人時，頻道名必須存在於 config('bot-notify.channel')，
 * 否則 chat id 為空、通知靜默丟棄且不報錯。**
 * 字面頻道名由 {@see \Tests\Feature\BotNotify\BotNotifyChannelKeysTest} 掃 app/ 擋下，
 * 但**該測試只驗 config 有沒有這個鍵、不驗對應的 env chat id 有沒有配**——
 * 兩邊必須成對，只加 config 那半邊一樣是靜默不發，而且測試會過。
 * chat id 的解析順序見 {@see self::telegram()}：$chatId 參數 → chatId() 的覆寫值 → 頻道 config，
 * 前兩者任一有值時，頻道名不在 config 裡也照發。
 *
 * ⚠️ **站台前綴、context 附加、Str::limit 1000 字元截斷、testing 環境早退，四者都只在 send()
 * 與 sendOnCommit() 裡。**
 * 直接呼叫 public 的 telegram() 會把四者全部繞過——送出的是未加前綴、未截斷的原文，
 * 也不受 testing 早退保護（現有兩處這樣用：WcasinoSWController 與 SummaryMissionDatas）。
 * 新代碼一律走 send() 或 sendOnCommit()。
 *
 * 🔴 **send() 不會因為交易 rollBack 而漏送。** 在交易內呼叫時等交易結束才推 queue，commit 或
 * rollBack 之後都會送出；rollBack 那次在訊息最前面加上 {@see self::ROLLED_BACK_NOTICE}，
 * 讀的人才知道訊息描述的寫入沒有生效。沒有進行中的交易時立即推。
 * 巢狀交易內不保證送達，前提見
 * {@see \App\Jobs\Concerns\SafelyDispatchable::dispatchSafelyAfterTransaction()}。
 *
 * 🔴 **sendOnCommit() 在 rollBack 時把通知整則丟掉，只給「這件事沒發生就不該通知」的業務通知用。**
 * 目前只有大額通知（{@see LargeAmountNotifyService}、{@see WithdrawalService::notifyDailyWithdrawalOverLimit()}），
 * 範圍由 {@see \Tests\Feature\BotNotify\BotNotifySendOnCommitCallSitesTest} 守住。
 * 排查用的告警一律走 send()：告警無聲消失比「內容需要標註已回滾」難查得多。
 * 只驗 send() 有沒有被呼叫（BotNotify::spy()）的測試看不出實際有沒有派發，
 * 要驗實際派發，見 {@see \Tests\Concerns\FakesTelegramDispatch}。
 *
 * 送出一律經 SendTelegramNotification 推 queue，禁止在請求執行緒內同步打
 * Telegram API。例外類告警不得直接呼叫本設施，
 * 必須走 {@see BotNotify\AlertGate}（分類與降噪）。
 *
 * @knowledge rules/payout-submit-and-status
 */
class BotNotifyService
{
    /**
     * rollBack 之後才送出的告警，開頭會加這一行：訊息描述的寫入已經被撤銷，讀的人不能照字面採取行動
     */
    public const ROLLED_BACK_NOTICE = '【交易已回滾，以下內容描述的寫入未生效】';

    public string $channel = 'debug';

    private string $overrideChatId = '';

    public function channel(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function chatId(string $chatId): static
    {
        $this->overrideChatId = $chatId;

        return $this;
    }

    /**
     * 送出告警。在交易內呼叫時等交易結束才推 queue，commit 或 rollBack 之後都會送出，
     * rollBack 那次開頭加上 {@see self::ROLLED_BACK_NOTICE}；沒有進行中的交易則立即推。
     */
    public function send(string $message, array $context = []): void
    {
        $this->deliver($message, $context, dropWhenRolledBack: false);
    }

    /**
     * 只在交易 commit 後送出，rollBack 時整則丟掉。
     *
     * 只給「這件事沒發生就不該通知」的業務通知用（大額通知）。排查用的告警一律走 {@see self::send()}。
     */
    public function sendOnCommit(string $message, array $context = []): void
    {
        $this->deliver($message, $context, dropWhenRolledBack: true);
    }

    private function deliver(string $message, array $context, bool $dropWhenRolledBack): void
    {
        if ($this->shouldSkipDelivery()) {
            return;
        }

        $siteName = config('custom.site_name');
        $appName = config('app.name');
        $env = config('app.env');
        $message = sprintf('[%s-%s-%s] %s', $siteName, $appName, $env, $message);

        if ($context) {
            $contextJson = PHP_EOL . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $message .= $contextJson;
        }

        $message = Str::limit($message, 1000);

        $driver = config('bot-notify.default');

        switch ($driver) {
            case 'telegram':
                $this->telegram($message, dropWhenRolledBack: $dropWhenRolledBack);
                break;

            default:
                Log::error('BotNotifyService ' . $driver . ' not found');
                break;
        }
    }

    /**
     * 測試環境或 CI/CD 環境不發出告警。
     *
     * 要驗實際派發的測試只覆寫這個方法（見 {@see \Tests\Concerns\FakesTelegramDispatch}），
     * 站台前綴、context、截斷與派發時機都走真的程式碼。
     */
    protected function shouldSkipDelivery(): bool
    {
        return app()->environment('testing') || app()->runningUnitTests();
    }

    /**
     * @param  bool  $dropWhenRolledBack  true＝只在 commit 後推 queue，rollBack 時丟掉；false＝交易結束後都推，rollBack 那次加註記
     */
    public function telegram(string $message, string $chatId = '', bool $dropWhenRolledBack = false): void
    {
        $chatId = $chatId ?: $this->overrideChatId ?: config('bot-notify.channel.' . $this->channel);
        $token = [REDACTED]'bot-notify.driver.telegram.token');

        // 🔴 靜默丟棄點：頻道名打錯或 config/bot-notify.php 的 channel 陣列漏加鍵時
        // chat id 就是空的，這裡直接 return——不拋例外、呼叫端拿不到任何訊號，通知就這樣消失。
        // 排查「為什麼沒收到通知」第一個要查的就是頻道名在不在 config('bot-notify.channel') 裡。
        if (empty($chatId) || empty($token)) {
            return;
        }

        if ($dropWhenRolledBack) {
            SendTelegramNotification::dispatchSafely($message, $chatId);

            return;
        }

        SendTelegramNotification::dispatchSafelyAfterTransaction(
            static fn (bool $rolledBack): array => [
                $rolledBack ? self::ROLLED_BACK_NOTICE . PHP_EOL . $message : $message,
                $chatId,
            ],
        );
    }
}
