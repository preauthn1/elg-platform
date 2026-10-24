<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

/**
 * 每日歸檔前一天的資料（建議使用）
 * php artisan archive:activity_logs --daily
 *
 * 歸檔上個月的資料（預設行為）
 * php artisan archive:activity_logs
 *
 * 補救 2025-09 的資料歸檔（--month 只接受保留窗口起點至當月之間的月份，見 AbstractArchive::backfillRange()）
 * php artisan archive:activity_logs --month=2025-09
 *
 * 照常歸檔，但分表刪除只列出不執行
 * php artisan archive:activity_logs --daily --dry-run-partitions
 *
 * ## 分表保留期：15 個月（RB 2026-07-29 拍板 12 ＋ 3）
 * `activity_logs` 是後台操作稽核（誰改了什麼），這類紀錄通常有合規保存要求、砍太短事後補不回來，
 * 故保留期取 `command_logs` 的兩倍多。
 *
 * 原設計是「12 個月 RENAME 進隔離區 → 觀察 3 個月 → DROP」的兩階段，2026-08 拆掉改為單階段
 * 直接在 15 個月屆滿時 DROP，永久銷毀時點完全不變。理由：
 *
 * - **觀察期沒有觀察者**——RENAME 只寫 `Log::warning`，沒有任何通知；隔離錯了 3 個月內零信號，
 *   然後被同一段代碼 DROP 掉
 * - **沒有還原路徑**——沒有 command、沒有程序，「隨時可改回」實際上要有人手動在 prod 打 RENAME
 * - **RENAME 不釋放磁碟**，而釋放磁碟是清理分表唯一的目的，等於把回收延後 3 個月換一個沒人看的保險
 *
 * 拆掉前已確認六站皆無 `_deprecated_*` 殘留表（該機制從未實際觸發過，首次 RENAME 要等 2026-12）。
 *
 * > ⚠️ 主表資料已由 {@see AbstractArchive::deleteOldData()} 刪除 2 個月前的部分，
 * > **分表是那些月份的唯一副本**。量體差異極大（VWorld 7,776 MB vs TPower < 1 MB），
 * > 回收效益幾乎全來自 VWorld 與 Me77。
 */
class ArchiveActivityLogs extends AbstractArchive
{
    protected $signature = 'archive:activity_logs
                            {--month= : 指定要歸檔的月份，格式：YYYY-MM（例如：2025-09）}
                            {--daily : 每日模式，歸檔昨天的資料}
                            {--dry-run-partitions : 只列出將刪除的分表，不實際執行；歸檔與主表刪除照常進行}';

    protected $description = '將 activity_logs 資料備份到分表（預設為上個月，可使用 --daily 每日歸檔），並刪除 15 個月前的舊分表';

    protected string $table = 'activity_logs';

    protected int $deleteMonths = 2;

    protected ?int $keepPartitionMonths = 15;

    protected string $dateColumn = 'created_at';
}
