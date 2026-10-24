<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * 後台「資料表加索引／加欄位」（schema_operation）的計畫與執行。
 *
 * 兩條路徑：
 * - 加索引（action=index）：**一律**建 {table}_mirror 完整鏡像表，不分表大小、不直接 ALTER ... ADD INDEX——
 *   trigger 同步新資料、EVENT 每 5 秒呼叫 SP_Backfill_Mirror_Step 由大到小分段回填舊資料，
 *   進度存 mirror_backfill_state；全量回填完成後以 action=rename 原子換表轉正。
 *   不直接 ALTER 的理由有兩個，缺一不可：
 *   1. primary：62GB 表實測跑到失敗且中途不可暫停；616MB 表 INPLACE 也跑了 319 秒（約 2MB/s，Cloud SQL I/O 上限）。
 *   2. replica：ALTER 是以一句 statement 進 binlog，replica 必須**整句重跑、序列執行**，`LOCK=NONE` 對它無效。
 *      TPower 實測 616MB 表直接 ALTER 讓 replica 落後 134 秒（後台讀 replica → 畫面顯示過期狀態）；
 *      同期鏡像回填全程 ≤3 秒。曾設 1GB／256MB 門檻讓小表直接 ALTER，每次實測都得再往下修——門檻不存在，故移除。
 * - 加欄位（action=column）：ALTER TABLE ... ADD COLUMN ... ALGORITHM=INSTANT
 *   （只改 metadata；條件不滿足會直接報錯，不會默默退化成鎖表重建。**「瞬間」只對 metadata 成立**：
 *   TPower 24GB buffer pool 實測 orders 加欄位 MySQL 端 24 秒、期間全庫查詢停頓——一律離峰執行）。
 *
 * SP 與 EVENT 屬單次作業的臨時機具，不進 database/procedures/ 版控：SP 定義以本類常數為準，
 * 執行時不存在才建立，rename 收官時若已無其他回填 EVENT 便一併移除。
 * 人工執行版 runbook（含拆除與逐步驗證）：docs/reconcile/payout-time-鏡像表建置.md
 */
