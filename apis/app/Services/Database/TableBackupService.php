<?php

declare(strict_types=1);

namespace App\Services\Database;

use Illuminate\Support\Facades\DB;

/**
 * 通用備份表機制：改動前快照 → 改動後校驗 → 疏失時逐列回滾 → 排程到期清除。
 *
 * 給「會改動或刪除正式資料」的命令共用（銀行收斂的修正命令、幣別主檔清洗等）；規則：
 * ①備份表名**沿用原表名**＝`<原表名>_bak_<YmdHis>`（排在原表旁邊、一眼可辨）
 * ②呼叫端必須先 capture() 成功才進寫入段，備份失敗即中止
 * ③統一由 `db:prune-table-backups` 排程在保留期（15 天）後 DROP
 * ④同日多次執行互不覆蓋——時間戳到秒級，撞戳即遞增；`CREATE TABLE` 遇同名也會
 *   直接報錯而非靜默覆蓋。
 *
 * ## 兩級備份策略（生產量級教訓）
 *
 * 整表載入記憶體的備份在生產必炸（實測 VWorld withdrawal_orders 1,435,480 筆爆 512MB），
 * 所以：小型配置表整表快照；大表由呼叫端給「範圍欄位＋id 清單」只備會被觸及的列。
 * INSERT SELECT 全程在 DB 端完成、不經 PHP 記憶體。
 *
 * ## 回滾語意：逐列還原，不是整表灌回
 *
 * `restore()` 只把指定 row id 的欄位值寫回 live 表——整表 TRUNCATE 後灌回會抹掉備份
 * 之後新進的資料，本服務刻意不提供那條路徑。
 *
 * ## generated columns（生產 schema 教訓）
 *
 * 表若帶 generated column（例如銀行表的唯一鍵守衛欄），INSERT／UPDATE 都不可對它賦值，
 * 故欄位清單一律顯式並排除 generated columns——判準見 {@see nonGeneratedColumns()}
 * 的警語（DEFAULT_GENERATED 是時間戳欄、不可誤殺）。
 */
class TableBackupService
{
    /** 備份表命名：<原表名>_bak_<YmdHis>。stamp 固定 14 碼，供發現與到期清除解析 */
    public const NAME_SUFFIX_PATTERN = '/^(?<source>[A-Za-z0-9_]+)_bak_(?<stamp>\d{14})$/';

    /** 備份表保留天數，到期由 db:prune-table-backups 排程 DROP */
    public const RETENTION_DAYS = 15;

    /**
     * 改動前快照。回傳備份摘要（stamp 是後續校驗、回滾與清除的鑰匙）。
     *
     * @param  list<string>  $fullTables  整表快照的小型配置表
     * @param  array<string, array{column: string, ids: list<int>}>  $scopedTables  大表：只備 column IN (ids) 的列；
     *                                                                              ids 為空＝不建表（manifest 記 rows: 0）
     * @param  array<string, mixed>  $meta  落 manifest 的額外脈絡（呼叫端、範圍說明等）
     * @return array{stamp: string, tables: array<string, array{backup_table: string|null, scope: string, rows: int}>}
     */
    public function capture(array $fullTables, array $scopedTables = [], array $meta = []): array
    {
        $stamp = $this->uniqueStamp();

        $tables = [];

        foreach ($fullTables as $source) {
            $tables[$source] = $this->copyTable($source, $stamp, null, null);
        }

        foreach ($scopedTables as $source => $scope) {
            $tables[$source] = $this->copyTable($source, $stamp, $scope['column'], $scope['ids']);
        }

        $manifest = [
            'connection' => DB::connection()->getName(),
            'created_at' => now()->toIso8601String(),
            'tables' => $tables,
            ...$meta,
        ];

        $dir = storage_path('app/backups/table-backup');

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $dir . '/manifest_' . $stamp . '.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return ['stamp' => $stamp, 'tables' => $tables];
    }

