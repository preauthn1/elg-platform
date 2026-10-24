<?php

declare(strict_types=1);

namespace App\Vendors\PlayTechSW;

use App\Exceptions\LockTimeoutException;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * PTSW 同局同帳的 MySQL named lock，回調（PlayTechController）與補帳命令（bet-record-recovery:refund-unsettled）共用同一把。
 *
 * 兩邊必須拿同一把鎖才能互相序列化：命令的交易進行中若同局回調進來，回調在鎖後才開交易，read view 才看得到
 * 命令已 commit 的退款事件（反之亦然）；各自用不同鎖或不取鎖，回調與命令會各退一次（target/op_tickets#3089）。
 * 讀寫分離下 GET_LOCK／RELEASE_LOCK 必走 write PDO，否則落 read replica、與後續 transaction 不同連線。
 *
 * @knowledge rules/vendor-ptsw
 */
final class GameRoundLock
{
    public const int TIMEOUT_SECONDS = 5;

    /**
     * 鎖域對齊 DB 的同局判定：bet_id／username 欄是 utf8mb4_unicode_ci（不分大小寫），
     * 先小寫化再 md5，PT 對同局送出大小寫不同的 gameRoundCode 或 username 時仍拿同一把鎖。
     */
    public static function key(string $gameRoundCode, string $username): string
    {
        return 'ptsw:' . md5(mb_strtolower($gameRoundCode) . ':' . mb_strtolower($username));
    }

    /**
     * 取鎖後執行 $callback，結束（含例外）必釋放；取鎖須在 DB::transaction 之外，釋放才會晚於 commit。
     *
     * @throws LockTimeoutException TIMEOUT_SECONDS 內未取得鎖
     */
    public static function synchronize(string $lockKey, Closure $callback): mixed
    {
        $acquired = DB::selectFromWriteConnection('SELECT GET_LOCK(?, ?) AS l', [$lockKey, self::TIMEOUT_SECONDS]);
        if (empty($acquired) || (int) $acquired[0]->l !== 1) {
            throw new LockTimeoutException('Concurrent request on game round');
        }

        try {
            return $callback();
        } finally {
            DB::selectFromWriteConnection('SELECT RELEASE_LOCK(?)', [$lockKey]);
        }
    }
}
