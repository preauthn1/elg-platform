<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use Closure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 非核心 Job 的安全派發。
 *
 * 這類 Job（會員統計時間、小時匯總、Telegram 告警等）不屬於主業務，不應與主流程／事務共生死：
 *  - 等當前 DB 事務結束才推 queue（無進行中事務則立即），不與主業務綁在同一失敗單位。
 *  - {@see rescue()} 包住 {@see Bus::dispatch()}：queue 服務異常時只記錄例外、回傳 null，
 *    絕不炸穿 commit 影響主流程。「queue 可以死，主流程必須正常。」
 *
 * 派發時機有兩種，依「rollBack 時這個 Job 還該不該送」選用：
 *  - {@see self::dispatchSafely()}：以 {@see DB::afterCommit()} 只在 commit 後派發，rollBack 時丟掉、不會誤派。
 *    統計類 Job，以及「這件事沒發生就不該通知」的業務通知用這個。
 *  - {@see self::dispatchSafelyAfterTransaction()}：commit 或 rollBack 後都派發，**不會因為 rollBack 漏送**。
 *    Job 參數由 closure 產生，closure 收得到「這次是不是 rollBack」，可據此改內容
 *    （BotNotify 用它在告警前面加上交易已回滾的標註）。保證送達的前提見該方法。
 *
 * 🔴 使用本 trait 的 Job 禁止要求 after commit（實作 ShouldQueueAfterCommit，或呼叫 `$this->afterCommit()`），
 *    queue 連線的 after_commit 也必須維持 false（config/queue.php）。
 *    Laravel 在最外層交易 rollBack 時，先執行 rollBack callback，之後才把這筆交易移出待處理清單；
 *    queue 這時若要求 after commit，dispatchSafelyAfterTransaction() 的推送會掛回這筆正被丟掉的交易，
 *    Job 就被靜默丟掉。Bus::fake() 在 Bus 層就攔下 Job、不經過 queue，派發測試看不出這個問題；
 *    改由 {@see \Tests\Unit\Jobs\Concerns\SafelyDispatchableTest} 直接檢查 queue 設定與 Job 宣告擋住。
 *
 * ⚠️ 必須用 Bus::dispatch（當下同步 push，例外可被 rescue 攔），
 *    不可用 static::dispatch()->afterCommit()——後者的 push 發生在 PendingDispatch 解構／
 *    commit 當下，包在外層的 try-catch/rescue 攔不到（見 target/tickets#3967）。
 */
trait SafelyDispatchable
{
    public static function dispatchSafely(mixed ...$arguments): void
    {
        DB::afterCommit(fn () => self::pushSafely($arguments));
    }

    /**
     * 交易結束後派發：commit 或 rollBack 之後都派發，而且只派發一次；沒有進行中的交易則立即派發。
     *
     * Job 的建構參數由 $arguments 產生，它收到的 bool 是「這次是 rollBack 才派發的嗎」，
     * 需要依成敗改內容時用它（例如在告警前面加上交易已回滾的標註）；不需要就忽略這個參數。
     *
     * 🔴 保證送達的前提：呼叫時所有連線合計只有一筆進行中的交易（不在巢狀交易內）。
     * 交易管理器是所有連線共用的，callback 掛在最近開啟、尚未結束的那筆交易上，不分連線。
     * 前提成立時，推 queue 發生在那筆交易結束之後，queue 不論存在資料庫或 redis 都不會被撤銷。
     * 前提不成立時可能漏送。例如內層交易（savepoint）rollBack 時，Laravel 當下就執行 rollBack callback，
     * 這時連線仍在外層交易內；queue 存在資料庫的話，jobs 表的寫入落在外層交易裡，
     * 外層接著 rollBack 就一起被撤銷，而且已經派發過、不會補派。queue 存在 redis 時不受影響。
     */
    /**
     * @param  Closure(bool): array<int|string, mixed>  $arguments  收「這次是不是 rollBack」，回傳 Job 的建構參數
     */
    public static function dispatchSafelyAfterTransaction(Closure $arguments): void
    {
        $dispatched = false;

        $dispatch = static function (bool $rolledBack) use (&$dispatched, $arguments): void {
            if ($dispatched) {
                return;
            }

            $dispatched = true;
            self::pushSafely($arguments($rolledBack));
        };

        // 沒有進行中的交易時，afterCommit 立即執行、afterRollBack 什麼都不做；
        // 有交易時兩者都掛在最近開啟、尚未結束的那筆交易上，commit 或 rollBack 哪個發生就由哪個派發
        DB::afterCommit(static fn () => $dispatch(false));
        DB::afterRollBack(static fn () => $dispatch(true));
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     */
    private static function pushSafely(array $arguments): void
    {
        rescue(
            fn () => Bus::dispatch(new static(...$arguments)),
            // queue 異常時記一條精簡 warning（保留可見性、不洗 stack trace），不再經 exception handler 重複 report
            fn (Throwable $e) => Log::warning(static::class . ' dispatchSafely failed (queue unavailable)', [
                'error' => $e->getMessage(),
            ]),
            report: false,
        );
    }
}