    /**
     * 改動後校驗：live vs 備份表逐列比對。
     *
     * 回傳每張表的差異清單；`changes` 為 欄位 => [備份值, 現值]。備份裡有、live 裡消失的列
     * 以 `missing: true` 回報。整表快照組另回報 `new_ids`——備份之後新插入的列
     * （供人工分辨校驗窗內的並發寫入）；範圍是否整表由該備份表列數是否等於 manifest 無從
     * 判斷時，以「備份列數 > 0 且呼叫端傳入」為準——這裡直接對所有備份表回報。
     *
     * @return array<string, array{diffs: list<array{id: int, missing: bool, changes: array<string, array{0: mixed, 1: mixed}>}>, new_ids: list<int>}>
     */
    public function diff(string $stamp): array
    {
        $fullSources = $this->manifestFullSources($stamp);
        $report = [];

        foreach ($this->backupTables($stamp) as $source => $backupTable) {
            $backupRows = DB::table($backupTable)->orderBy('id')->get()->keyBy('id');

            $liveRows = $backupRows->isEmpty()
                ? collect()
                : DB::table($source)->whereIn('id', $backupRows->keys()->all())->get()->keyBy('id');

            $diffs = [];

            foreach ($backupRows as $id => $backupRow) {
                $liveRow = $liveRows->get($id);

                if ($liveRow === null) {
                    $diffs[] = ['id' => (int) $id, 'missing' => true, 'changes' => []];

                    continue;
                }

                $changes = [];

                foreach ((array) $backupRow as $column => $before) {
                    $after = ((array) $liveRow)[$column] ?? null;

                    if ((string) $before !== (string) $after) {
                        $changes[$column] = [$before, $after];
                    }
                }

                if ($changes !== []) {
                    $diffs[] = ['id' => (int) $id, 'missing' => false, 'changes' => $changes];
                }
            }

            $newIds = in_array($source, $fullSources, true)
                ? DB::table($source)->whereNotIn('id', $backupRows->keys()->all())->pluck('id')->map(fn ($v): int => (int) $v)->all()
                : [];

            $report[$source] = ['diffs' => $diffs, 'new_ids' => $newIds];
        }

        return $report;
    }

    /**
     * 逐列回滾：把備份表裡指定 row id 的欄位值寫回 live 表。
     *
     * 只動指定的列、整列覆寫（含 updated_at 一併還原成備份值——顯式賦值不會觸發
     * ON UPDATE CURRENT_TIMESTAMP；generated columns 除外，由 DB 自行重算）。
     * live 已不存在的列預設回報 missing、不嘗試重建（多數備份範圍內的命令都不刪列，
     * 缺列代表有其他操作介入，該先查明而不是盲插）；只有呼叫端明示
     * `$recreateMissing=true`（給會合法 DELETE 列的命令，例如清掉停用撞鍵列的修正命令）
     * 才以備份值 INSERT 重建，**保留原 id**——插入撞主鍵或唯一鍵時原樣拋出、
     * 整批回滾（fail-closed：槽位被佔代表狀態已變，該人工查明而不是硬插）。
     *
     * @param  list<int>  $ids
     * @return array{restored: list<int>, unchanged: list<int>, missing: list<int>, recreated: list<int>}
     */
    public function restore(string $stamp, string $source, array $ids, bool $recreateMissing = false): array
    {
        $backupTable = $this->backupTables($stamp)[$source] ?? null;

        if ($backupTable === null) {
            throw new \RuntimeException(sprintf('備份戳 %s 沒有 %s 的備份表', $stamp, $source));
        }

        $writableColumns = array_flip($this->nonGeneratedColumns($source));

        $restored = [];
        $unchanged = [];
        $missing = [];
        $recreated = [];

        DB::transaction(function () use ($backupTable, $source, $ids, $writableColumns, $recreateMissing, &$restored, &$unchanged, &$missing, &$recreated): void {
            $backupRows = DB::table($backupTable)->whereIn('id', $ids)->get()->keyBy('id');

            $liveRows = DB::table($source)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($ids as $id) {
                $backupRow = $backupRows->get($id);

                if ($backupRow !== null && $liveRows->get($id) === null && $recreateMissing) {
                    // 以備份值整列重建、保留原 id；撞主鍵／唯一鍵即拋、整批回滾
                    DB::table($source)->insert(array_intersect_key((array) $backupRow, $writableColumns));
                    $recreated[] = $id;

                    continue;
                }

                if ($backupRow === null || $liveRows->get($id) === null) {
                    // 備份裡沒有＝呼叫端給錯 id；live 裡沒有＝有其他操作介入。都回報讓人查
                    $missing[] = $id;

                    continue;
                }

                $before = array_intersect_key((array) $backupRow, $writableColumns);
                $current = (array) $liveRows->get($id);

                if ($this->rowsEqual($before, $current)) {
                    $unchanged[] = $id;

                    continue;
                }

                DB::table($source)->where('id', $id)->update($before);
                $restored[] = $id;
            }
        });

        return ['restored' => $restored, 'unchanged' => $unchanged, 'missing' => $missing, 'recreated' => $recreated];
    }

