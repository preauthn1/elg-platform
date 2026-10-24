<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * 比對兩個 Laravel database connection 底下，所有「實體資料表」的欄位定義差異（MySQL information_schema）。
 *
 * 表名以底線分段後，若任一段為 bak／baks（不分大小寫）、bak／baks 後接數字（如 bak260217）、或整段為數字，該表不納入比對。
 *
 * php artisan db:compare-schema Yes2win testing
 * php artisan db:compare-schema Yes2win testing --only-column-presence
 * php artisan db:compare-schema TPower Yes2Win --only-column-presence --tables-like=shareholder
 */
class CompareDatabaseSchemaCommand extends Command
{
    protected $signature = 'db:compare-schema
                            {from : 來源 connection 名稱（config/database.php）}
                            {to : 目標 connection 名稱（config/database.php）}
                            {--ignore-tables= : 略過的資料表（逗號分隔）}
                            {--table= : 僅比對單一資料表}
                            {--tables-like= : 僅比對表名含任一關鍵字（逗號分隔，不分大小寫）}
                            {--only-column-presence : 僅比對欄位有無（多出／缺少），略過同名欄位定義差異}';

    protected $description = '比對兩個 MySQL 資料庫所有實體資料表的欄位差異（略過表名分段為 bak／baks、bak+數字／baks+數字、或純數字之備份類資料表；可選僅比對欄位有無，或含型別／預設值等完整定義）';

