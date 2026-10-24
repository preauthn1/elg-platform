<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 多白標 schema drift 盤點工具。
 *
 * 用途：一次掃描 7 個白標 DB 的 column / index drift，產出報表。
 * 建立時機：2026-05-22 因 agent_cash_flow_order_types.tax 等 drift 需盤點全表。
 * 預期生命週期：補完缺失 migration 後可移除（或保留為定期巡檢工具）。
 *
 * 命令範例（在容器內執行：docker exec -it php-fpm8 bash -c "cd /var/www/APIs && <cmd>"）：
 *
 * 預設範圍（7 connection × Phase 1 14 張表，stdout 印 artisan table）：
 *   php artisan tool:audit-schema-drift
 *
 * 兩白標 1:1 對比（最常用，聚焦差異）：
 *   php artisan tool:audit-schema-drift --connections=mysql,Me77
 *
 * 指定要比對的表：
 *   php artisan tool:audit-schema-drift --tables=vendors,agent_cash_flow_order_types
 *
 * 兩白標 + 指定表 + 跳過 index 加速：
 *   php artisan tool:audit-schema-drift --connections=VWorld,TPower --tables=banks --no-indexes
 *
 * 全表掃描（7 connection 表名 union，~895 表，11 秒）：
 *   php artisan tool:audit-schema-drift --all
 *
 * 產出 markdown 報表給檔案 / commit（stdout 不再印 table）：
 *   php artisan tool:audit-schema-drift --save=/tmp/schema-drift.md
 *
 * 兩白標完整對比並寫檔：
 *   php artisan tool:audit-schema-drift --connections=Yes2Win,AlfaWin77 --all --save=/tmp/y2w-vs-aw77.md
 *
 * 比 View / Trigger / Routine（預設關，分別用 flag 開）：
 *   php artisan tool:audit-schema-drift --views --connections=mysql,Yes2Win
 *   php artisan tool:audit-schema-drift --triggers --connections=mysql,Me77
 *   php artisan tool:audit-schema-drift --routines --connections=mysql,Me77
 *
 * SP/Function 預設過濾 ad-hoc 牌（*_bak / *_test / *_mars / *_neil / *_20\d{6}）；
 * 要看全部含 ad-hoc 加 --include-ad-hoc-routines：
 *   php artisan tool:audit-schema-drift --routines --include-ad-hoc-routines --connections=mysql,Me77
 *
 * 全部 schema object 一次比 + 寫檔：
 *   php artisan tool:audit-schema-drift --views --triggers --routines --connections=mysql,Me77 --save=/tmp/all-drift.md
 *
 * 可用 connection display name：mysql / VWorld / TPower / VPlus33 / Yes2Win / AlfaWin77 / Me77
 *
 * 輸出語意：
 * - Table-level 缺失：表只存在於部分 connection（補 create_* migration）
 * - Column / Index 漂移：表都在但 column 型別 / default / index 結構不一致（補 ALTER migration）
 * - 兩個桶互斥；連線失敗的 connection 標 [FAILED] 不影響其他 connection 輸出
 *
 * 相關背景：
 * - 956cfc044 fill_missing_columns_for_test_environment.php（develop only）
 */