    /**
     * 該備份戳現存的備份表（來源表名 => 備份表名）。以命名模式從 information_schema 發現，
     * 不依賴呼叫端傳表清單——回滾與清除都靠這條路。
     *
     * @return array<string, string>
     */
    public function backupTables(string $stamp): array
    {
        $this->assertSafeIdentifier($stamp);

        $tables = [];

        $rows = DB::select(
            'SELECT table_name AS name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name LIKE ?',
            ['%\\_bak\\_' . $stamp],
        );

        foreach ($rows as $row) {
            $name = (string) $row->name;

            if (preg_match(self::NAME_SUFFIX_PATTERN, $name, $m) === 1 && $m['stamp'] === $stamp) {
                $tables[$m['source']] = $name;
            }
        }

        return $tables;
    }

    /**
     * 到期清除：DROP 所有 stamp 早於截止時點的備份表。回傳被刪的表名。
     *
     * 只認 `<表名>_bak_<14 碼數字>` 的嚴格命名，並以 stamp 解析時間——不看
     * 表的 create_time（RENAME 會刷新它）。
     *
     * @return list<string>
     */
    public function prune(\DateTimeInterface $olderThan): array
    {
        $cutoff = $olderThan->format('YmdHis');
        $dropped = [];

        $rows = DB::select(
            "SELECT table_name AS name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name LIKE '%\\_bak\\_%'",
        );

        foreach ($rows as $row) {
            $name = (string) $row->name;

            if (preg_match(self::NAME_SUFFIX_PATTERN, $name, $m) !== 1) {
                continue;
            }

            if ($m['stamp'] >= $cutoff) {
                continue;
            }

            $this->assertSafeIdentifier($name);
            DB::statement(sprintf('DROP TABLE IF EXISTS `%s`', $name));
            $dropped[] = $name;

            $manifest = storage_path('app/backups/table-backup/manifest_' . $m['stamp'] . '.json');

            if (is_file($manifest)) {
                unlink($manifest);
            }
        }

        return $dropped;
    }

    /**
     * 空備份表清除：DROP 所有嚴格命名且**精確 COUNT(*) 為零**的備份表，不論保留期。
     *
     * 空表是 2026-08-28 之前「零列仍建表」舊行為的殘留（capture 已改為零列不建表），
     * 零資料零回滾價值。逐表以精確 COUNT 確認為空才 DROP——information_schema 的
     * TABLE_ROWS 是估計值，不可作為刪除依據。不動 manifest（同批的非空表仍在，
     * manifest 記錄整批脈絡）。
     *
     * @return list<string> 被刪的表名
     */
    public function pruneEmpty(): array
    {
        $dropped = [];

        foreach ($this->emptyBackupTables() as $name) {
            $this->assertSafeIdentifier($name);
            DB::statement(sprintf('DROP TABLE IF EXISTS `%s`', $name));
            $dropped[] = $name;
        }

        return $dropped;
    }

    /**
     * 嚴格命名且精確零列的備份表清單（pruneEmpty 與其 dry-run 共用同一判準）。
     *
     * @return list<string>
     */
    public function emptyBackupTables(): array
    {
        $empty = [];

        $rows = DB::select(
            "SELECT table_name AS name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name LIKE '%\\_bak\\_%'",
        );

        foreach ($rows as $row) {
            $name = (string) $row->name;

            if (preg_match(self::NAME_SUFFIX_PATTERN, $name) !== 1) {
                continue;
            }

            $this->assertSafeIdentifier($name);

            if ((int) DB::table($name)->count() === 0) {
                $empty[] = $name;
            }
        }

        return $empty;
    }

    /**
     * 取唯一備份戳：同日（甚至同秒）多次執行互不覆蓋——已存在同戳備份表即遞增一秒重試。
     */
    private function uniqueStamp(): string
    {
        $at = now();

        for ($i = 0; $i < 60; $i++) {
            $stamp = $at->format('YmdHis');

            if ($this->backupTables($stamp) === []) {
                return $stamp;
            }

            $at = $at->addSecond();
        }

        throw new \RuntimeException('連續 60 個時間戳都已有備份表，請檢查是否有異常殘留');
    }

