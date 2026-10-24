<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Models\OrderRequestLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 清理 order_request_logs 過期的三方往返紀錄（保留 90 天，工單 target/tickets#4449）。
 *
 * 這張表每一筆都帶 request／response 原文，接上 EasyPay／MaxPay／TngPin 的代收代付之後
 * 量級跳了一階——光 TngPin 代收三站一週就 2000 多筆，加上建單與代付，粗估一週 5000 筆。
 * 不清的話一年 26 萬列，而且每列都有 TEXT 欄位。
 *
 * 保留期訂 90 天的理由：這張表的讀者是客服與開發，用途是回答「這筆為什麼沒上分」。
 * GCP log 只留 30 天，所以 30 天之外本表就是唯一的往返證據；90 天涵蓋跨季度的對帳爭議，
 * 再久的爭議得靠金流商後台的對帳單，不是靠日誌。
 *
 * 🔴 **本表 append-only**，所以清理是唯一的刪除路徑，且只以 `created_at` 為準、
 * 不依任何業務狀態——已經結案的訂單與還在爭議的訂單一視同仁，避免清理邏輯跟著業務規則漂移。
 *
 * php artisan clean:order-request-logs
 * php artisan clean:order-request-logs --dry-run
 *
 * @knowledge rules/payment-callback-common-checks
 */
class CleanOrderRequestLogs extends Command
{
    /** 分批刪除，避免單一大 DELETE 長時間持鎖拖累線上回調寫入 */
    private const int CHUNK_SIZE = 5000;

    /** 保留天數；改這個值前先確認客服的回看需求與 GCP log 的保留期 */
    private const int RETENTION_DAYS = 90;

    protected $signature = 'clean:order-request-logs {--dry-run : 只顯示將刪除的列數，不實際刪除}';

    protected $description = '清理 90 天前的三方往返紀錄（order_request_logs）';

    public function handle(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        if ($this->option('dry-run')) {
            $count = OrderRequestLog::query()->where('created_at', '<', $cutoff)->count();
            $this->warn("=== 乾跑模式：{$cutoff} 前的往返紀錄共 {$count} 列，未刪除 ===");

            return self::SUCCESS;
        }

        $total = 0;

        do {
            $deleted = OrderRequestLog::query()
                ->where('created_at', '<', $cutoff)
                ->limit(self::CHUNK_SIZE)
                ->delete();
            $total += $deleted;
        } while ($deleted > 0);

        Log::info('clean:order-request-logs 完成', ['cutoff' => (string) $cutoff, 'deleted' => $total]);
        $this->info("已刪除 {$cutoff} 前的往返紀錄 {$total} 列");

        return self::SUCCESS;
    }
}
