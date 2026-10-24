<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Services\Database\TableBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 還原被銀行收斂改指污染的 `updated_at`
 *
 * 【分類】Bank — 存量還原工具，🔴 **不退役**（見下方）。
 *
 * ## 為什麼需要這支命令
 *
 * `bank:fix-mismatch` 的改指走 `DB::table()->update(['bank_id' => ...])`。這些表的
 * `updated_at` 帶 `on update CURRENT_TIMESTAMP`，未明確自我賦值時會被 MySQL 連帶蓋成
 * 執行時間。改指是技術搬移、不是業務事件，蓋掉時間戳會污染以 `updated_at` 判讀時序的
 * 下游——`withdrawal_orders.updated_at` 正是前端訂單排序欄，2026-08-27 因此造成生產訂單
 * 順序混亂（六站共 550 筆）。根因已在 `BankRepairService::reassignForeignKeys()` 修掉，
 * 本命令負責把既有污染還原回去。
 *
 * `db:backup-rollback` 是**全欄還原**，會把 `bank_id` 一起退回去＝撤銷收斂成果，
 * 所以不能用；本命令只動 `updated_at` 單欄。
 *
 * ## 判定與 gate（全部 fail-closed：不確定就跳過，不猜）
 *
 * 候選＝備份表與 live 同時滿足「`bank_id` 不同」且「`updated_at` 不同」的列。三道 gate：
 * 1. 同一 id 出現在多張備份表且原值**不一致** ⇒ 跳過（無法判斷哪個才是原值）
 * 2. live 的 `updated_at` **早於**備份戳 ⇒ 跳過（時序矛盾，資料狀態非預期）
 * 3. live 的 `updated_at` 晚於最後一次備份戳超過 `--window` 秒 ⇒ 跳過
 *    （改指之後又被業務更新過，現值才是真值，還原會覆蓋業務時間）
 *
 * 還原值取**最早**備份戳的值＝第一次被改指之前的原始值。
 *
 * ## 使用範例
 *
 *   php artisan bank:restore-fk-timestamps                          # 唯讀預演（withdrawal_orders）
 *   php artisan bank:restore-fk-timestamps --table=member_bank_accounts
 *   php artisan bank:restore-fk-timestamps --stamp=20260826091621   # 只處理單一備份戳
 *   php artisan bank:restore-fk-timestamps --execute --force
 *
 * 寫入前自動以 `TableBackupService` 備份候選列（scoped by id）、寫入後自動 diff 校驗；
 * 疏失時走 `db:backup-rollback --stamp=<本次戳> --table=<表> --ids=<...>`。
 *
 * ## 退役條件
 *
 * 🔴 **不退役（`docs/未完成計劃/RB/TODO.md` §2.4-4，2026-09-09 查證後登記）。**
 *
 * 「根因已修 ⇒ 可刪」這條推論**已被推翻**。已修的部分是真的：
 * `BankRepairService::reassignForeignKeys()` 走 `preservingUpdatedAt()`
 * （`BankRepairService.php:982`），`member:migrate-bank-accounts` 四處寫入的同型缺陷
 * 也於同日修復（`8d95e28bc`，含 SQL 斷言迴歸測試）。
 *
 * 但**其餘會寫那五張保護表的路徑尚未全量盤點** ⇒ 來源沒有真的斷，
 * 工具留著待命的成本遠低於再發一次 2026-08-27 那種事故（六站 550 筆）。
 *
 * ⇒ 要重啟退役討論，前置是「五張保護表的全部寫入路徑完成盤點、且皆已掛時間戳保護」。
 */
class BankRestoreFkTimestampsCommand extends Command
{
    protected $signature = 'bank:restore-fk-timestamps
                            {--table=withdrawal_orders : 要還原的表（需有對應 <表>_bak_<戳> 備份）}
                            {--stamp= : 只處理該備份戳，省略則掃該表所有備份}
                            {--window=3600 : 容許「改指造成」的時間窗（秒）；live updated_at 超出最後備份戳 +N 秒即視為後續業務更新並跳過}
                            {--execute : 實際寫入（預設唯讀預演）}
                            {--force : 與 --execute 並用，跳過確認}
                            {--skip-backup : 與 --execute 並用，明示不備份（無回滾路徑，另需確認）}';

    protected $description = '還原被銀行收斂改指連帶蓋掉的 updated_at（只動該欄，不撤銷收斂）';

    public function handle(TableBackupService $backups): int
    {
        $table = (string) $this->option('table');

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            $this->error('--table 只允許 [A-Za-z0-9_]。');

            return self::FAILURE;
        }

