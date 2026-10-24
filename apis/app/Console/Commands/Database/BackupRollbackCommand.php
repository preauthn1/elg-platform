<?php

declare(strict_types=1);

namespace App\Console\Commands\Database;

use App\Services\Database\TableBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 備份表的逐列回滾：把指定 row 還原成改動前的值（凡用 TableBackupService 建的備份都適用）。
 *
 * 回滾語意是**逐列覆寫、只動指定的 id**——不是整表灌回（那會抹掉備份之後新進的資料，
 * 本命令不提供）。要回滾哪些列由人決定：
 * 先看原命令跑完的校驗報告與 bank_dedup_logs 的審計，再點名。
 *
 * 預設 dry-run（逐列列出會還原成什麼值）；--execute 才寫入，寫入需確認（--force 跳過）。
 * 每次執行記審計 action=backup_rollback（不在 bank:dedup-rollback 的 exact 白名單內，
 * 不會被誤消費）。
 *
 * 使用範例：
 *   php artisan db:backup-rollback --stamp=20260825073618 --table=banks --ids=1371,1372
 *   php artisan db:backup-rollback --stamp=... --table=cash_flow_banks --ids=55 --execute
 */
class BackupRollbackCommand extends Command
{
    /** 一筆審計 detail 最多帶幾個 id（detail 欄位長度的安全邊界） */
    private const AUDIT_ID_CHUNK = 20;

    protected $signature = 'db:backup-rollback
                            {--stamp= : 備份戳（capture 輸出的 <原表名>_bak_<stamp>）}
                            {--table= : 要回滾的來源表名}
                            {--ids= : 逗號分隔的 row id}
                            {--execute : 實際寫入（預設 dry-run）}
                            {--force : 與 --execute 並用，跳過確認}
                            {--recreate-missing : live 已不存在的列以備份值 INSERT 重建（保留原 id；限被合法 DELETE 的列）}';

    protected $description = '從備份表（<原表名>_bak_<時間戳>）逐列還原指定 row（預設 dry-run）';

    public function __construct(protected TableBackupService $backup)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $stamp = (string) $this->option('stamp');
        $table = (string) $this->option('table');
        $ids = $this->parseIds((string) $this->option('ids'));

        if ($stamp === '' || $table === '' || $ids === []) {
            $this->error('--stamp、--table、--ids 皆為必填。');

            return self::FAILURE;
        }

        $backupTables = $this->backup->backupTables($stamp);

        if (!isset($backupTables[$table])) {
            $this->error(sprintf(
                '備份戳 %s 沒有 %s 的備份表。該戳現存：%s',
                $stamp,
                $table,
                $backupTables === [] ? '（無）' : implode('、', array_keys($backupTables)),
            ));

            return self::FAILURE;
        }

        if (!$this->renderPlan($backupTables[$table], $table, $ids)) {
            return self::FAILURE;
        }

        if (!$this->option('execute')) {
            $this->newLine();
            $this->info('🔍 Dry-run 模式，未寫入。加 --execute 以實際還原。');

            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm(sprintf('確認把 %s 的 %d 列還原成備份值？', $table, count($ids)), false)) {
            $this->info('已取消，未寫入任何資料。');

            return self::SUCCESS;
        }

        $result = $this->backup->restore($stamp, $table, $ids, recreateMissing: (bool) $this->option('recreate-missing'));

        $this->audit($stamp, $table, [...$result['restored'], ...$result['recreated']]);

        $this->newLine();
        $this->info(sprintf(
            '✅ 還原完成：%d 列寫回備份值、%d 列以備份值重建、%d 列與備份相同未動、%d 列缺失未處理。',
            count($result['restored']),
            count($result['recreated']),
            count($result['unchanged']),
            count($result['missing']),
        ));

        if ($result['missing'] !== []) {
            $this->warn(sprintf(
                '⚠ 缺失的列（備份或 live 裡找不到）：id=%s——請查明來由，本命令不盲插。',
                implode(', ', $result['missing']),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * 逐列列出「現值 → 會還原成的備份值」。無任何差異也照列（還原是冪等空操作）。
     */
    private function renderPlan(string $backupTable, string $table, array $ids): bool
    {
        $backupRows = DB::table($backupTable)->whereIn('id', $ids)->get()->keyBy('id');
        $liveRows = DB::table($table)->whereIn('id', $ids)->get()->keyBy('id');

        $missingFromBackup = array_values(array_filter($ids, fn (int $id): bool => !$backupRows->has($id)));

        if ($missingFromBackup !== []) {
            $this->error(sprintf(
                '這些 id 不在備份表裡：%s——備份範圍是「本次觸及的列」，不在裡面代表當初就沒被動過或 id 給錯。',
                implode(', ', $missingFromBackup),
            ));

            return false;
        }

        foreach ($ids as $id) {
            $backup = (array) $backupRows->get($id);
            $live = $liveRows->has($id) ? (array) $liveRows->get($id) : null;

            if ($live === null) {
                $this->warn($this->option('recreate-missing')
                    ? sprintf('  ＋ id=%d 在 live 已不存在，將以備份值 INSERT 重建（保留原 id；撞鍵即整批回滾）', $id)
                    : sprintf('  ⚠ id=%d 在 live 已不存在（未帶 --recreate-missing 不重建，將回報 missing）', $id));

                continue;
            }

            $changes = [];

            foreach ($backup as $column => $before) {
                if ((string) ($live[$column] ?? null) !== (string) $before) {
                    $changes[] = sprintf('%s: %s → %s', $column, $this->short($live[$column] ?? null), $this->short($before));
                }
            }

            $this->line($changes === []
                ? sprintf('  = id=%d 與備份相同，無需還原', $id)
                : sprintf('  ↺ id=%d  %s', $id, implode('，', $changes)));
        }

        return true;
    }

    /**
     * 還原動作落審計（survivor／loser 不適用於回滾情境，以 0 佔位，
     * 實際範圍在 detail：備份戳進 code 欄、表名與 row id 進 detail）。
     *
     * @param  list<int>  $restoredIds
     */
    private function audit(string $stamp, string $table, array $restoredIds): void
    {
        foreach (array_chunk($restoredIds, self::AUDIT_ID_CHUNK) as $chunk) {
            DB::table('bank_dedup_logs')->insert([
                'connection' => DB::connection()->getName(),
                'agent_group_id' => 0,
                'code' => mb_substr($stamp, 0, 16),
                'survivor_bank_id' => 0,
                'loser_bank_id' => 0,
                'action' => 'backup_rollback',
                'detail' => json_encode(['t' => $table, 'ids' => $chunk], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function parseIds(string $raw): array
    {
        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);

            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    private function short(mixed $value): string
    {
        $text = (string) $value;

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '…' : $text;
    }
}
