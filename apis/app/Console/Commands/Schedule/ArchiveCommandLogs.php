<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

/**
 * 每日歸檔前一天的資料（建議使用）
 * php artisan archive:command_logs --daily
 *
 * 歸檔上個月的資料（預設行為）
 * php artisan archive:command_logs
 *
 * 補救 2025-09 的資料歸檔（--month 只接受保留窗口起點至當月之間的月份，見 AbstractArchive::backfillRange()）
 * php artisan archive:command_logs --month=2025-09
 *
 * 照常歸檔，但分表清理只列出不刪除（首次上線建議先跑這個）
 * php artisan archive:command_logs --daily --dry-run-partitions
 *
 * ## 分表保留期：6 個月（RB 2026-07-29 拍板）
 * `command_logs` 是排程執行記錄（含 `trace_id`），用途是事後排查、價值隨時間衰減快，
 * 且六站量體均勻（2026-07 實測各站分表 302～386 MB），同一個保留期對六站都適用。
 * 保留期較長、屬合規稽核的是 {@see ArchiveActivityLogs}（15 個月）。
 *
 * > ⚠️ 主表資料已由 {@see AbstractArchive::deleteOldData()} 刪除 3 個月前的部分，
 * > **分表是那些月份的唯一副本**，清理不可逆。
 */
class ArchiveCommandLogs extends AbstractArchive
{
    protected $signature = 'archive:command_logs
                            {--month= : 指定要歸檔的月份，格式：YYYY-MM（例如：2025-09）}
                            {--daily : 每日模式，歸檔昨天的資料}
                            {--dry-run-partitions : 分表清理只列出將刪除的分表，不實際執行；歸檔與主表刪除照常進行}';

    protected $description = '將 command_logs 資料備份到分表（預設為上個月，可使用 --daily 每日歸檔），並清除 6 個月前的舊分表';

    protected string $table = 'command_logs';

    protected ?int $keepPartitionMonths = 6;
}
