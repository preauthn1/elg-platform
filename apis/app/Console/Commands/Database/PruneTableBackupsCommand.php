<?php

declare(strict_types=1);

namespace App\Console\Commands\Database;

use App\Services\Database\TableBackupService;
use Illuminate\Console\Command;

/**
 * 到期備份表清除：DROP 所有超過保留期的 `<原表名>_bak_<YmdHis>` 備份表。
 *
 * 備份表由使用 TableBackupService 的命令產生，保留期預設 15 天。只認嚴格命名（表名＋`_bak_`＋14 碼時間戳）並以
 * 戳解析時間——不看表的 create_time（RENAME 會刷新它），不碰任何不合命名模式的表。
 *
 * 排程每日執行（routes/console.php）；人工提前清特定一批用
 * `DROP TABLE <表名>` 或縮短 --days 跑一次。
 *
 * `--empty-only`：不論保留期，只清**精確 COUNT(*) 為零**的備份表（「零列仍建表」
 * 舊行為的殘留，零回滾價值）。此模式忽略 --days。
 */
class PruneTableBackupsCommand extends Command
{
    protected $signature = 'db:prune-table-backups
                            {--days= : 保留天數（預設 ' . TableBackupService::RETENTION_DAYS . '）}
                            {--empty-only : 不論保留期，只清精確零列的空備份表}
                            {--dry-run : 只列出會被刪的備份表，不執行}';

    protected $description = '清除超過保留期的備份表（<原表名>_bak_<時間戳>）；--empty-only 清零列空表';

    public function __construct(protected TableBackupService $backup)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('empty-only')) {
            return $this->handleEmptyOnly();
        }

        $days = $this->option('days') !== null
            ? max(1, (int) $this->option('days'))
            : TableBackupService::RETENTION_DAYS;

        $cutoff = now()->subDays($days);

        if ($this->option('dry-run')) {
            // prune() 會真的 DROP，dry-run 走同一套命名／期限判準只列不刪
            $candidates = $this->expiredTables($cutoff);

            $this->info(sprintf('🔍 Dry-run：%d 張備份表已超過 %d 天保留期', count($candidates), $days));

            foreach ($candidates as $table) {
                $this->line('  ' . $table);
            }

            return self::SUCCESS;
        }

        $dropped = $this->backup->prune($cutoff);

        if ($dropped === []) {
            $this->info(sprintf('沒有超過 %d 天保留期的備份表。', $days));

            return self::SUCCESS;
        }

        $this->info(sprintf('已清除 %d 張到期備份表：', count($dropped)));

        foreach ($dropped as $table) {
            $this->line('  ' . $table);
        }

        return self::SUCCESS;
    }

    /**
     * --empty-only：只清精確零列的空備份表，不論保留期。dry-run 與實刪共用
     * emptyBackupTables() 同一判準（嚴格命名＋逐表精確 COUNT）。
     */
    private function handleEmptyOnly(): int
    {
        if ($this->option('dry-run')) {
            $candidates = $this->backup->emptyBackupTables();

            $this->info(sprintf('🔍 Dry-run：%d 張零列空備份表', count($candidates)));

            foreach ($candidates as $table) {
                $this->line('  ' . $table);
            }

            return self::SUCCESS;
        }

        $dropped = $this->backup->pruneEmpty();

        if ($dropped === []) {
            $this->info('沒有零列的空備份表。');

            return self::SUCCESS;
        }

        $this->info(sprintf('已清除 %d 張零列空備份表：', count($dropped)));

        foreach ($dropped as $table) {
            $this->line('  ' . $table);
        }

        return self::SUCCESS;
    }

    /**
     * dry-run 用：與 prune() 同一套判準（嚴格命名＋戳早於截止），只列不刪。
     *
     * @return list<string>
     */
    private function expiredTables(\DateTimeInterface $cutoff): array
    {
        $cutoffStamp = $cutoff->format('YmdHis');
        $expired = [];

        $rows = \Illuminate\Support\Facades\DB::select(
            "SELECT table_name AS name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name LIKE '%\\_bak\\_%'",
        );

        foreach ($rows as $row) {
            $name = (string) $row->name;

            if (preg_match(TableBackupService::NAME_SUFFIX_PATTERN, $name, $m) === 1 && $m['stamp'] < $cutoffStamp) {
                $expired[] = $name;
            }
        }

        return $expired;
    }
}