    public function handle(): int
    {
        $connections = $this->resolveConnectionsOrReport();

        if ($connections === null) {
            return Command::FAILURE;
        }

        [$from, $to] = $connections;

        $columnSchemas = $this->fetchColumnSchemasOrReport(from: $from, to: $to);

        if ($columnSchemas === null) {
            return Command::FAILURE;
        }

        [$left, $right] = $columnSchemas;

        $this->writeConnectionDatabases(from: $from, to: $to);

        $onlyColumnPresence = (bool) $this->option('only-column-presence');
        $this->writeOnlyColumnPresenceModeHint(onlyColumnPresence: $onlyColumnPresence);

        $diff = self::compareSchemaColumns(
            leftColumnsByTable: $left,
            rightColumnsByTable: $right,
            onlyColumnPresence: $onlyColumnPresence,
        );
        $this->writeSchemaDiffReport(diff: $diff, onlyColumnPresence: $onlyColumnPresence);

        return $this->resolveExitCode(diff: $diff, onlyColumnPresence: $onlyColumnPresence);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function resolveConnectionsOrReport(): ?array
    {
        try {
            $from = $this->resolveConnectionName((string) $this->argument('from'));
            $to = $this->resolveConnectionName((string) $this->argument('to'));

            return [$from, $to];
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    /**
     * @return array{0: array<string, array<string, array{fingerprint: string, label: string}>>, 1: array<string, array<string, array{fingerprint: string, label: string}>>}|null
     */
    private function fetchColumnSchemasOrReport(string $from, string $to): ?array
    {
        try {
            $ignoreTables = $this->parseTableList((string) $this->option('ignore-tables'));
            $onlyTable = $this->option('table') !== null && $this->option('table') !== ''
                ? (string) $this->option('table')
                : null;
            $tablesLike = $this->parseTableList((string) $this->option('tables-like'));

            if ($onlyTable !== null && $tablesLike !== []) {
                throw new InvalidArgumentException('不可同時使用 --table 與 --tables-like');
            }

            $left = $this->fetchBaseTableColumns($from, $ignoreTables, $onlyTable, $tablesLike);
            $right = $this->fetchBaseTableColumns($to, $ignoreTables, $onlyTable, $tablesLike);

            return [$left, $right];
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    private function writeConnectionDatabases(string $from, string $to): void
    {
        $this->line(sprintf(
            '來源 [%s] database: <info>%s</info>',
            $from,
            (string) config("database.connections.{$from}.database")
        ));
        $this->line(sprintf(
            '目標 [%s] database: <info>%s</info>',
            $to,
            (string) config("database.connections.{$to}.database")
        ));
        $this->newLine();
    }

    private function writeOnlyColumnPresenceModeHint(bool $onlyColumnPresence): void
    {
        if (!$onlyColumnPresence) {
            return;
        }

        $this->comment('模式：僅比對欄位有無（略過同名欄位定義差異）。');
        $this->newLine();
    }

    /**
     * @param  array{
     *     tables_only_left: list<string>,
     *     tables_only_right: list<string>,
     *     tables: array<string, array{
     *         columns_only_left: list<string>,
     *         columns_only_right: list<string>,
     *         columns_mismatch: array<string, array{left: string, right: string}>
     *     }>
     * }  $diff
     */
    private function writeSchemaDiffReport(array $diff, bool $onlyColumnPresence): void
    {
        $this->writeTablesOnlyOnSide(
            tables: $diff['tables_only_left'],
            heading: '僅存在於來源 connection 的資料表:',
        );
        $this->writeTablesOnlyOnSide(
            tables: $diff['tables_only_right'],
            heading: '僅存在於目標 connection 的資料表:',
        );

        foreach ($diff['tables'] as $table => $detail) {
            $this->writeSingleTableDiff(
                table: $table,
                detail: $detail,
                onlyColumnPresence: $onlyColumnPresence,
            );
        }
    }

    /**
     * @param  list<string>  $tables
     */
    private function writeTablesOnlyOnSide(array $tables, string $heading): void
    {
        if ($tables === []) {
            return;
        }

        $this->warn($heading);

        foreach ($tables as $tableName) {
            $this->line('  - ' . $tableName);
        }

        $this->newLine();
    }

    /**
     * @param  array{
     *     columns_only_left: list<string>,
     *     columns_only_right: list<string>,
     *     columns_mismatch: array<string, array{left: string, right: string}>
     * }  $detail
     */
    private function writeSingleTableDiff(string $table, array $detail, bool $onlyColumnPresence): void
    {
        $this->line("<fg=cyan>資料表: {$table}</>");

        $this->writeColumnNameList(
            title: '  僅來源有的欄位:',
            columns: $detail['columns_only_left'],
            prefix: '    - ',
        );
        $this->writeColumnNameList(
            title: '  僅目標有的欄位:',
            columns: $detail['columns_only_right'],
            prefix: '    + ',
        );

        if (!$onlyColumnPresence && $detail['columns_mismatch'] !== []) {
            $this->line('  欄位定義不一致:');

            foreach ($detail['columns_mismatch'] as $column => $pair) {
                $this->line("    <fg=yellow>{$column}</>");
                $this->line('      來源: ' . $pair['left']);
                $this->line('      目標: ' . $pair['right']);
            }
        }

        $this->newLine();
    }

    /**
     * @param  list<string>  $columns
     */
    private function writeColumnNameList(string $title, array $columns, string $prefix): void
    {
        if ($columns === []) {
            return;
        }

        $this->line($title);

        foreach ($columns as $c) {
            $this->line($prefix . $c);
        }
    }

    /**
     * @param  array{
     *     tables_only_left: list<string>,
     *     tables_only_right: list<string>,
     *     tables: array<string, mixed>
     * }  $diff
     */
    private function resolveExitCode(array $diff, bool $onlyColumnPresence): int
    {
        $hasStructuralDiff = $diff['tables_only_left'] !== []
            || $diff['tables_only_right'] !== []
            || $diff['tables'] !== [];

        if (!$hasStructuralDiff) {
            $this->info($onlyColumnPresence
                ? '兩邊實體資料表與欄位集合一致（在比對範圍內；未檢查同名欄位定義）。'
                : '兩邊實體資料表與欄位定義一致（在比對範圍內）。');

            return Command::SUCCESS;
        }

        $this->warn($onlyColumnPresence
            ? '比對完成：發現資料表或欄位有無上的差異。'
            : '比對完成：發現差異。');

        return Command::FAILURE;
    }

    /**
     * @param  list<string>  $ignoreTables
     * @param  list<string>  $tablesLike  表名須含任一關鍵字（不分大小寫）
     * @return array<string, array<string, array{fingerprint: string, label: string}>>
     *                                                                                 鍵名不含表名以 `_` 分段後任一段為 `bak`／`baks`（不分大小寫）、`bak`／`baks` 後接數字、或全為數字之資料表
     */
    private function fetchBaseTableColumns(
        string $connection,
        array $ignoreTables,
        ?string $onlyTable,
        array $tablesLike = [],
    ): array {
        $config = config("database.connections.{$connection}");

        if (!is_array($config)) {
            throw new InvalidArgumentException(sprintf('找不到 database connection: %s', $connection));
        }

        $driver = $config['driver'] ?? '';

        if ($driver !== 'mysql') {
            throw new InvalidArgumentException(sprintf('目前僅支援 mysql driver，[%s] 為 %s', $connection, (string) $driver));
        }

        $database = trim((string) ($config['database'] ?? ''));

        if ($database === '') {
            throw new InvalidArgumentException(sprintf('Connection [%s] 未設定 database 名稱', $connection));
        }

        $bindings = [$database];
        $whereOnlyTable = '';

        if ($onlyTable !== null) {
            $whereOnlyTable = ' AND c.TABLE_NAME = ?';
            $bindings[] = $onlyTable;
        }

        $whereIgnore = '';

        foreach ($ignoreTables as $ignored) {
            $whereIgnore .= ' AND c.TABLE_NAME <> ?';
            $bindings[] = $ignored;
        }

        $whereTablesLike = self::buildTablesLikeSql($tablesLike, $bindings);

        $sql = <<<SQL
            SELECT
                c.TABLE_NAME,
                c.COLUMN_NAME,
                c.ORDINAL_POSITION,
                c.COLUMN_DEFAULT,
                c.IS_NULLABLE,
                c.COLUMN_TYPE,
                c.EXTRA,
                c.COLUMN_KEY,
                c.COLLATION_NAME,
                c.COLUMN_COMMENT
            FROM information_schema.COLUMNS AS c
            INNER JOIN information_schema.TABLES AS t
                ON t.TABLE_SCHEMA = c.TABLE_SCHEMA
                AND t.TABLE_NAME = c.TABLE_NAME
            WHERE c.TABLE_SCHEMA = ?
                AND t.TABLE_TYPE = 'BASE TABLE'
                {$whereOnlyTable}
                {$whereIgnore}
                {$whereTablesLike}
            ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION
            SQL;

        $rows = DB::connection($connection)->select($sql, $bindings);

        /** @var array<string, array<string, array{fingerprint: string, label: string}>> $out */
        $out = [];

        foreach ($rows as $row) {
            $table = (string) $row->TABLE_NAME;

            if (self::shouldExcludeTableByUnderscoreSegments($table)) {
                continue;
            }

            $column = (string) $row->COLUMN_NAME;

            $default = $row->COLUMN_DEFAULT;

            if ($default === null) {
                $defaultToken = '∅';
            } else {
                $defaultToken = [REDACTED] $default;
            }

            $fingerprint = implode("\x1e", [
                (string) $row->ORDINAL_POSITION,
                $defaultToken,
                (string) $row->IS_NULLABLE,
                (string) $row->COLUMN_TYPE,
                (string) $row->EXTRA,
                (string) $row->COLUMN_KEY,
                $row->COLLATION_NAME === null ? '∅' : (string) $row->COLLATION_NAME,
                (string) $row->COLUMN_COMMENT,
            ]);

            $label = sprintf(
                'pos=%s | default=%s | null=%s | type=%s | extra=%s | key=%s | collation=%s | comment=%s',
                (string) $row->ORDINAL_POSITION,
                $default === null ? 'NULL' : (string) $default,
                (string) $row->IS_NULLABLE,
                (string) $row->COLUMN_TYPE,
                (string) $row->EXTRA,
                (string) $row->COLUMN_KEY,
                $row->COLLATION_NAME === null ? 'NULL' : (string) $row->COLLATION_NAME,
                (string) $row->COLUMN_COMMENT
            );

            if (!isset($out[$table])) {
                $out[$table] = [];
            }

            $out[$table][$column] = [
                'fingerprint' => $fingerprint,
                'label' => $label,
            ];
        }

        if ($onlyTable !== null && !isset($out[$onlyTable])) {
            throw new InvalidArgumentException(sprintf(
                '在 connection [%s] 找不到實體資料表 %s（或已被 --ignore-tables 排除，或表名符合自動略過規則：分段為 bak／baks、bak+數字／baks+數字、或純數字）',
                $connection,
                $onlyTable
            ));
        }

        return $out;
    }

    /**
     * 比對兩份「資料表 → 欄位 → 定義」結構，產出差異報告（不含 I/O）。
     *
     * @phpstan-type ColumnDefs array<string, array<string, array{fingerprint: string, label: string}>>
     *
     * @param  ColumnDefs  $leftColumnsByTable
     * @param  ColumnDefs  $rightColumnsByTable
     * @param  bool  $onlyColumnPresence  為 true 時只比對欄位是否多出／缺少，略過同名欄位定義差異
     * @return array{
     *     tables_only_left: list<string>,
     *     tables_only_right: list<string>,
     *     tables: array<string, array{
     *         columns_only_left: list<string>,
     *         columns_only_right: list<string>,
     *         columns_mismatch: array<string, array{left: string, right: string}>
     *     }>
     * }
     */
    public static function compareSchemaColumns(
        array $leftColumnsByTable,
        array $rightColumnsByTable,
        bool $onlyColumnPresence = false,
    ): array {
        $leftTables = array_keys($leftColumnsByTable);
        $rightTables = array_keys($rightColumnsByTable);
        sort($leftTables);
        sort($rightTables);

        $tablesOnlyLeft = array_values(array_diff($leftTables, $rightTables));
        $tablesOnlyRight = array_values(array_diff($rightTables, $leftTables));
        $commonTables = array_values(array_intersect($leftTables, $rightTables));
        sort($commonTables);

        $tables = [];

        foreach ($commonTables as $table) {
            $leftCols = $leftColumnsByTable[$table] ?? [];
            $rightCols = $rightColumnsByTable[$table] ?? [];
            $leftNames = array_keys($leftCols);
            $rightNames = array_keys($rightCols);
            sort($leftNames);
            sort($rightNames);

            $columnsOnlyLeft = array_values(array_diff($leftNames, $rightNames));
            $columnsOnlyRight = array_values(array_diff($rightNames, $leftNames));
            $commonNames = array_values(array_intersect($leftNames, $rightNames));
            sort($commonNames);

            $columnsMismatch = [];

            if (!$onlyColumnPresence) {
                foreach ($commonNames as $column) {
                    $l = $leftCols[$column]['fingerprint'] ?? '';
                    $r = $rightCols[$column]['fingerprint'] ?? '';

                    if ($l !== $r) {
                        $columnsMismatch[$column] = [
                            'left' => $leftCols[$column]['label'] ?? '',
                            'right' => $rightCols[$column]['label'] ?? '',
                        ];
                    }
                }
            }

            $hasPresenceDiff = $columnsOnlyLeft !== [] || $columnsOnlyRight !== [];
            $includeTable = $hasPresenceDiff || (!$onlyColumnPresence && $columnsMismatch !== []);

            if ($includeTable) {
                $tables[$table] = [
                    'columns_only_left' => $columnsOnlyLeft,
                    'columns_only_right' => $columnsOnlyRight,
                    'columns_mismatch' => $columnsMismatch,
                ];
            }
        }

        return [
            'tables_only_left' => $tablesOnlyLeft,
            'tables_only_right' => $tablesOnlyRight,
            'tables' => $tables,
        ];
    }

    /**
     * @param  list<string>  $tablesLike
     * @param  list<mixed>  $bindings
     */
    public static function buildTablesLikeSql(array $tablesLike, array &$bindings): string
    {
        if ($tablesLike === []) {
            return '';
        }

        $clauses = [];

        foreach ($tablesLike as $keyword) {
            $clauses[] = 'LOWER(c.TABLE_NAME) LIKE ?';
            $bindings[] = '%' . strtolower($keyword) . '%';
        }

        return ' AND (' . implode(' OR ', $clauses) . ')';
    }

    /**
     * 表名以 `_` 分段後，若任一段為 bak／baks（不分大小寫）、bak／baks 後接十進位數字（如 bak260217）、或整段均為十進位數字，則不納入 schema 比對。
     */
    private static function shouldExcludeTableByUnderscoreSegments(string $tableName): bool
    {
        foreach (explode('_', $tableName) as $segment) {
            if ($segment === '') {
                continue;
            }

            $normalized = strtolower($segment);

            if ($normalized === 'bak' || $normalized === 'baks') {
                return true;
            }

            if (preg_match('/^baks?\d+$/i', $segment) === 1) {
                return true;
            }

            if (preg_match('/^\d+$/', $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 將使用者輸入的 connection 名稱對應到 config 實際鍵名（不區分大小寫，例如 Yes2win → Yes2Win）。
     */
    private function resolveConnectionName(string $name): string
    {
        $connections = config('database.connections');

        if (!is_array($connections)) {
            throw new InvalidArgumentException('設定檔 database.connections 格式不正確');
        }

        if (isset($connections[$name])) {
            return $name;
        }

        $target = strtolower($name);

        foreach (array_keys($connections) as $key) {
            $keyString = (string) $key;

            if (strtolower($keyString) === $target) {
                if ($keyString !== $name) {
                    $this->line(sprintf(
                        '<comment>connection 名稱已正規化：%s → %s</comment>',
                        $name,
                        $keyString
                    ));
                }

                return $keyString;
            }
        }

        $available = implode(', ', array_keys($connections));

        throw new InvalidArgumentException(sprintf(
            '找不到 database connection: %s。可用鍵名: %s',
            $name,
            $available
        ));
    }

    /**
     * @return list<string>
     */
    private function parseTableList(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return [];
        }

        /** @var list<string> $out */
        $out = [];

        foreach ($parts as $p) {
            $t = trim((string) $p);

            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }
}