        $window = (int) $this->option('window');

        if ($window < 0) {
            $this->error('--window 不可為負數。');

            return self::FAILURE;
        }

        $bakTables = $this->backupTables($table, $this->option('stamp') !== null ? (string) $this->option('stamp') : null);

        if ($bakTables === []) {
            $this->warn(sprintf('找不到 %s 的備份表%s，無事可做。', $table, $this->option('stamp') !== null ? '（指定戳）' : ''));

            return self::SUCCESS;
        }

        $this->info(sprintf('掃描 %d 張備份表（%s）...', count($bakTables), $table));

        [$candidates, $skipped] = $this->collect($table, $bakTables, $window);

        $this->renderSkipped($skipped);

        if ($candidates === []) {
            $this->info('沒有需要還原的列。');

            return self::SUCCESS;
        }

        $this->renderCandidates($table, $candidates);

        if (!$this->option('execute')) {
            $this->info(sprintf('預演完成（未寫入）。共 %d 筆可還原；加 --execute 實際寫入。', count($candidates)));

            return self::SUCCESS;
        }

        if (!$this->confirmExecution($table, count($candidates))) {
            return self::FAILURE;
        }

        $stamp = null;

        if (!$this->option('skip-backup')) {
            $captured = $backups->capture([], [$table => ['column' => 'id', 'ids' => array_keys($candidates)]], [
                'command' => $this->getName(),
                'scope' => sprintf('%s.updated_at 還原候選 %d 列', $table, count($candidates)),
            ]);
            $stamp = $captured['stamp'];
            $this->line(sprintf('  備份戳 %s（%d 筆）', $stamp, $captured['tables'][$table]['rows'] ?? 0));
        }

        $restored = $this->restore($table, $candidates);

        $this->info(sprintf('已還原 %d 筆 %s.updated_at。', $restored, $table));

        if ($stamp !== null) {
            $this->verify($backups, $stamp, $table, $candidates);
            $this->line(sprintf('  回滾（如需）：php artisan db:backup-rollback --stamp=%s --table=%s --ids=<id,...>', $stamp, $table));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{name: string, stamp: string, ts: int}>
     */
    private function backupTables(string $table, ?string $stamp): array
    {
        $rows = DB::select(
            'SELECT table_name AS name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name REGEXP ? ORDER BY table_name',
            ['^' . $table . '_bak_[0-9]{14}$'],
        );

        $out = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;
            $found = substr($name, -14);

            if ($stamp !== null && $found !== $stamp) {
                continue;
            }

            $out[] = ['name' => $name, 'stamp' => $found, 'ts' => $this->stampToTimestamp($found)];
        }

        return $out;
    }

    private function stampToTimestamp(string $stamp): int
    {
        $iso = sprintf(
            '%s-%s-%s %s:%s:%s',
            substr($stamp, 0, 4),
            substr($stamp, 4, 2),
            substr($stamp, 6, 2),
            substr($stamp, 8, 2),
            substr($stamp, 10, 2),
            substr($stamp, 12, 2),
        );

        return (int) strtotime($iso);
    }