readonly class SchemaOperationService
{
    /** 回填 SP 名稱（各表共用，EVENT 以表名參數呼叫） */
    public const string BACKFILL_PROCEDURE = 'SP_Backfill_Mirror_Step';

    /**
     * 執行 DDL 時等 metadata lock 的上限秒數。
     *
     * INSTANT／INPLACE 都仍要瞬間拿一次表的排他 MDL；表上若有長交易或長查詢，ALTER 會排隊等它，
     * 而等待期間所有新進的該表查詢都會堵在 ALTER 後面（全站卡死的實際成因）。
     * session 預設 lock_wait_timeout 是 31536000 秒（一年），這裡壓到幾秒：等不到就自己以 1205 失敗，離峰重跑即可。
     */
    public const int LOCK_WAIT_TIMEOUT_SECONDS = 5;

    /**
     * 退場表命名：{原表}_YYYYMMDD（planRename 換表時產生）。
     * drop_retired 只接受這個形狀，避免面板誤刪一般業務表。
     */
    public const string RETIRED_TABLE_PATTERN = '/^(?<base>[A-Za-z][A-Za-z0-9_]*)_(?<date>\d{8})$/';

    /** 面板「表」下拉排除的基礎設施表 */
    private const array TABLE_BLACKLIST = [
        'migrations',
        'jobs',
        'failed_jobs',
        'job_batches',
        'mirror_backfill_state',
        'procedure_hashes',
        'admin_operation_logs',
    ];

    /** MySQL 識別字：表名／欄位名／索引名（64 字元上限） */
    private const string IDENTIFIER_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    /** 加欄位允許的型別白名單（皆為 INSTANT 支援、專案實際會用到的範圍） */
    private const string COLUMN_TYPE_PATTERN = '/^(?:VARCHAR\(\d{1,4}\)|CHAR\(\d{1,3}\)|DECIMAL\(\d{1,2},\s?\d{1,2}\)|(?:TINY|SMALL|MEDIUM|BIG)?INT(?: UNSIGNED)?|DATETIME|DATE|TIMESTAMP|TIME|JSON|TEXT)$/i';

    /** DEFAULT／COMMENT 允許的字面值（不含引號與反斜線，杜絕跳脫問題） */
    private const string LITERAL_PATTERN = '/^[\p{L}\p{N} _\-.,:\/()%+]{0,100}$/u';

    /**
     * 加索引：建鏡像表全套（鏡像表 → trigger → 進度列 → SP → EVENT，順序不可換）。
     *
     * trigger 必須先於進度列：先建 trigger 再取 MAX(id) 當回填水位，
     * id 大於水位的新資料由 trigger 顧、小於水位的由回填顧，中間才不會有缺口。
     *
     * @return list<string> 將依序執行的 SQL
     */
    public function planMirrorIndex(string $table, string $column, int $minId, int $chunk): array
    {
        $this->assertManagedTable($table);

        // 衍生名最長的是 trigger（trg_{table}_mirror_ai），表名 >50 會超過 MySQL 識別字
        // 64 字元上限，且會在建完鏡像表之後才爆、留殘留——動手前先擋
        if (strlen($table) > 50) {
            throw new InvalidArgumentException(sprintf('表名 %s 超過 50 字元，衍生的 trigger／EVENT 名稱會超過 MySQL 上限', $table));
        }

        if ($minId < 0) {
            throw new InvalidArgumentException('min_id 不可為負數');
        }

        if ($chunk < 1000 || $chunk > 100000) {
            throw new InvalidArgumentException('chunk 允許的值為 1000〜100000（胖列建議 8000、一般 20000）');
        }

        $columns = $this->columnsOf($table);

        if ($columns === []) {
            throw new InvalidArgumentException(sprintf('資料表 %s 不存在', $table));
        }

        if (!array_key_exists($column, $columns)) {
            throw new InvalidArgumentException(sprintf('資料表 %s 沒有欄位 %s', $table, $column));
        }

        $this->assertHasAutoIncrementIdPrimaryKey($table);

        $existingIndex = $this->indexLeadingOnColumn($table, $column);

        if ($existingIndex !== null) {
            throw new InvalidArgumentException(
                sprintf('資料表 %s 已有以 %s 開頭的索引（%s），直接查原表即可，不需鏡像表', $table, $column, $existingIndex),
            );
        }

        $this->assertNoMirrorLeftover($table);
        // 外鍵在鏡像流程一開始就擋，不要回填好幾天後才在 rename 被擋
        $this->assertForeignKeysAllowMirror($table);

        $mirror = $table . '_mirror';
        $statements = [
            // LIKE 帶走全部欄位、主鍵與索引，但不帶外鍵——鏡像表寫入不需外鍵檢查，是好事
            sprintf('CREATE TABLE `%s` LIKE `%s`', $mirror, $table),
            sprintf('ALTER TABLE `%s` ADD INDEX `%s` (`%s`)', $mirror, self::mirrorIndexName($mirror, $column), $column),
            ...self::buildTriggerSqls($table, array_keys($columns)),
            self::buildStateUpsertSql($table, $minId),
        ];

        if (!$this->procedureExists()) {
            $statements[] = self::backfillProcedureSql();
        }

        $statements[] = self::buildEventSql($table, $chunk);

        return $statements;
    }

    /**
     * 加欄位：INSTANT 只改 metadata。大表 metadata 一樣瞬間，但提交階段實測會停頓全庫（見類別說明），離峰執行。
     *
     * @return list<string>
     */
    public function planAddColumn(string $table, string $name, string $type, bool $nullable, ?string $default, ?string $comment): array
    {
        $this->assertManagedTable($table);

        $columns = $this->columnsOf($table);

        if ($columns === []) {
            throw new InvalidArgumentException(sprintf('資料表 %s 不存在', $table));
        }

        if (array_key_exists($name, $columns)) {
            throw new InvalidArgumentException(sprintf('資料表 %s 已有欄位 %s', $table, $name));
        }

        return [self::buildAddColumnSql($table, $name, $type, $nullable, $default, $comment)];
    }

    /**
     * 取消回填：拆除該表的鏡像建置（EVENT／trigger／鏡像表／進度列），已回填資料一併刪除。
     *
     * @return list<string>
     */
    public function planCancel(string $table): array
    {
        $this->assertManagedTable($table);

        $mirror = $table . '_mirror';

        $hasAnything = $this->tableExists($mirror)
            || DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.TRIGGERS '
                . "WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? AND TRIGGER_NAME LIKE '%\\_mirror\\_%' LIMIT 1",
                [$table],
            ) !== null
            || DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = ?',
                ['ev_backfill_' . $table],
            ) !== null
            || DB::selectOne('SELECT 1 AS ok FROM mirror_backfill_state WHERE src_table = ?', [$table]) !== null;

        if (!$hasAnything) {
            throw new InvalidArgumentException(sprintf('%s 沒有任何鏡像建置殘留（EVENT／trigger／鏡像表／進度列），無可取消', $table));
        }

        $statements = self::buildCancelSqls($table);

        if ($this->backfillEventCountExcluding($table) === 0) {
            $statements[] = sprintf('DROP PROCEDURE IF EXISTS `%s`', self::BACKFILL_PROCEDURE);
        }

        return $statements;
    }

    /**
     * 刪除退場表：換表轉正後留下的 {原表}_YYYYMMDD，觀察穩定後由本動作 DROP 釋放空間。
     *
     * 硬擋三種情況：不是退場表形狀、原表不存在（代表它不是換表留下的）、
     * 仍有 trigger 或外鍵掛著它（DROP 會讓那些寫入立即報錯）。
     *
     * @return list<string>
     */
    public function planDropRetired(string $table): array
    {
        $this->assertIdentifier($table, '表名');

        $base = self::retiredBaseTable($table);

        if ($base === null) {
            throw new InvalidArgumentException(sprintf('%s 不是退場表（需為 {原表}_YYYYMMDD 形狀），本動作只刪換表後留下的退場表', $table));
        }

        if (!$this->tableExists($table)) {
            throw new InvalidArgumentException(sprintf('退場表 %s 不存在', $table));
        }

        if (!$this->tableExists($base)) {
            throw new InvalidArgumentException(sprintf('原表 %s 不存在，%s 不是換表留下的退場表，不可由本動作刪除', $base, $table));
        }

        $trigger = DB::selectOne(
            'SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA = DATABASE() AND (EVENT_OBJECT_TABLE = ? OR ACTION_STATEMENT LIKE ?) LIMIT 1',
            [$table, '%`' . $table . '`%'],
        );

        if ($trigger !== null) {
            throw new InvalidArgumentException(sprintf('trigger %s 仍掛著 %s，先拆 trigger 再刪表', (string) $trigger->name, $table));
        }

        $referencing = DB::selectOne(
            'SELECT TABLE_NAME AS name FROM information_schema.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? LIMIT 1',
            [$table],
        );

        if ($referencing !== null) {
            throw new InvalidArgumentException(sprintf('有其他表（%s）以外鍵指向 %s，先處理外鍵再刪表', (string) $referencing->name, $table));
        }

        return [sprintf('DROP TABLE IF EXISTS `%s`', $table)];
    }

    /** 退場表名對應的原表名；不是 {原表}_YYYYMMDD 形狀回 null */
    public static function retiredBaseTable(string $table): ?string
    {
        return preg_match(self::RETIRED_TABLE_PATTERN, $table, $m) === 1 ? $m['base'] : null;
    }

    /**
     * 取消回填的固定拆除語句。順序是保命的：trigger 必須先於鏡像表刪除——
     * 先刪表會讓來源表上殘留指向已消失表的 trigger，該表所有寫入立即報錯。
     *
     * @return list<string>
     */
    public static function buildCancelSqls(string $table): array
    {
        return [
            sprintf('DROP EVENT IF EXISTS `ev_backfill_%s`', $table),
            sprintf('DROP TRIGGER IF EXISTS `trg_%s_mirror_ai`', $table),
            sprintf('DROP TRIGGER IF EXISTS `trg_%s_mirror_au`', $table),
            sprintf('DROP TRIGGER IF EXISTS `trg_%s_mirror_ad`', $table),
            sprintf('DROP TABLE IF EXISTS `%s_mirror`', $table),
            sprintf("DELETE FROM mirror_backfill_state WHERE src_table = '%s'", $table),
        ];
    }

    /**
     * 換表轉正：回填全量完成後，把鏡像表原子換成原表（原表退場為 {table}_old）。
     *
     * 原子核心是多表 RENAME TABLE（單一語句、全有或全無、持 MDL 擋併發寫入）。
     * 無資料縫隙：換表前每筆寫入都由 trigger 同交易同步進鏡像，換表後寫入直接進新表
     * ——與 pt-online-schema-change 的 cutover 相同模型。換表後應用查詢照舊查原表名。
     *
     * @return list<string>
     */
    public function planRename(string $table): array
    {
        $this->assertManagedTable($table);

        $mirror = $table . '_mirror';
        // 退場表以換表日期為後綴（庫內既有慣例，如 total_bet_records_20260511）
        $old = sprintf('%s_%s', $table, now()->format('Ymd'));

        if ($this->tableExists($old)) {
            throw new InvalidArgumentException(sprintf('%s 已存在（今天已換過表？），先處理掉再轉正', $old));
        }

        if (!$this->tableExists($mirror)) {
            throw new InvalidArgumentException(sprintf('鏡像表 %s 不存在，沒有可轉正的對象', $mirror));
        }

        $state = DB::selectOne('SELECT next_id, min_id, done FROM mirror_backfill_state WHERE src_table = ?', [$table]);

        if ($state === null || (int) $state->done !== 1) {
            throw new InvalidArgumentException(sprintf('%s 回填尚未完成，不可轉正（用 status 動作查進度）', $table));
        }

        // 部分回填（min_id > 0）不再硬擋：對帳類的表只需要近期資料在線上表，id <= min_id 的舊列
        // 換表後只留在退場表 {table}_YYYYMMDD——由命令層以 partialBackfillNotice() 明講筆數，操作者自己決定

        // 健康抽查：兩表 MAX(id) 同一語句（同一快照）比對，不等代表 trigger 曾停擺
        $maxes = DB::selectOne(sprintf(
            'SELECT (SELECT MAX(id) FROM `%s`) AS src_max, (SELECT MAX(id) FROM `%s`) AS mirror_max',
            $table,
            $mirror,
        ));

        if ((int) $maxes->src_max !== (int) $maxes->mirror_max) {
            throw new InvalidArgumentException(sprintf(
                '%s 與 %s 的 MAX(id) 不一致（%s vs %s），trigger 同步可能曾中斷，先查明再轉正',
                $table,
                $mirror,
                (string) $maxes->src_max,
                (string) $maxes->mirror_max,
            ));
        }

        // 建鏡像時已擋過，這裡再擋一次：期間可能有人加了外鍵
        $this->assertForeignKeysAllowMirror($table);

        // 原表上若有非鏡像 trigger，會隨換表跟去 _old 而失效，硬擋
        $foreignTrigger = DB::selectOne(
            'SELECT TRIGGER_NAME AS trigger_name FROM information_schema.TRIGGERS '
            . "WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? AND TRIGGER_NAME NOT LIKE '%\\_mirror\\_%' LIMIT 1",
            [$table],
        );

        if ($foreignTrigger !== null) {
            throw new InvalidArgumentException(sprintf(
                '%s 上有鏡像以外的 trigger（%s），換表會讓它跟去 %s 失效，需人工處理',
                $table,
                (string) $foreignTrigger->trigger_name,
                $old,
            ));
        }

        $statements = [
            sprintf('DROP EVENT IF EXISTS `ev_backfill_%s`', $table),
            self::buildAtomicSwapSql($table, $old),
            sprintf('DROP TRIGGER IF EXISTS `trg_%s_mirror_ai`', $table),
            sprintf('DROP TRIGGER IF EXISTS `trg_%s_mirror_au`', $table),
            sprintf('DROP TRIGGER IF EXISTS `trg_%s_mirror_ad`', $table),
        ];

        // 鏡像索引改回正式命名（metadata-only、瞬間）；目標名已存在則保留原名不硬撞
        $mirrorIndexes = DB::select(
            'SELECT DISTINCT INDEX_NAME AS index_name FROM information_schema.STATISTICS '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME LIKE '%mirror%'",
            [$mirror],
        );

        $existingNames = array_map(
            static fn (object $row): string => (string) $row->index_name,
            DB::select(
                'SELECT DISTINCT INDEX_NAME AS index_name FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$mirror],
            ),
        );

        foreach ($mirrorIndexes as $row) {
            $from = (string) $row->index_name;
            $to = self::renamedIndexName($from);

            if ($to !== $from && !in_array($to, $existingNames, true)) {
                $statements[] = sprintf('ALTER TABLE `%s` RENAME INDEX `%s` TO `%s`', $table, $from, $to);
            }
        }

        $statements[] = sprintf("DELETE FROM mirror_backfill_state WHERE src_table = '%s'", $table);

        if ($this->backfillEventCountExcluding($table) === 0) {
            $statements[] = sprintf('DROP PROCEDURE IF EXISTS `%s`', self::BACKFILL_PROCEDURE);
        }

        return $statements;
    }

    /**
     * 鏡像流程（建鏡像／換表轉正）對外鍵的硬擋，兩種都必須人工處理、不可自動：
     *
     * - 原表對外的外鍵帶 CASCADE／SET NULL／SET DEFAULT：MySQL 由外鍵級聯改動的列**不觸發 trigger**
     *   （官方文件明載），父表一刪，原表的列被級聯刪掉但鏡像表不知道，回填完成、MAX(id) 相同也照樣漂移，
     *   換表後這些列會在線上表復活。
     * - 其他表以外鍵指向原表：RENAME 會讓那些外鍵默默跟去退場表。
     *
     * RESTRICT／NO ACTION 的對外外鍵不擋（不會改動原表的列），但 LIKE 建的鏡像表不帶外鍵、換表後會消失，
     * 由命令層以警示告知（見 outgoingForeignKeys()）。
     */
    private function assertForeignKeysAllowMirror(string $table): void
    {
        $cascading = $this->cascadingForeignKeys($table);

        if ($cascading !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s 的外鍵帶級聯動作（%s）：級聯改動的列不觸發 trigger，鏡像表會漂移，不可走鏡像流程，需人工處理',
                $table,
                implode('、', $cascading),
            ));
        }

        $referencing = DB::selectOne(
            'SELECT TABLE_NAME AS name FROM information_schema.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? LIMIT 1',
            [$table],
        );

        if ($referencing !== null) {
            throw new InvalidArgumentException(sprintf(
                '有其他表（%s）以外鍵指向 %s，換表會讓外鍵跟去退場表，需人工處理，不可走鏡像流程',
                (string) $referencing->name,
                $table,
            ));
        }
    }

    /**
     * 原表對外、帶級聯動作（CASCADE／SET NULL／SET DEFAULT）的外鍵，格式「外鍵名→父表(ON UPDATE x, ON DELETE y)」。
     *
     * @return list<string>
     */
    public function cascadingForeignKeys(string $table): array
    {
        $this->assertIdentifier($table, '表名');

        $rows = DB::select(
            'SELECT CONSTRAINT_NAME AS name, REFERENCED_TABLE_NAME AS referenced, UPDATE_RULE AS update_rule, DELETE_RULE AS delete_rule '
            . 'FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            . "AND (UPDATE_RULE IN ('CASCADE', 'SET NULL', 'SET DEFAULT') OR DELETE_RULE IN ('CASCADE', 'SET NULL', 'SET DEFAULT'))",
            [$table],
        );

        return array_map(
            static fn (object $row): string => sprintf(
                '%s→%s(ON UPDATE %s, ON DELETE %s)',
                (string) $row->name,
                (string) $row->referenced,
                (string) $row->update_rule,
                (string) $row->delete_rule,
            ),
            $rows,
        );
    }

    /**
     * 部分回填換表的提醒文字：鏡像表只有 id > min_id 的列，換表後 id <= min_id 的舊資料只留在退場表。
     * 全量回填（min_id = 0）或查不到進度列時回 null。
     *
     * 筆數用 id 區間估（auto increment 大致連續），不對大表 COUNT。
     */
    public function partialBackfillNotice(string $table): ?string
    {
        $this->assertIdentifier($table, '表名');

        $state = DB::selectOne('SELECT min_id FROM mirror_backfill_state WHERE src_table = ?', [$table]);
        $minId = (int) ($state?->min_id ?? 0);

        if ($minId <= 0) {
            return null;
        }

        $lowest = (int) (DB::selectOne(sprintf('SELECT MIN(id) AS v FROM `%s`', $table))?->v ?? $minId);

        return sprintf(
            '%s 只回填到 id > %d：換表後 id <= %d 的舊資料（約 %s 筆，id %d〜%d）從線上表消失、只留在退場表 %s_%s，'
            . '報表／對帳／會員歷史查不到這段。確定只需要近期資料才轉正；退場表在確認前不要 drop_retired。',
            $table,
            $minId,
            $minId,
            number_format(max($minId - $lowest + 1, 0)),
            $lowest,
            $minId,
            $table,
            now()->format('Ymd'),
        );
    }

    /**
     * 原表對外的外鍵名（換表警示用：LIKE 建的鏡像表不帶外鍵，轉正後這些外鍵不再存在）。
     *
     * @return list<string>
     */
    public function outgoingForeignKeys(string $table): array
    {
        $this->assertIdentifier($table, '表名');

        $rows = DB::select(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$table],
        );

        return array_map(static fn (object $row): string => (string) $row->name, $rows);
    }

    /**
     * 原子換表語句：單一多表 RENAME，全有或全無、持 MDL 擋併發寫入。
     */
    public static function buildAtomicSwapSql(string $table, string $oldTable): string
    {
        self::assertIdentifierStatic($oldTable, '退場表名');

        return sprintf('RENAME TABLE `%s` TO `%s`, `%s_mirror` TO `%s`', $table, $oldTable, $table, $table);
    }

    /**
     * 鏡像索引轉正名：拿掉名稱中的 mirror 段（{t}_mirror_{col}_index → {t}_{col}_index）。
     */
    public static function renamedIndexName(string $mirrorIndexName): string
    {
        return str_replace(['_mirror_', '_mirror'], ['_', ''], $mirrorIndexName);
    }

    /**
     * 回填進度報告（唯讀）。$table 為 null 時列出全部進度列。
     *
     * @return list<string> 逐列進度文字
     */
    public function statusReport(?string $table): array
    {
        if ($table !== null) {
            $this->assertIdentifier($table, '表名');
        }

        $rows = $table === null
            ? DB::select('SELECT src_table, next_id, min_id, done, updated_at FROM mirror_backfill_state ORDER BY src_table')
            : DB::select('SELECT src_table, next_id, min_id, done, updated_at FROM mirror_backfill_state WHERE src_table = ?', [$table]);

        if ($rows === []) {
            return [$table === null ? '沒有任何回填進度紀錄。' : sprintf('%s 沒有回填進度紀錄。', $table)];
        }

        $lines = [];

        foreach ($rows as $row) {
            $src = (string) $row->src_table;
            $this->assertIdentifier($src, '表名');

            $nextId = (int) $row->next_id;
            $minId = (int) $row->min_id;
            $done = (int) $row->done === 1;

            // MAX(id) 走主鍵端點，瞬間完成，不是全表掃描
            $maxId = (int) (DB::selectOne(sprintf('SELECT MAX(id) AS max_id FROM `%s`', $src))->max_id ?? 0);
            $total = max($maxId - $minId, 1);
            $remaining = max($nextId - $minId, 0);
            $percent = $done ? 100.0 : min(100.0, max(0.0, ($total - $remaining) / $total * 100));

            $event = DB::selectOne(
                'SELECT STATUS AS status, LAST_EXECUTED AS last_executed FROM information_schema.EVENTS '
                . 'WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = ?',
                ['ev_backfill_' . $src],
            );

            $lines[] = sprintf(
                '%s：%s｜進度 %.1f%%（水位 %s → 下限 %s，剩 %s 個 id）｜EVENT %s｜最後更新 %s',
                $src,
                $done ? '✅ 回填完成' : '⏳ 回填中',
                $percent,
                number_format($nextId),
                number_format($minId),
                number_format($remaining),
                $event === null
                    ? '不存在'
                    : sprintf('%s（最後執行 %s）', (string) $event->status, (string) ($event->last_executed ?? '—')),
                (string) $row->updated_at,
            );

            if ($done && $event !== null) {
                $lines[] = $minId === 0
                    ? sprintf('  ↳ 全量回填完成，可執行換表轉正：php artisan schema_operation --action=rename --table=%s', $src)
                    : sprintf('  ↳ 部分回填（min_id=%s）不可轉正；收尾請手動 DROP EVENT `ev_backfill_%s`，鏡像表留查', number_format($minId), $src);
            }

            if (!$done && $event === null) {
                $lines[] = '  ↳ ⚠ 回填未完成但 EVENT 不存在，回填已停擺——請查明（可能被手動移除），或以 cancel 動作拆除重來';
            }
        }

        return $lines;
    }

    /**
     * 逐句執行（DDL 隱式 commit、不可回滾，不包交易）；失敗即停並回報中斷點。
     *
     * 執行期間把 session 的 lock_wait_timeout 壓到 LOCK_WAIT_TIMEOUT_SECONDS，結束後還原
     * （queue worker 的連線跨 job 共用，不能把短 timeout 留給下一個 job）。
     *
     * 每句記牆鐘秒數；連線帳號有 performance_schema 的 UPDATE 權限時，執行期間順帶開啟 stage 儀表，
     * 把該句各階段（Opening tables／System lock／committing alter table to storage engine…）的耗時一併交給回呼，
     * 結束後把 setup_consumers／setup_instruments 還原成原本狀態。
     * 起因：TPower 加欄位 INSTANT 實測 MySQL 端 24 秒且全庫停頓，只有階段耗時能指出卡在哪。
     *
     * @param  list<string>  $statements
     * @param  Closure(string, float, list<array{stage: string, seconds: float}>|null): void|null  $onExecuted
     *                                                                                                          每句成功後回呼：SQL、牆鐘秒數、階段耗時（null＝無 performance_schema 權限、未量測）
     */
    public function execute(array $statements, ?Closure $onExecuted = null): void
    {
        // 走 write PDO：SET SESSION 與後面的 DDL 必須在同一條連線上才有效
        $previous = (int) DB::select('SELECT @@SESSION.lock_wait_timeout AS v', [], false)[0]->v;
        DB::unprepared(sprintf('SET SESSION lock_wait_timeout = %d', self::LOCK_WAIT_TIMEOUT_SECONDS));
        $restoreProfiling = $this->enableStageProfiling();

        try {
            foreach ($statements as $index => $sql) {
                $startedAt = microtime(true);

                try {
                    DB::unprepared($sql);
                } catch (Throwable $e) {
                    $hint = str_contains($e->getMessage(), 'Lock wait timeout')
                        ? sprintf("\n等 metadata lock 超過 %d 秒：表上有長交易或長查詢，未動到任何結構，離峰重跑即可", self::LOCK_WAIT_TIMEOUT_SECONDS)
                        : "\n殘留物拆除方式見 docs/reconcile/payout-time-鏡像表建置.md「全部刪除」一節";

                    throw new RuntimeException(sprintf(
                        "第 %d 句執行失敗：%s\n原因：%s%s",
                        $index + 1,
                        $sql,
                        $e->getMessage(),
                        $hint,
                    ), previous: $e);
                }

                $seconds = microtime(true) - $startedAt;
                $stages = $restoreProfiling !== null ? $this->stagesOfLastStatement($sql) : null;

                if ($onExecuted !== null) {
                    $onExecuted($sql, $seconds, $stages);
                }
            }
        } finally {
            DB::unprepared(sprintf('SET SESSION lock_wait_timeout = %d', $previous));

            if ($restoreProfiling !== null) {
                $restoreProfiling();
            }
        }
    }

    /**
     * 開啟 stage 儀表（全域設定，影響所有連線，故只在執行期間開、結束即還原）。
     *
     * 需要 performance_schema 的 SELECT／UPDATE；沒權限回 null，呼叫端退回只記牆鐘秒數。
     * 中途失敗會把已改的部分還原再回 null，不留半開狀態。
     *
     * @return Closure(): void|null 還原用閉包
     */
    private function enableStageProfiling(): ?Closure
    {
        /** @var list<Closure(): void> $undo */
        $undo = [];
        $restore = static function () use (&$undo): void {
            foreach (array_reverse($undo) as $step) {
                try {
                    $step();
                } catch (Throwable) {
                    // 還原失敗不能蓋掉主流程的結果；下一次執行會再讀當下狀態
                }
            }
        };

        try {
            $offConsumers = self::names(DB::select(
                "SELECT NAME FROM performance_schema.setup_consumers WHERE ENABLED = 'NO' AND NAME IN (?, ?)",
                ['events_stages_current', 'events_stages_history_long'],
                false,
            ));

            if ($offConsumers !== []) {
                DB::update(
                    sprintf("UPDATE performance_schema.setup_consumers SET ENABLED = 'YES' WHERE NAME IN (%s)", self::placeholders($offConsumers)),
                    $offConsumers,
                );
                $undo[] = static fn () => DB::update(
                    sprintf("UPDATE performance_schema.setup_consumers SET ENABLED = 'NO' WHERE NAME IN (%s)", self::placeholders($offConsumers)),
                    $offConsumers,
                );
            }

            foreach (['ENABLED', 'TIMED'] as $column) {
                $off = self::names(DB::select(
                    sprintf("SELECT NAME FROM performance_schema.setup_instruments WHERE NAME LIKE 'stage/%%' AND %s = 'NO'", $column),
                    [],
                    false,
                ));

                if ($off === []) {
                    continue;
                }

                DB::update(
                    sprintf("UPDATE performance_schema.setup_instruments SET %s = 'YES' WHERE NAME IN (%s)", $column, self::placeholders($off)),
                    $off,
                );
                $undo[] = static fn () => DB::update(
                    sprintf("UPDATE performance_schema.setup_instruments SET %s = 'NO' WHERE NAME IN (%s)", $column, self::placeholders($off)),
                    $off,
                );
            }
        } catch (Throwable) {
            $restore();

            return null;
        }

        return $restore;
    }

    /**
     * 本連線剛執行完那句 SQL 的階段耗時（由長到短）。找不到或沒權限回空陣列。
     *
     * @return list<array{stage: string, seconds: float}>
     */
    private function stagesOfLastStatement(string $sql): array
    {
        try {
            $prefix = mb_substr($sql, 0, 200);
            $statement = DB::select(
                'SELECT s.THREAD_ID AS thread_id, s.EVENT_ID AS event_id
                   FROM performance_schema.events_statements_history AS s
                   JOIN performance_schema.threads AS t ON t.THREAD_ID = s.THREAD_ID
                  WHERE t.PROCESSLIST_ID = CONNECTION_ID() AND LEFT(s.SQL_TEXT, ?) = ?
                  ORDER BY s.EVENT_ID DESC LIMIT 1',
                [mb_strlen($prefix), $prefix],
                false,
            );

            if ($statement === []) {
                return [];
            }

            $rows = DB::select(
                'SELECT EVENT_NAME AS stage, TIMER_WAIT AS timer_wait
                   FROM performance_schema.events_stages_history_long
                  WHERE THREAD_ID = ? AND NESTING_EVENT_ID = ?
                  ORDER BY TIMER_WAIT DESC',
                [(int) $statement[0]->thread_id, (int) $statement[0]->event_id],
                false,
            );

            return array_map(
                static fn (object $row): array => [
                    'stage' => (string) $row->stage,
                    'seconds' => (float) $row->timer_wait / 1e12, // TIMER_WAIT 單位皮秒
                ],
                $rows,
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<object>  $rows
     * @return list<string>
     */
    private static function names(array $rows): array
    {
        return array_map(static fn (object $row): string => (string) $row->NAME, $rows);
    }

    /** @param  list<string>  $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * 面板「表」下拉選項：當前白標 DB 的業務表（排除基礎設施表與鏡像表）。
     *
     * @return array<string, string> 表名 => 「表名（大小 / 估計列數)」
     */
    public function listTables(): array
    {
        $rows = DB::select(
            'SELECT TABLE_NAME AS name, DATA_LENGTH AS data_length, TABLE_ROWS AS table_rows
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?
              ORDER BY TABLE_NAME',
            ['BASE TABLE'],
        );

        $options = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;

            if (in_array($name, self::TABLE_BLACKLIST, true) || str_ends_with($name, '_mirror')) {
                continue;
            }

            $options[$name] = sprintf('%s（%s / 約 %s 列）', $name, self::humanSize((int) $row->data_length), number_format((float) $row->table_rows));
        }

        return $options;
    }

    /**
     * 有鏡像建置痕跡的表——cancel／rename／status 的「資料表」下拉來源：
     * 鏡像表存在、回填 EVENT 存在、或進度列存在，三者聯集（進度列的狀態標示優先）。
     *
     * @return array<string, string> 表名 => 「表名（狀態）」
     */
    public function listMirroredTables(): array
    {
        $states = [];

        foreach (DB::select("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\\_mirror'") as $row) {
            $states[substr((string) $row->name, 0, -strlen('_mirror'))] = '殘留（無進度列）';
        }

        foreach (DB::select("SELECT EVENT_NAME AS name FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME LIKE 'ev\\_backfill\\_%'") as $row) {
            $states[substr((string) $row->name, strlen('ev_backfill_'))] = '殘留（無進度列）';
        }

        foreach (DB::select('SELECT src_table, done FROM mirror_backfill_state') as $row) {
            $states[(string) $row->src_table] = (int) $row->done === 1 ? '回填完成' : '回填中';
        }

        ksort($states);

        $options = [];

        foreach ($states as $name => $state) {
            $options[$name] = sprintf('%s（%s）', $name, $state);
        }

        return $options;
    }

    /**
     * 退場表清單——drop_retired 的「資料表」下拉來源：
     * 名稱為 {原表}_YYYYMMDD 且原表仍存在的表（排除剛好帶 8 位數字後綴的一般業務表）。
     *
     * @return array<string, string> 表名 => 「表名（大小 / 約 n 列）」
     */
    public function listRetiredTables(): array
    {
        $rows = DB::select(
            'SELECT TABLE_NAME AS name, DATA_LENGTH + INDEX_LENGTH AS bytes, TABLE_ROWS AS table_rows
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?
              ORDER BY TABLE_NAME',
            ['BASE TABLE'],
        );

        $names = array_map(static fn (object $row): string => (string) $row->name, $rows);
        $options = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;
            $base = self::retiredBaseTable($name);

            if ($base === null || !in_array($base, $names, true)) {
                continue;
            }

            $options[$name] = sprintf('%s（%s / 約 %s 列）', $name, self::humanSize((int) $row->bytes), number_format((float) $row->table_rows));
        }

        return $options;
    }

    private static function humanSize(int $bytes): string
    {
        $gb = $bytes / (1024 ** 3);

        return $gb >= 1 ? sprintf('%.1f GB', $gb) : sprintf('%d MB', (int) ceil($gb * 1024));
    }

    /**
     * 面板「欄位」下拉選項。
     *
     * @return array<string, string> 欄位名 => 「欄位名（型別）」
     */
    public function listColumns(string $table): array
    {
        $this->assertIdentifier($table, '表名');

        $options = [];

        foreach ($this->columnsOf($table) as $name => $type) {
            $options[$name] = sprintf('%s（%s）', $name, $type);
        }

        return $options;
    }

    /**
     * 鏡像同步 trigger：新增／更新整列 REPLACE、刪除跟刪。
     *
     * 用 REPLACE INTO 不用 ON DUPLICATE KEY UPDATE：鏡像表可能有多個唯一鍵（主鍵之外
     * 還有 bet_id 這類 unique），後者撞到哪一個不確定，可能改到錯的列；REPLACE 意思明確。
     * UPDATE trigger 不限定特定欄位變動才寫——鏡像要完整，任何欄位變動都同步。
     * ⚠️ 欄位是建立當下明列的（trigger 不支援 NEW.*）：日後原表加欄位不會報錯、
     * 只會靜默漏那一欄，原表改結構必須重建 trigger。
     *
     * @param  list<string>  $columns  依 ORDINAL_POSITION 排序的完整欄位清單
     * @return list<string>
     */
    public static function buildTriggerSqls(string $table, array $columns): array
    {
        $mirror = $table . '_mirror';
        $columnList = implode(', ', array_map(static fn (string $column): string => "`{$column}`", $columns));
        $newList = implode(', ', array_map(static fn (string $column): string => "NEW.`{$column}`", $columns));
        $replace = sprintf('REPLACE INTO `%s` (%s) VALUES (%s)', $mirror, $columnList, $newList);

        return [
            sprintf('CREATE TRIGGER `trg_%s_mirror_ai` AFTER INSERT ON `%s` FOR EACH ROW %s', $table, $table, $replace),
            sprintf('CREATE TRIGGER `trg_%s_mirror_au` AFTER UPDATE ON `%s` FOR EACH ROW %s', $table, $table, $replace),
            sprintf('CREATE TRIGGER `trg_%s_mirror_ad` AFTER DELETE ON `%s` FOR EACH ROW DELETE FROM `%s` WHERE id = OLD.id', $table, $table, $mirror),
        ];
    }

    /**
     * 回填進度列：水位取當下 MAX(id)（走主鍵，瞬間）。必須在 trigger 建好之後執行。
     */
    public static function buildStateUpsertSql(string $table, int $minId): string
    {
        // 水位 <= 下限（空表、或 min_id 給得比 MAX(id) 還大）代表沒東西可回填，建立當下就標完成：
        // SP 對這種列是直接 LEAVE、不會走到最後那句 UPDATE，done 若從 0 起算會永遠卡住、rename 被擋
        return sprintf(
            'INSERT INTO mirror_backfill_state (src_table, next_id, min_id, done) '
            . "SELECT '%s', IFNULL(MAX(id), 0), %d, IF(IFNULL(MAX(id), 0) <= %d, 1, 0) FROM `%s` "
            . 'ON DUPLICATE KEY UPDATE next_id = VALUES(next_id), min_id = VALUES(min_id), done = VALUES(done)',
            $table,
            $minId,
            $minId,
            $table,
        );
    }

    /**
     * 回填 EVENT：每 5 秒一段。重疊防護在 SP 內（GET_LOCK），間隔留呼吸空間、
     * 不用 1 秒——那等於全速回填，跟要避開的長時間 ALTER 一樣壓 I/O。
     */
    public static function buildEventSql(string $table, int $chunk): string
    {
        return sprintf(
            "CREATE EVENT `ev_backfill_%s` ON SCHEDULE EVERY 5 SECOND ON COMPLETION PRESERVE DO CALL %s('%s', %d)",
            $table,
            self::BACKFILL_PROCEDURE,
            $table,
            $chunk,
        );
    }

    /**
     * 加欄位 SQL：顯式 ALGORITHM=INSTANT，條件不滿足直接報錯，不默默退化成鎖表重建。
     */
    public static function buildAddColumnSql(string $table, string $name, string $type, bool $nullable, ?string $default, ?string $comment): string
    {
        self::assertIdentifierStatic($table, '表名');
        self::assertIdentifierStatic($name, '欄位名');

        if (preg_match(self::COLUMN_TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException(
                sprintf('欄位型別 %s 不在白名單（VARCHAR(n)／CHAR(n)／DECIMAL(p,s)／INT 家族／DATETIME／DATE／TIMESTAMP／TIME／JSON／TEXT）', $type),
            );
        }

        $upperType = strtoupper($type);
        $sql = sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $name, $upperType);
        $sql .= $nullable ? ' NULL' : ' NOT NULL';

        if ($default !== null && $default !== '') {
            if (str_starts_with($upperType, 'JSON') || str_starts_with($upperType, 'TEXT')) {
                throw new InvalidArgumentException('JSON／TEXT 欄位不支援 DEFAULT 字面值，請留空');
            }

            self::assertLiteral($default, '預設值');
            $sql .= sprintf(" DEFAULT '%s'", $default);
        }

        if ($comment !== null && $comment !== '') {
            self::assertLiteral($comment, '欄位註解');
            $sql .= sprintf(" COMMENT '%s'", $comment);
        }

        return $sql . ', ALGORITHM=INSTANT';
    }

    /**
     * 回填 SP 定義（版控以本常數為準；與 runbook 的人工版行為一致）。
     * 一次做一段就結束，迴圈交給 EVENT；EVENT 不防重疊，以 GET_LOCK 互斥。
     */
    public static function backfillProcedureSql(): string
    {
        $procedure = self::BACKFILL_PROCEDURE;

        return <<<SQL
            CREATE PROCEDURE `{$procedure}`(IN p_src VARCHAR(64), IN p_chunk INT UNSIGNED)
            proc: BEGIN
                DECLARE v_dst  VARCHAR(80);
                DECLARE v_lock VARCHAR(64);
                DECLARE v_hi   BIGINT UNSIGNED;
                DECLARE v_lo   BIGINT UNSIGNED;
                DECLARE v_min  BIGINT UNSIGNED;
                DECLARE v_done TINYINT;

                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                    BEGIN
                        GET DIAGNOSTICS CONDITION 1
                            @p2 = MESSAGE_TEXT;

                        DO RELEASE_LOCK(CONCAT('backfill:', p_src));

                        CALL COM_Send_Error_Message(
                            CONCAT('[系統通知] {$procedure}(', p_src, ') 異常 ', @p2),
                            'db'
                        );
                    END;

                SET v_dst  = CONCAT(p_src, '_mirror');
                SET v_lock = CONCAT('backfill:', p_src);

                IF IFNULL(GET_LOCK(v_lock, 0), 0) <> 1 THEN
                    LEAVE proc;
                END IF;

                SELECT next_id, min_id, done INTO v_hi, v_min, v_done
                  FROM mirror_backfill_state
                 WHERE src_table = p_src;

                IF v_hi IS NULL OR v_done = 1 OR v_hi <= v_min THEN
                    DO RELEASE_LOCK(v_lock);
                    LEAVE proc;
                END IF;

                SET v_lo = IF(v_hi > v_min + p_chunk, v_hi - p_chunk, v_min);

                SET @sql = CONCAT('INSERT IGNORE INTO `', v_dst, '` SELECT * FROM `', p_src,
                                  '` WHERE id > ', v_lo, ' AND id <= ', v_hi);
                PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

                UPDATE mirror_backfill_state
                   SET next_id = v_lo,
                       done    = IF(v_lo <= v_min, 1, 0)
                 WHERE src_table = p_src;

                DO RELEASE_LOCK(v_lock);
            END
            SQL;
    }

    /**
     * 鏡像表索引名，超過 MySQL 64 字元上限時退回短名。
     */
    private static function mirrorIndexName(string $mirror, string $column): string
    {
        $name = sprintf('%s_%s_index', $mirror, $column);

        return strlen($name) <= 64 ? $name : sprintf('idx_mirror_%s', $column);
    }

    /**
     * 目標表守衛：識別字合法、存在於當前 DB、非基礎設施表、非鏡像表。
     */
    private function assertManagedTable(string $table): void
    {
        $this->assertIdentifier($table, '表名');

        if (in_array($table, self::TABLE_BLACKLIST, true)) {
            throw new InvalidArgumentException(sprintf('%s 是基礎設施表，不開放本操作', $table));
        }

        if (str_ends_with($table, '_mirror')) {
            throw new InvalidArgumentException('鏡像表不可再作為目標（請對原表操作）');
        }

        $exists = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = ?',
            [$table, 'BASE TABLE'],
        );

        if ($exists === null) {
            throw new InvalidArgumentException(sprintf('資料表 %s 不存在於當前 DB', $table));
        }
    }

    /**
     * 鏡像流程要求 id 主鍵＋auto_increment（回填以 id 分段、trigger 以 id 對列）。
     */
    private function assertHasAutoIncrementIdPrimaryKey(string $table): void
    {
        $id = DB::selectOne(
            'SELECT COLUMN_KEY AS column_key, EXTRA AS extra FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'id'],
        );

        if ($id === null || $id->column_key !== 'PRI' || !str_contains((string) $id->extra, 'auto_increment')) {
            throw new InvalidArgumentException(
                sprintf('%s 沒有 auto_increment 的 id 主鍵，鏡像回填無法以 id 分段，不支援', $table),
            );
        }
    }

    /**
     * 已有以該欄位為第一欄的索引時回索引名（此時不需鏡像表）。
     */
    private function indexLeadingOnColumn(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT INDEX_NAME AS index_name FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1 LIMIT 1',
            [$table, $column],
        );

        return $row?->index_name;
    }

    /**
     * 防重複建置：鏡像表／同步 trigger／回填 EVENT／未完成進度列，任一殘留即擋下。
     */
    private function assertNoMirrorLeftover(string $table): void
    {
        $mirror = $table . '_mirror';

        $mirrorExists = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$mirror],
        );

        if ($mirrorExists !== null) {
            throw new InvalidArgumentException(
                sprintf('鏡像表 %s 已存在（建置中或未拆除），拆除方式見 docs/reconcile/payout-time-鏡像表建置.md「全部刪除」', $mirror),
            );
        }

        $trigger = DB::selectOne(
            'SELECT TRIGGER_NAME AS trigger_name FROM information_schema.TRIGGERS '
            . "WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? AND TRIGGER_NAME LIKE '%\\_mirror\\_%' LIMIT 1",
            [$table],
        );

        if ($trigger !== null) {
            throw new InvalidArgumentException(sprintf('資料表 %s 已掛鏡像同步 trigger（%s），先拆除再重建', $table, $trigger->trigger_name));
        }

        $event = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = ?',
            ['ev_backfill_' . $table],
        );

        if ($event !== null) {
            throw new InvalidArgumentException(sprintf('回填 EVENT ev_backfill_%s 已存在，先拆除再重建', $table));
        }

        $state = DB::selectOne('SELECT done FROM mirror_backfill_state WHERE src_table = ? AND done = 0', [$table]);

        if ($state !== null) {
            throw new InvalidArgumentException(sprintf('%s 有未完成的回填進度列，先查明再重建（mirror_backfill_state）', $table));
        }
    }

    private function tableExists(string $table): bool
    {
        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        ) !== null;
    }

    private function procedureExists(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ? AND ROUTINE_TYPE = ?',
            [self::BACKFILL_PROCEDURE, 'PROCEDURE'],
        ) !== null;
    }

    /**
     * 其他表的回填 EVENT 數（rename 收官判斷共用 SP 能不能一起刪）。
     */
    private function backfillEventCountExcluding(string $table): int
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS cnt FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME LIKE 'ev\\_backfill\\_%' AND EVENT_NAME != ?",
            ['ev_backfill_' . $table],
        );

        return (int) ($row->cnt ?? 0);
    }

    /**
     * @return array<string, string> 欄位名 => 型別，依 ORDINAL_POSITION 排序
     */
    private function columnsOf(string $table): array
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table],
        );

        $columns = [];

        foreach ($rows as $row) {
            $columns[(string) $row->name] = (string) $row->type;
        }

        return $columns;
    }

    private function assertIdentifier(string $value, string $label): void
    {
        self::assertIdentifierStatic($value, $label);
    }

    private static function assertIdentifierStatic(string $value, string $label): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s「%s」不是合法的 MySQL 識別字（英文開頭、限英數與底線、64 字元內）', $label, $value));
        }
    }

    private static function assertLiteral(string $value, string $label): void
    {
        if (preg_match(self::LITERAL_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s 含不允許的字元（禁止引號與反斜線，100 字元內）', $label));
        }
    }
}