class AuditSchemaDrift extends Command
{
    protected $signature = 'tool:audit-schema-drift
        {--tables= : 指定表名，逗號分隔（未指定時用 Phase 1 預設 14 張表）}
        {--all : 比對所有 connection 表名 union，覆蓋 --tables}
        {--connections= : 指定要對比的白標 display name，逗號分隔（未指定時 7 個全跑；常用於兩個白標 1:1 對比，例 --connections=VWorld,Me77）}
        {--save= : markdown 輸出路徑（未指定時只印 stdout）}
        {--no-indexes : 跳過 index 比對加速}
        {--routines : 比對 Stored Procedure / Function（預設關；ad-hoc 牌如 *_bak/*_test 自動過濾，加 --include-ad-hoc-routines 可全看）}
        {--views : 比對 View definition}
        {--triggers : 比對 Trigger（name + timing + event + action）}
        {--include-ad-hoc-routines : 關閉 routine 預設過濾，顯示所有 SP/Function（含 ad-hoc 牌）}';

    protected $description = '盤點 7 個白標 DB 的 column / index schema drift';

    /**
     * 白標 display name → 實際 DB connection key。
     *
     * AlfaWin77 為正名（業務正式名稱），但 config/database.php 的 connection key
     * 暫時還是舊名 'AlphaWin77'，等 rename worktree 合入主線後 value 同步改成
     * 'AlfaWin77'，屆時此 mapping 可塌成單純的 list。
     */
    private const CONNECTIONS = [
        'mysql' => 'mysql',
        'VWorld' => 'VWorld',
        'TPower' => 'TPower',
        'VPlus33' => 'VPlus33',
        'Yes2Win' => 'Yes2Win',
        'AlfaWin77' => 'AlphaWin77',
        'Me77' => 'Me77',
    ];

    private const PHASE_1_TABLES = [
        'shareholder_categories',
        'vendors',
        'realname_validation_requests',
        'event_details',
        'event_invite_records',
        'order_histories',
        'summary_shareholder_temps',
        'summary_shareholder_winlose_temps',
        'summary_shareholders',
        'vip_contents',
        'oppo_accounts',
        'pussy888_accounts',
        'agent_cash_flow_order_types',
        'bank_transaction_logs',
    ];

    /**
     * SP / Function 預設過濾規則：排除明顯的 ad-hoc 個人實驗 SP（_bak / _test / _mars /
     * _neil / 日期後綴等），避免 audit 報表被 noise 灌爆。加 --include-ad-hoc-routines
     * 可關閉此過濾完整顯示。
     */
    private const AD_HOC_ROUTINE_PATTERNS = [
        '/_bak\d*$/i',           // *_bak, *_bak1, *_bak2
        '/_bak_\d{8}$/i',        // *_bak_20221118
        '/_test\d*$/i',          // *_test, *_test1
        '/_mars$/i',              // *_mars
        '/_neil$/i',              // *_neil
        '/_allen$/i',             // *_allen
        '/_\d{6,8}$/',           // *_20240105, *_20251020
        '/^TEST_/i',              // TEST_*
        '/^timtest$/i',           // timtest
        '/^Mars_test$/i',         // Mars_test
        '/^ADMIN_AAA/i',          // ADMIN_AAA, ADMIN_AAAA, ADMIN_AAB
        '/^ADMIN_BBB/i',          // ADMIN_BBB
        '/^ADMIN_Leah_test$/i',   // ADMIN_Leah_test
    ];

    /**
     * 本次執行實際比對的 connection 集合（display name => connection key），
     * 由 --connections= flag 決定；未指定時等於 self::CONNECTIONS 全集合。
     *
     * @var array<string, string>
     */
    private array $activeConnections = [];

    public function handle(): int
    {
        try {
            $this->activeConnections = $this->resolveConnections();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $tables = $this->resolveTables();
        $this->info(sprintf('掃描範圍：%d connections × %d tables', count($this->activeConnections), count($tables)));

        $schemas = $this->fetchSchemas($tables);
        $report = $this->detectDrifts($tables, $schemas, !$this->option('no-indexes'));

        $this->info(sprintf(
            'Drift summary：%d tables table-level 缺失，%d tables column/index drift，%d views drift，%d triggers drift，%d routines drift',
            count($report['table_missing']),
            count($report['column_drift']),
            count($report['view_drift'] ?? []),
            count($report['trigger_drift'] ?? []),
            count($report['routine_drift'] ?? [])
        ));

        $savePath = $this->option('save');
        if (is_string($savePath) && $savePath !== '') {
            $markdown = $this->renderMarkdown($tables, $schemas, $report);
            $dir = dirname($savePath);
            if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                $this->error("無法建立輸出目錄：{$dir}");

                return self::FAILURE;
            }
            if (file_put_contents($savePath, $markdown) === false) {
                $this->error("無法寫入 {$savePath}");

                return self::FAILURE;
            }
            $this->info("已寫入 {$savePath}");
        } else {
            $this->renderTable($tables, $schemas, $report);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string> display name => connection key
     *
     * @throws \InvalidArgumentException 當 --connections 含未知 display name
     */
    private function resolveConnections(): array
    {
        $custom = $this->option('connections');
        if (!is_string($custom) || $custom === '') {
            return self::CONNECTIONS;
        }

        $requested = array_values(array_filter(array_map('trim', explode(',', $custom))));
        $unknown = array_diff($requested, array_keys(self::CONNECTIONS));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                '未知的 connection display name：%s（可用：%s）',
                implode(', ', $unknown),
                implode(', ', array_keys(self::CONNECTIONS))
            ));
        }

        $resolved = [];
        foreach ($requested as $displayName) {
            $resolved[$displayName] = self::CONNECTIONS[$displayName];
        }

        return $resolved;
    }