    /**
     * @param  list<int>|null  $ids  null＝整表；[]＝零列——不建表（backup_table 回 null），
     *                               「0 筆」的明確紀錄由 manifest 的 rows: 0 承擔。空備份表
     *                               零回滾價值卻讓表數膨脹（2026-08-28 六站盤點 549 張備份表
     *                               303 張全空），RB 拍板改為不建。
     * @return array{backup_table: string|null, scope: string, rows: int}
     */
    private function copyTable(string $source, string $stamp, ?string $scopeColumn, ?array $ids): array
    {
        if ($scopeColumn !== null && ($ids === null || $ids === [])) {
            return ['backup_table' => null, 'scope' => sprintf('%s in ids', $scopeColumn), 'rows' => 0];
        }

        $backupTable = $source . '_bak_' . $stamp;

        // 表名無法用參數綁定；來源表名與範圍欄位由呼叫端常數提供、stamp 由 now() 生成，
        // 均經白名單字元檢查，無使用者輸入成分（紅線「禁止未綁定 DB::raw」針對可注入的值，
        // 值一律走綁定）
        $this->assertSafeIdentifier($backupTable);
        $this->assertSafeIdentifier($source);

        DB::statement(sprintf('CREATE TABLE `%s` LIKE `%s`', $backupTable, $source));

        $columns = implode(', ', array_map(
            static fn (string $column): string => sprintf('`%s`', $column),
            $this->nonGeneratedColumns($source),
        ));

        if ($scopeColumn === null) {
            DB::statement(sprintf('INSERT INTO `%1$s` (%2$s) SELECT %2$s FROM `%3$s`', $backupTable, $columns, $source));
        } else {
            $this->assertSafeIdentifier($scopeColumn);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            DB::statement(
                sprintf(
                    'INSERT INTO `%1$s` (%2$s) SELECT %2$s FROM `%3$s` WHERE `%4$s` IN (%5$s)',
                    $backupTable,
                    $columns,
                    $source,
                    $scopeColumn,
                    $placeholders,
                ),
                $ids,
            );
        }

        return [
            'backup_table' => $backupTable,
            'scope' => $scopeColumn === null ? 'full' : sprintf('%s in ids', $scopeColumn),
            'rows' => (int) DB::table($backupTable)->count(),
        ];
    }

    /**
     * manifest 記載為整表快照的來源表（diff 的 new_ids 只對整表組有意義）。
     *
     * @return list<string>
     */
    private function manifestFullSources(string $stamp): array
    {
        $path = storage_path('app/backups/table-backup/manifest_' . $stamp . '.json');

        if (!is_file($path)) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        $sources = [];

        foreach ((array) ($manifest['tables'] ?? []) as $source => $meta) {
            if (($meta['scope'] ?? null) === 'full') {
                $sources[] = (string) $source;
            }
        }

        return $sources;
    }

    /**
     * 該表的非 generated 欄位（依定義順序）。generated column 由 DB 自行計算，
     * INSERT／UPDATE 都不可對它賦值。
     *
     * ⚠️ 判準是 EXTRA 含「VIRTUAL GENERATED」或「STORED GENERATED」（GENERATED 前是**空格**）。
     * 不可寫成 `NOT LIKE '%GENERATED%'`——時間戳欄的 `DEFAULT_GENERATED`（底線）也會中招，
     * 把 created_at／updated_at 排除在快照外＝備份的時間戳全變成備份當下時刻
     * （VWorld 實測：業務欄 before 值無恙，但 1,536 列時間戳誤報淹沒校驗）。
     *
     * @return list<string>
     */
    private function nonGeneratedColumns(string $table): array
    {
        $columns = [];

        $rows = DB::select(
            'SELECT column_name AS name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?
               AND extra NOT LIKE ? AND extra NOT LIKE ?
             ORDER BY ordinal_position',
            [$table, '%VIRTUAL GENERATED%', '%STORED GENERATED%'],
        );

        foreach ($rows as $row) {
            $this->assertSafeIdentifier((string) $row->name);
            $columns[] = (string) $row->name;
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function rowsEqual(array $a, array $b): bool
    {
        foreach ($a as $column => $value) {
            if ((string) $value !== (string) ($b[$column] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function assertSafeIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException(sprintf('非法識別字：%s', $identifier));
        }
    }
}