    /**
     * @param  list<array{name: string, stamp: string, ts: int}>  $bakTables
     * @return array{0: array<int, array{original: string, live: string, from_stamp: string}>, 1: array<string, list<string>>}
     */
    private function collect(string $table, array $bakTables, int $window): array
    {
        /** @var array<int, array{values: array<string, string>, live: string, last_ts: int, first_stamp: string, first_ts: int}> $seen */
        $seen = [];

        foreach ($bakTables as $bak) {
            $rows = DB::select(sprintf(
                'SELECT b.id AS id, b.updated_at AS backup_updated_at, w.updated_at AS live_updated_at
                 FROM `%s` b JOIN `%s` w ON w.id = b.id
                 WHERE b.bank_id <> w.bank_id AND b.updated_at <> w.updated_at',
                $bak['name'],
                $table,
            ));

            foreach ($rows as $row) {
                $id = (int) $row->id;
                $backupValue = (string) $row->backup_updated_at;

                if (!isset($seen[$id])) {
                    $seen[$id] = [
                        'values' => [],
                        'live' => (string) $row->live_updated_at,
                        'last_ts' => $bak['ts'],
                        'first_stamp' => $bak['stamp'],
                        'first_ts' => $bak['ts'],
                    ];
                }

                $seen[$id]['values'][$bak['stamp']] = $backupValue;
                $seen[$id]['last_ts'] = max($seen[$id]['last_ts'], $bak['ts']);

                if ($bak['ts'] < $seen[$id]['first_ts']) {
                    $seen[$id]['first_ts'] = $bak['ts'];
                    $seen[$id]['first_stamp'] = $bak['stamp'];
                }
            }
        }

        $candidates = [];
        $skipped = [];

        foreach ($seen as $id => $info) {
            $distinct = array_unique(array_values($info['values']));

            if (count($distinct) > 1) {
                $skipped['多張備份原值不一致，無法判定原值'][] = sprintf('id=%d（%s）', $id, implode(' / ', $distinct));

                continue;
            }

            $liveTs = (int) strtotime($info['live']);

            if ($liveTs < $info['first_ts']) {
                $skipped['live updated_at 早於備份戳（時序矛盾）'][] = sprintf('id=%d live=%s bak=%s', $id, $info['live'], $info['first_stamp']);

                continue;
            }

            if ($liveTs > $info['last_ts'] + $window) {
                $skipped[sprintf('改指後疑似有業務更新（超出最後備份戳 +%ds）', $window)][] = sprintf('id=%d live=%s', $id, $info['live']);

                continue;
            }

            $candidates[$id] = [
                'original' => (string) $distinct[array_key_first($distinct)],
                'live' => $info['live'],
                'from_stamp' => $info['first_stamp'],
            ];
        }

        ksort($candidates);

        return [$candidates, $skipped];
    }

    /**
     * @param  array<string, list<string>>  $skipped
     */
    private function renderSkipped(array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        foreach ($skipped as $reason => $items) {
            $this->warn(sprintf('⏭ 跳過 %d 筆——%s', count($items), $reason));

            foreach (array_slice($items, 0, 10) as $item) {
                $this->line('    ' . $item);
            }

            if (count($items) > 10) {
                $this->line(sprintf('    ...（另 %d 筆）', count($items) - 10));
            }
        }
    }

    /**
     * @param  array<int, array{original: string, live: string, from_stamp: string}>  $candidates
     */
    private function renderCandidates(string $table, array $candidates): void
    {
        $this->info(sprintf('可還原 %d 筆 %s.updated_at：', count($candidates), $table));

        foreach (array_slice($candidates, 0, 20, true) as $id => $c) {
            $this->line(sprintf('    id=%-10d %s → %s（備份戳 %s）', $id, $c['live'], $c['original'], $c['from_stamp']));
        }

        if (count($candidates) > 20) {
            $this->line(sprintf('    ...（另 %d 筆，逐筆值在備份表）', count($candidates) - 20));
        }
    }

    private function confirmExecution(string $table, int $count): bool
    {
        if ($this->option('skip-backup') && !$this->option('force')) {
            if (!$this->confirm(sprintf('--skip-backup 沒有回滾路徑，仍要對 %s 寫入 %d 筆？', $table, $count))) {
                return false;
            }
        }

        if ($this->option('force')) {
            return true;
        }

        return $this->confirm(sprintf('即將還原 %s.updated_at 共 %d 筆，確定？', $table, $count));
    }

    /**
     * @param  array<int, array{original: string, live: string, from_stamp: string}>  $candidates
     */
    private function restore(string $table, array $candidates): int
    {
        $done = 0;

        foreach ($candidates as $id => $c) {
            // 明確賦值 updated_at＝不觸發 on update CURRENT_TIMESTAMP，寫進去的就是備份的原值
            $done += DB::table($table)->where('id', $id)->update(['updated_at' => $c['original']]);
        }

        return $done;
    }

    /**
     * @param  array<int, array{original: string, live: string, from_stamp: string}>  $candidates
     */
    private function verify(TableBackupService $backups, string $stamp, string $table, array $candidates): void
    {
        $report = $backups->diff($stamp)[$table] ?? ['diffs' => [], 'new_ids' => []];
        $unexpected = [];

        foreach ($report['diffs'] as $diff) {
            $changed = array_keys($diff['changes']);

            if ($diff['missing'] || $changed !== ['updated_at']) {
                $unexpected[] = sprintf('id=%d 動到 %s', $diff['id'], $diff['missing'] ? '（列消失）' : implode('/', $changed));
            }
        }

        if ($unexpected === []) {
            $this->info(sprintf('校驗通過：%d 筆差異全部只在 updated_at 一欄。', count($report['diffs'])));

            return;
        }

        $this->error('校驗發現非預期差異：');

        foreach ($unexpected as $line) {
            $this->line('    ' . $line);
        }
    }
}