    /**
     * @return string[]
     */
    private function resolveTables(): array
    {
        if ($this->option('all')) {
            return $this->fetchAllTablesUnion();
        }

        $custom = $this->option('tables');
        if (is_string($custom) && $custom !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $custom))));
        }

        return self::PHASE_1_TABLES;
    }

    /**
     * @return string[]
     */
    private function fetchAllTablesUnion(): array
    {
        $all = [];
        foreach ($this->activeConnections as $displayName => $connectionKey) {
            try {
                $rows = DB::connection($connectionKey)->select(
                    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
                );
                foreach ($rows as $row) {
                    $all[$row->TABLE_NAME] = true;
                }
            } catch (Throwable $e) {
                $this->warn("[{$displayName}] 無法取得 table list：{$e->getMessage()}");
            }
        }
        ksort($all);

        return array_keys($all);
    }

    /**
     * @param  string[]  $tables
     * @return array<string, array{columns: array<string, array<string, array{type:string,nullable:string,default:?string,extra:string,generation:string,position:int}>>, indexes: array<string, array<string, array{columns:string[],unique:bool}>>, failed: bool}>
     */
    private function fetchSchemas(array $tables): array
    {
        $result = [];
        foreach ($this->activeConnections as $displayName => $connectionKey) {
            try {
                $result[$displayName] = [
                    'columns' => $this->fetchColumns($connectionKey, $tables),
                    'indexes' => $this->option('no-indexes') ? [] : $this->fetchIndexes($connectionKey, $tables),
                    'views' => $this->option('views') ? $this->fetchViews($connectionKey) : [],
                    'triggers' => $this->option('triggers') ? $this->fetchTriggers($connectionKey) : [],
                    'routines' => $this->option('routines') ? $this->fetchRoutines($connectionKey) : [],
                    'failed' => false,
                ];
            } catch (Throwable $e) {
                $this->warn("[{$displayName}] CONNECTION_FAILED：{$e->getMessage()}");
                $result[$displayName] = ['columns' => [], 'indexes' => [], 'views' => [], 'triggers' => [], 'routines' => [], 'failed' => true];
            }
        }

        return $result;
    }

    /**
     * @param  string[]  $tables
     * @return array<string, array<string, array{type:string,nullable:string,default:?string,extra:string,generation:string,position:int}>>
     */
    private function fetchColumns(string $connection, array $tables): array
    {
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $rows = DB::connection($connection)->select(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION, ORDINAL_POSITION
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})
             ORDER BY TABLE_NAME, ORDINAL_POSITION",
            $tables
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row->TABLE_NAME][$row->COLUMN_NAME] = [
                'type' => $row->COLUMN_TYPE,
                'nullable' => $row->IS_NULLABLE,
                'default' => $row->COLUMN_DEFAULT,
                'extra' => $row->EXTRA,
                'generation' => (string) ($row->GENERATION_EXPRESSION ?? ''),
                'position' => (int) $row->ORDINAL_POSITION,
            ];
        }

        return $map;
    }

    /**
     * @param  string[]  $tables
     * @return array<string, array<string, array{columns:string[],unique:bool}>>
     */
    private function fetchIndexes(string $connection, array $tables): array
    {
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $rows = DB::connection($connection)->select(
            "SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})
             ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
            $tables
        );

        $map = [];
        foreach ($rows as $row) {
            $key = $row->TABLE_NAME;
            $idx = $row->INDEX_NAME;
            if (!isset($map[$key][$idx])) {
                $map[$key][$idx] = [
                    'columns' => [],
                    'unique' => (int) $row->NON_UNIQUE === 0,
                ];
            }
            $map[$key][$idx]['columns'][] = $row->COLUMN_NAME;
        }

        return $map;
    }

    /**
     * 撈 view 列表 + 定義 SHA256 hash 當簽章。
     *
     * @return array<string, string> view_name => sha256(view_definition)
     */
    private function fetchViews(string $connection): array
    {
        $rows = DB::connection($connection)->select(
            'SELECT TABLE_NAME, VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row->TABLE_NAME] = hash('sha256', (string) $row->VIEW_DEFINITION);
        }

        return $map;
    }

    /**
     * 撈 trigger 列表 + 簽章：TIMING EVENT ON TABLE | sha256(action_statement) 前 12 字。
     *
     * @return array<string, string> trigger_name => signature
     */
    private function fetchTriggers(string $connection): array
    {
        $rows = DB::connection($connection)->select(
            'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT
             FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row->TRIGGER_NAME] = sprintf(
                '%s %s ON %s | sha256:%s',
                $row->ACTION_TIMING,
                $row->EVENT_MANIPULATION,
                $row->EVENT_OBJECT_TABLE,
                substr(hash('sha256', (string) $row->ACTION_STATEMENT), 0, 12)
            );
        }

        return $map;
    }

    /**
     * 撈 stored procedure / function 列表 + 簽章。
     *
     * 簽章 = ROUTINE_TYPE | sha256(routine_definition) 前 12 字
     * 預設過濾 ad-hoc 牌（_bak / _test / _mars / _neil / 日期後綴 等），
     * --include-ad-hoc-routines flag 開啟時不過濾。
     *
     * @return array<string, string> routine_name => signature
     */
    private function fetchRoutines(string $connection): array
    {
        $rows = DB::connection($connection)->select(
            'SELECT ROUTINE_NAME, ROUTINE_TYPE, ROUTINE_DEFINITION
             FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_TYPE, ROUTINE_NAME'
        );

        $includeAdHoc = (bool) $this->option('include-ad-hoc-routines');
        $map = [];
        foreach ($rows as $row) {
            if (!$includeAdHoc && $this->isAdHocRoutine($row->ROUTINE_NAME)) {
                continue;
            }
            $map[$row->ROUTINE_NAME] = sprintf(
                '%s | sha256:%s',
                $row->ROUTINE_TYPE,
                substr(hash('sha256', (string) $row->ROUTINE_DEFINITION), 0, 12)
            );
        }

        return $map;
    }

    /**
     * 名稱比對 AD_HOC_ROUTINE_PATTERNS，命中視為個人 ad-hoc 實驗 SP，預設不納入 audit。
     */
    private function isAdHocRoutine(string $name): bool
    {
        foreach (self::AD_HOC_ROUTINE_PATTERNS as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string[]  $tables
     * @param  array<string, array{columns: array, indexes: array, failed: bool}>  $schemas
     * @param  bool  $includeIndexes  是否比對 index drift；handle() 從 `--no-indexes` flag 推導，
     *                                synthetic test 直接呼叫時用預設 true 即可（避免依賴 command
     *                                input lifecycle 造成 `Call to a member function getOption() on null`）。
     * @return array{
     *     table_missing: array<string, array{missing_in: string[], present_in: string[]}>,
     *     column_drift: array<string, array{
     *         columns: array<string, array<string, ?string>>,
     *         indexes: array<string, array<string, ?string>>,
     *     }>,
     * }
     */
    public function detectDrifts(array $tables, array $schemas, bool $includeIndexes = true): array
    {
        $tableMissing = [];
        $columnDrift = [];

        foreach ($tables as $table) {
            $presence = $this->detectTablePresence($table, $schemas);

            // Table-level 缺失：整張表只存在於部分 connection 時，不展開 column/index drift，
            // 避免一張表缺失被渲染成 N 條 column-level missing 的灌水誤導
            // （Codex adversarial review 2026-05-22 #1）。
            if ($presence['missing_in'] !== []) {
                $tableMissing[$table] = $presence;

                continue;
            }

            $columnDrifts = $this->detectColumnDrifts($table, $schemas);
            $indexDrifts = $includeIndexes ? $this->detectIndexDrifts($table, $schemas) : [];

            if ($columnDrifts === [] && $indexDrifts === []) {
                continue;
            }

            $columnDrift[$table] = [
                'columns' => $columnDrifts,
                'indexes' => $indexDrifts,
            ];
        }

        return [
            'table_missing' => $tableMissing,
            'column_drift' => $columnDrift,
            'view_drift' => $this->detectViewDrift($schemas),
            'trigger_drift' => $this->detectTriggerDrift($schemas),
            'routine_drift' => $this->detectRoutineDrift($schemas),
        ];
    }

    /**
     * 比對所有 connection 的 view 簽章；只列有 drift（簽章不一致或某 connection 缺）的 view。
     *
     * @param  array<string, array{columns: array, indexes: array, views?: array, failed: bool}>  $schemas
     * @return array<string, array<string, ?string>> view_name => connection => hash|null|'__FAILED__'
     */
    private function detectViewDrift(array $schemas): array
    {
        $hasViews = false;
        foreach ($schemas as $data) {
            if (!$data['failed'] && isset($data['views'])) {
                $hasViews = true;
                break;
            }
        }
        if (!$hasViews) {
            return [];
        }

        $viewUnion = [];
        foreach ($schemas as $data) {
            foreach (array_keys($data['views'] ?? []) as $view) {
                $viewUnion[$view] = true;
            }
        }

        $drifts = [];
        foreach (array_keys($viewUnion) as $view) {
            $row = [];
            foreach ($schemas as $displayName => $data) {
                if ($data['failed']) {
                    $row[$displayName] = '__FAILED__';

                    continue;
                }
                $row[$displayName] = $data['views'][$view] ?? null;
            }
            if ($this->isDriftedRow($row)) {
                $drifts[$view] = $row;
            }
        }

        return $drifts;
    }

    /**
     * 比對所有 connection 的 trigger 簽章；只列有 drift（簽章不一致或某 connection 缺）的 trigger。
     *
     * @param  array<string, array{columns: array, indexes: array, views?: array, triggers?: array, failed: bool}>  $schemas
     * @return array<string, array<string, ?string>> trigger_name => connection => signature|null|'__FAILED__'
     */
    private function detectTriggerDrift(array $schemas): array
    {
        $hasTriggers = false;
        foreach ($schemas as $data) {
            if (!$data['failed'] && isset($data['triggers'])) {
                $hasTriggers = true;
                break;
            }
        }
        if (!$hasTriggers) {
            return [];
        }

        $union = [];
        foreach ($schemas as $data) {
            foreach (array_keys($data['triggers'] ?? []) as $name) {
                $union[$name] = true;
            }
        }

        $drifts = [];
        foreach (array_keys($union) as $name) {
            $row = [];
            foreach ($schemas as $displayName => $data) {
                if ($data['failed']) {
                    $row[$displayName] = '__FAILED__';

                    continue;
                }
                $row[$displayName] = $data['triggers'][$name] ?? null;
            }
            if ($this->isDriftedRow($row)) {
                $drifts[$name] = $row;
            }
        }

        return $drifts;
    }

    /**
     * 比對所有 connection 的 routine 簽章；只列有 drift（簽章不一致或某 connection 缺）的 routine。
     *
     * @param  array<string, array{columns: array, indexes: array, views?: array, triggers?: array, routines?: array, failed: bool}>  $schemas
     * @return array<string, array<string, ?string>> routine_name => connection => signature|null|'__FAILED__'
     */
    private function detectRoutineDrift(array $schemas): array
    {
        $hasRoutines = false;
        foreach ($schemas as $data) {
            if (!$data['failed'] && isset($data['routines'])) {
                $hasRoutines = true;
                break;
            }
        }
        if (!$hasRoutines) {
            return [];
        }

        $union = [];
        foreach ($schemas as $data) {
            foreach (array_keys($data['routines'] ?? []) as $name) {
                $union[$name] = true;
            }
        }

        $drifts = [];
        foreach (array_keys($union) as $name) {
            $row = [];
            foreach ($schemas as $displayName => $data) {
                if ($data['failed']) {
                    $row[$displayName] = '__FAILED__';

                    continue;
                }
                $row[$displayName] = $data['routines'][$name] ?? null;
            }
            if ($this->isDriftedRow($row)) {
                $drifts[$name] = $row;
            }
        }

        return $drifts;
    }

    /**
     * 判定 table 在哪些 connection 存在 / 缺失（連線失敗的不算）。
     *
     * - 表在所有非失敗 connection 都不存在 → missing_in 為空（從報表略過）
     * - 表在所有非失敗 connection 都存在     → missing_in 為空（不是 table-level drift）
     * - 表只在部分非失敗 connection 存在     → missing_in 為缺表名單，present_in 為存在名單
     *
     * @param  array<string, array{columns: array, indexes: array, failed: bool}>  $schemas
     * @return array{missing_in: string[], present_in: string[]}
     */
    private function detectTablePresence(string $table, array $schemas): array
    {
        $present = [];
        $absent = [];
        foreach ($schemas as $displayName => $data) {
            if ($data['failed']) {
                continue;
            }
            if (isset($data['columns'][$table])) {
                $present[] = $displayName;
            } else {
                $absent[] = $displayName;
            }
        }
        if ($present === [] || $absent === []) {
            return ['missing_in' => [], 'present_in' => $present];
        }

        return ['missing_in' => $absent, 'present_in' => $present];
    }

    /**
     * @param  array<string, array{columns: array, indexes: array, failed: bool}>  $schemas
     * @return array<string, array<string, ?string>> column => connection => signature|null
     */
    private function detectColumnDrifts(string $table, array $schemas): array
    {
        $columnUnion = [];
        foreach ($schemas as $data) {
            foreach (array_keys($data['columns'][$table] ?? []) as $col) {
                $columnUnion[$col] = true;
            }
        }

        $drifts = [];
        foreach (array_keys($columnUnion) as $col) {
            $row = [];
            foreach ($schemas as $displayName => $data) {
                if ($data['failed']) {
                    $row[$displayName] = '__FAILED__';

                    continue;
                }
                $meta = $data['columns'][$table][$col] ?? null;
                $row[$displayName] = $meta === null ? null : $this->columnSignature($meta);
            }
            if ($this->isDriftedRow($row)) {
                $drifts[$col] = $row;
            }
        }

        return $drifts;
    }

    /**
     * @param  array<string, array{columns: array, indexes: array, failed: bool}>  $schemas
     * @return array<string, array<string, ?string>> index_name => connection => signature|null
     */
    private function detectIndexDrifts(string $table, array $schemas): array
    {
        $indexUnion = [];
        foreach ($schemas as $data) {
            foreach (array_keys($data['indexes'][$table] ?? []) as $idx) {
                $indexUnion[$idx] = true;
            }
        }

        $drifts = [];
        foreach (array_keys($indexUnion) as $idx) {
            $row = [];
            foreach ($schemas as $displayName => $data) {
                if ($data['failed']) {
                    $row[$displayName] = '__FAILED__';

                    continue;
                }
                $meta = $data['indexes'][$table][$idx] ?? null;
                $row[$displayName] = $meta === null ? null : $this->indexSignature($meta);
            }
            if ($this->isDriftedRow($row)) {
                $drifts[$idx] = $row;
            }
        }

        return $drifts;
    }

    /**
     * @param  array{type:string,nullable:string,default:?string,extra:string,generation:string,position:int}  $meta
     *
     * @internal exposed for synthetic test
     */
    public function columnSignature(array $meta): string
    {
        return sprintf(
            '%s | %s | DEFAULT %s | %s | AS %s',
            $meta['type'],
            $meta['nullable'] === 'YES' ? 'NULL' : 'NOT NULL',
            $meta['default'] === null ? 'NULL' : "'{$meta['default']}'",
            $meta['extra'] === '' ? '-' : $meta['extra'],
            $meta['generation'] === '' ? '-' : $meta['generation']
        );
    }

    /**
     * @param  array{columns:string[],unique:bool}  $meta
     *
     * @internal exposed for synthetic test
     */
    public function indexSignature(array $meta): string
    {
        return ($meta['unique'] ? 'UNIQUE' : 'INDEX') . ' (' . implode(', ', $meta['columns']) . ')';
    }

    /**
     * 所有非 __FAILED__ / 非 null 值都一樣且沒有 null，就不是 drift。
     *
     * @param  array<string, ?string>  $row  connection => signature|null|'__FAILED__'
     */
    private function isDriftedRow(array $row): bool
    {
        $signatures = [];
        foreach ($row as $value) {
            if ($value === '__FAILED__') {
                continue;
            }
            $signatures[$value ?? '__MISSING__'] = true;
        }

        return count($signatures) > 1;
    }

    /**
     * @param  string[]  $tables
     * @param  array<string, array{columns: array, indexes: array, failed: bool}>  $schemas
     * @param  array{
     *     table_missing: array<string, array{missing_in: string[], present_in: string[]}>,
     *     column_drift: array<string, array{columns: array, indexes: array}>
     * }  $report
     */
    private function renderMarkdown(array $tables, array $schemas, array $report): string
    {
        $displayNames = array_keys($this->activeConnections);

        $lines = [];
        $lines[] = '# Schema Drift Audit — ' . now()->format('Y-m-d H:i');
        $lines[] = '';
        $lines[] = sprintf('掃描 %d connections / %d tables', count($displayNames), count($tables));

        $failedConns = array_keys(array_filter($schemas, fn ($s) => $s['failed']));
        if ($failedConns !== []) {
            $lines[] = '';
            $lines[] = '> ⚠️ 連線失敗 connections：`' . implode('`, `', $failedConns) . '`';
        }

        $totalColumnDrifts = array_sum(array_map(fn ($r) => count($r['columns']), $report['column_drift']));
        $totalIndexDrifts = array_sum(array_map(fn ($r) => count($r['indexes']), $report['column_drift']));

        $lines[] = '';
        $lines[] = 'Drift summary：';
        $lines[] = sprintf('- Table-level 缺失：%d tables（整張表只存在於部分 connection）', count($report['table_missing']));
        $lines[] = sprintf(
            '- Column / Index 漂移：%d tables（%d column drifts，%d index drifts）',
            count($report['column_drift']),
            $totalColumnDrifts,
            $totalIndexDrifts
        );

        $driftedTables = array_merge(array_keys($report['table_missing']), array_keys($report['column_drift']));
        $cleanTables = array_values(array_diff($tables, $driftedTables));
        if ($cleanTables !== []) {
            $lines[] = '';
            $lines[] = '未漂移 ' . count($cleanTables) . ' tables：`' . implode('`, `', $cleanTables) . '`';
        }

        if ($report['table_missing'] !== []) {
            $lines[] = '';
            $lines[] = '## Table-level 缺失';
            $lines[] = '';
            $lines[] = '| Table | 存在於 | 缺於 |';
            $lines[] = '|---|---|---|';
            foreach ($report['table_missing'] as $table => $info) {
                $lines[] = sprintf(
                    '| `%s` | %s | %s |',
                    $table,
                    implode(', ', $info['present_in']) ?: '—',
                    implode(', ', $info['missing_in']) ?: '—'
                );
            }
            $lines[] = '';
            $lines[] = '> 補救：到缺表 connection 跑對應 `create_*` migration；確認該 tenant 是否本來就不應有此表後再執行。';
        }

        if ($report['column_drift'] !== []) {
            $lines[] = '';
            $lines[] = '## Column / Index 漂移';

            foreach ($report['column_drift'] as $table => $drift) {
                $lines[] = '';
                $lines[] = '### ' . $table;
                $lines[] = '';
                $lines[] = '#### Columns';
                $lines[] = '';
                if ($drift['columns'] === []) {
                    $lines[] = '（無 drift）';
                } else {
                    $lines = array_merge($lines, $this->renderDriftTable('Column', $drift['columns'], $displayNames));
                }

                if (!$this->option('no-indexes')) {
                    $lines[] = '';
                    $lines[] = '#### Indexes';
                    $lines[] = '';
                    if ($drift['indexes'] === []) {
                        $lines[] = '（無 drift）';
                    } else {
                        $lines = array_merge($lines, $this->renderDriftTable('Index', $drift['indexes'], $displayNames));
                    }
                }
            }
        }

        if (($report['view_drift'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = '## View 漂移';
            $lines[] = '';
            $lines = array_merge($lines, $this->renderDriftTable('View', $report['view_drift'], $displayNames));
        }

        if (($report['trigger_drift'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = '## Trigger 漂移';
            $lines[] = '';
            $lines = array_merge($lines, $this->renderDriftTable('Trigger', $report['trigger_drift'], $displayNames));
        }

        if (($report['routine_drift'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = '## Routine (SP/Function) 漂移';
            $lines[] = '';
            if (!$this->option('include-ad-hoc-routines')) {
                $lines[] = '> 已套用預設 ad-hoc 過濾（*_bak / *_test / *_mars / *_neil 等個人實驗 SP 不計入；用 --include-ad-hoc-routines 可看全部）';
                $lines[] = '';
            }
            $lines = array_merge($lines, $this->renderDriftTable('Routine', $report['routine_drift'], $displayNames));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param  array<string, array<string, ?string>>  $drifts  name => connection => signature|null|'__FAILED__'
     * @param  string[]  $displayNames
     * @return string[]
     */
    private function renderDriftTable(string $headerLabel, array $drifts, array $displayNames): array
    {
        $header = '| ' . $headerLabel . ' | ' . implode(' | ', $displayNames) . ' |';
        $sep = '|---|' . str_repeat('---|', count($displayNames));
        $rows = [$header, $sep];

        foreach ($drifts as $name => $row) {
            $baseline = $this->pickBaseline($row);
            $cells = [];
            foreach ($displayNames as $conn) {
                $cells[] = $this->renderCell($row[$conn] ?? null, $baseline);
            }
            $firstCol = sprintf(
                '`%s` <br/>baseline: `%s`',
                $name,
                $baseline === null ? '—' : $this->escapePipe($baseline),
            );
            $rows[] = '| ' . $firstCol . ' | ' . implode(' | ', $cells) . ' |';
        }

        return $rows;
    }

    /**
     * @param  array<string, ?string>  $row
     */
    private function pickBaseline(array $row): ?string
    {
        foreach ($row as $value) {
            if ($value !== null && $value !== '__FAILED__') {
                return $value;
            }
        }

        return null;
    }

    private function renderCell(?string $value, ?string $baseline): string
    {
        if ($value === '__FAILED__') {
            return '[CONNECTION_FAILED]';
        }
        if ($value === null) {
            return '✗ MISSING';
        }
        if ($value === $baseline) {
            return '✓';
        }

        return '`' . $this->escapePipe($value) . '`';
    }

    /**
     * Markdown table cell 內的 `|` 必須 escape 否則會被當作 column 分隔符。
     * signature 用 `|` 作為內部 field separator，渲染前必先 escape。
     */
    private function escapePipe(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }

    /**
     * stdout 輸出：用 Symfony Console Table（artisan 內建）一張一張 table 印，
     * 比 markdown 在 terminal 內好讀。檔案輸出仍走 renderMarkdown + --save。
     *
     * @param  string[]  $tables
     * @param  array<string, array{columns: array, indexes: array, failed: bool}>  $schemas
     * @param  array{
     *     table_missing: array<string, array{missing_in: string[], present_in: string[]}>,
     *     column_drift: array<string, array{columns: array, indexes: array}>
     * }  $report
     */
    private function renderTable(array $tables, array $schemas, array $report): void
    {
        $displayNames = array_keys($this->activeConnections);

        $failedConns = array_keys(array_filter($schemas, fn ($s) => $s['failed']));
        if ($failedConns !== []) {
            $this->newLine();
            $this->warn('連線失敗 connections：' . implode(', ', $failedConns));
        }

        $totalColumnDrifts = array_sum(array_map(fn ($r) => count($r['columns']), $report['column_drift']));
        $totalIndexDrifts = array_sum(array_map(fn ($r) => count($r['indexes']), $report['column_drift']));

        $driftedTables = array_merge(array_keys($report['table_missing']), array_keys($report['column_drift']));
        $cleanTables = array_values(array_diff($tables, $driftedTables));

        $this->newLine();
        $this->line(sprintf(
            '<info>Drift summary</info>：Table-level 缺失 <comment>%d</comment> tables，Column/Index 漂移 <comment>%d</comment> tables（%d column drifts，%d index drifts），View 漂移 <comment>%d</comment>，Trigger 漂移 <comment>%d</comment>，Routine 漂移 <comment>%d</comment>，未漂移 <comment>%d</comment> tables',
            count($report['table_missing']),
            count($report['column_drift']),
            $totalColumnDrifts,
            $totalIndexDrifts,
            count($report['view_drift'] ?? []),
            count($report['trigger_drift'] ?? []),
            count($report['routine_drift'] ?? []),
            count($cleanTables),
        ));

        if ($cleanTables !== []) {
            $this->line('<fg=gray>未漂移：' . implode(', ', $cleanTables) . '</>');
        }

        if ($report['table_missing'] !== []) {
            $this->newLine();
            $this->info('## Table-level 缺失');
            $this->table(
                ['Table', '存在於', '缺於'],
                array_map(
                    fn (string $table, array $info) => [
                        $table,
                        implode(', ', $info['present_in']) ?: '—',
                        implode(', ', $info['missing_in']) ?: '—',
                    ],
                    array_keys($report['table_missing']),
                    array_values($report['table_missing']),
                ),
            );
            $this->line('<fg=gray>補救：到缺表 connection 跑對應 create_* migration。</>');
        }

        if ($report['column_drift'] !== []) {
            $this->newLine();
            $this->info('## Column / Index 漂移');

            foreach ($report['column_drift'] as $table => $drift) {
                $this->newLine();
                $this->line('<fg=cyan;options=bold>### ' . $table . '</>');

                if ($drift['columns'] !== []) {
                    $this->line('<fg=yellow>Columns</>');
                    $this->table(
                        array_merge(['Column'], $displayNames),
                        $this->buildTableRows($drift['columns'], $displayNames),
                    );
                }

                if (!$this->option('no-indexes') && $drift['indexes'] !== []) {
                    $this->line('<fg=yellow>Indexes</>');
                    $this->table(
                        array_merge(['Index'], $displayNames),
                        $this->buildTableRows($drift['indexes'], $displayNames),
                    );
                }
            }
        }

        if (($report['view_drift'] ?? []) !== []) {
            $this->newLine();
            $this->info('## View 漂移');
            $this->table(
                array_merge(['View'], $displayNames),
                $this->buildTableRows($report['view_drift'], $displayNames),
            );
        }

        if (($report['trigger_drift'] ?? []) !== []) {
            $this->newLine();
            $this->info('## Trigger 漂移');
            $this->table(
                array_merge(['Trigger'], $displayNames),
                $this->buildTableRows($report['trigger_drift'], $displayNames),
            );
        }

        if (($report['routine_drift'] ?? []) !== []) {
            $this->newLine();
            $this->info('## Routine (SP/Function) 漂移');
            if (!$this->option('include-ad-hoc-routines')) {
                $this->line('<fg=gray>（已套用預設 ad-hoc 過濾：*_bak / *_test / *_mars / *_neil 等個人實驗 SP 不計入；用 --include-ad-hoc-routines 可看全部）</>');
            }
            $this->table(
                array_merge(['Routine'], $displayNames),
                $this->buildTableRows($report['routine_drift'], $displayNames),
            );
        }
    }

    /**
     * @param  array<string, array<string, ?string>>  $drifts  name => connection => signature|null|'__FAILED__'
     * @param  string[]  $displayNames
     * @return list<array<int, string>>
     */
    private function buildTableRows(array $drifts, array $displayNames): array
    {
        $rows = [];
        foreach ($drifts as $name => $row) {
            $baseline = $this->pickBaseline($row);
            $cells = [$name];
            foreach ($displayNames as $conn) {
                $cells[] = $this->renderTableCell($row[$conn] ?? null, $baseline);
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    private function renderTableCell(?string $value, ?string $baseline): string
    {
        if ($value === '__FAILED__') {
            return '<fg=red>[FAILED]</>';
        }
        if ($value === null) {
            return '<fg=red>✗ MISSING</>';
        }
        if ($value === $baseline) {
            return '<fg=green>✓</>';
        }

        return $value;
    }
}
