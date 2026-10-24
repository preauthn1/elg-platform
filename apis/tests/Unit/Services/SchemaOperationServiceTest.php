<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SchemaOperationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * SchemaOperationService 測試：只驗純 SQL 建構與輸入守衛，不執行任何 DDL
 * （DDL 隱式 commit 不可回滾，依 .claude/rules/php.md 測試安全紅線不進測試庫）。
 *
 * php artisan test tests/Unit/Services/SchemaOperationServiceTest.php
 */
class SchemaOperationServiceTest extends TestCase
{
    public function test_build_trigger_sqls_covers_insert_update_delete_with_full_column_list(): void
    {
        $sqls = SchemaOperationService::buildTriggerSqls('demo_bet_records', ['id', 'bet_id', 'payout_time']);

        $this->assertCount(3, $sqls);

        // 新增與更新共用同一份 REPLACE 整列語句（欄位完整明列）
        $replace = 'REPLACE INTO `demo_bet_records_mirror` (`id`, `bet_id`, `payout_time`) '
            . 'VALUES (NEW.`id`, NEW.`bet_id`, NEW.`payout_time`)';
        $this->assertSame(
            "CREATE TRIGGER `trg_demo_bet_records_mirror_ai` AFTER INSERT ON `demo_bet_records` FOR EACH ROW {$replace}",
            $sqls[0],
        );
        $this->assertSame(
            "CREATE TRIGGER `trg_demo_bet_records_mirror_au` AFTER UPDATE ON `demo_bet_records` FOR EACH ROW {$replace}",
            $sqls[1],
        );
        $this->assertSame(
            'CREATE TRIGGER `trg_demo_bet_records_mirror_ad` AFTER DELETE ON `demo_bet_records` '
            . 'FOR EACH ROW DELETE FROM `demo_bet_records_mirror` WHERE id = OLD.id',
            $sqls[2],
        );
    }

    public function test_build_state_upsert_sql_takes_watermark_from_max_id_and_marks_done_when_nothing_to_backfill(): void
    {
        $sql = SchemaOperationService::buildStateUpsertSql('demo_bet_records', 85000000);

        // 空表（MAX(id) NULL→0）或 min_id >= MAX(id) 時 SP 會直接 LEAVE、永遠不寫 done=1，所以建立當下就要標完成
        $this->assertSame(
            'INSERT INTO mirror_backfill_state (src_table, next_id, min_id, done) '
            . "SELECT 'demo_bet_records', IFNULL(MAX(id), 0), 85000000, IF(IFNULL(MAX(id), 0) <= 85000000, 1, 0) FROM `demo_bet_records` "
            . 'ON DUPLICATE KEY UPDATE next_id = VALUES(next_id), min_id = VALUES(min_id), done = VALUES(done)',
            $sql,
        );
    }

    public function test_build_event_sql_schedules_backfill_every_five_seconds(): void
    {
        $sql = SchemaOperationService::buildEventSql('demo_bet_records', 8000);

        $this->assertSame(
            'CREATE EVENT `ev_backfill_demo_bet_records` ON SCHEDULE EVERY 5 SECOND ON COMPLETION PRESERVE '
            . "DO CALL SP_Backfill_Mirror_Step('demo_bet_records', 8000)",
            $sql,
        );
    }

    public function test_backfill_procedure_sql_guards_overlap_and_walks_down_to_min_id(): void
    {
        $sql = SchemaOperationService::backfillProcedureSql();

        // EVENT 不防重疊 → SP 內以 GET_LOCK 互斥
        $this->assertStringContainsString('GET_LOCK(v_lock, 0)', $sql);
        $this->assertStringContainsString('DO RELEASE_LOCK(v_lock);', $sql);
        // 由大到小走、最低到 min_id（不含）
        $this->assertStringContainsString('SET v_lo = IF(v_hi > v_min + p_chunk, v_hi - p_chunk, v_min);', $sql);
        // 回填只補洞，不覆蓋 trigger 寫入的較新資料
        $this->assertStringContainsString('INSERT IGNORE INTO', $sql);
        // 異常時釋放鎖並發 TG 通知
        $this->assertStringContainsString('COM_Send_Error_Message', $sql);
    }

    public function test_build_add_column_sql_variants(): void
    {
        $this->assertSame(
            'ALTER TABLE `orders` ADD COLUMN `remark` VARCHAR(100) NULL, ALGORITHM=INSTANT',
            SchemaOperationService::buildAddColumnSql('orders', 'remark', 'varchar(100)', true, null, null),
        );

        $this->assertSame(
            "ALTER TABLE `orders` ADD COLUMN `retry_count` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '重試次數', ALGORITHM=INSTANT",
            SchemaOperationService::buildAddColumnSql('orders', 'retry_count', 'INT UNSIGNED', false, '0', '重試次數'),
        );
    }

    public function test_build_add_column_sql_rejects_type_outside_whitelist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SchemaOperationService::buildAddColumnSql('orders', 'blob_data', 'BLOB', true, null, null);
    }

    public function test_build_add_column_sql_rejects_default_on_json(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SchemaOperationService::buildAddColumnSql('orders', 'infos', 'JSON', true, '{}', null);
    }

    public function test_build_add_column_sql_rejects_illegal_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SchemaOperationService::buildAddColumnSql('orders', 'bad-name', 'INT', true, null, null);
    }

    public function test_build_atomic_swap_sql_is_single_multi_table_rename(): void
    {
        // 單一多表 RENAME＝原子換表核心：全有或全無，換表瞬間持 MDL 擋併發寫入
        $this->assertSame(
            'RENAME TABLE `demo_bet_records` TO `demo_bet_records_20260903`, `demo_bet_records_mirror` TO `demo_bet_records`',
            SchemaOperationService::buildAtomicSwapSql('demo_bet_records', 'demo_bet_records_20260903'),
        );
    }

    public function test_build_cancel_sqls_drops_triggers_before_mirror_table(): void
    {
        $sqls = SchemaOperationService::buildCancelSqls('demo_bet_records');

        // 順序保命：trigger 必須先於鏡像表刪除，否則來源表寫入會因 trigger 指向已消失的表而報錯
        $mirrorDropIndex = array_search('DROP TABLE IF EXISTS `demo_bet_records_mirror`', $sqls, true);
        $this->assertIsInt($mirrorDropIndex);

        foreach (['ai', 'au', 'ad'] as $suffix) {
            $triggerDropIndex = array_search(
                sprintf('DROP TRIGGER IF EXISTS `trg_demo_bet_records_mirror_%s`', $suffix),
                $sqls,
                true,
            );
            $this->assertIsInt($triggerDropIndex);
            $this->assertLessThan($mirrorDropIndex, $triggerDropIndex);
        }

        $this->assertSame('DROP EVENT IF EXISTS `ev_backfill_demo_bet_records`', $sqls[0]);
        $this->assertSame("DELETE FROM mirror_backfill_state WHERE src_table = 'demo_bet_records'", end($sqls));
    }

    public function test_renamed_index_name_strips_mirror_segment(): void
    {
        $this->assertSame(
            'demo_bet_records_payout_time_index',
            SchemaOperationService::renamedIndexName('demo_bet_records_mirror_payout_time_index'),
        );
        $this->assertSame('idx_payout_time', SchemaOperationService::renamedIndexName('idx_mirror_payout_time'));
    }

    public function test_status_report_shows_progress_and_missing_event_warning(): void
    {
        // 進度列 fixture 由 DatabaseTransactions 回滾；statusReport 為唯讀，不執行任何 DDL
        DB::insert(
            'INSERT INTO mirror_backfill_state (src_table, next_id, min_id, done) VALUES (?, ?, ?, ?)',
            ['fumasw_bet_records', 100, 0, 0],
        );

        $lines = (new SchemaOperationService)->statusReport('fumasw_bet_records');

        $this->assertStringContainsString('回填中', $lines[0]);
        // 回填未完成又沒有 EVENT，必須給停擺警示
        $this->assertStringContainsString('EVENT 不存在', $lines[0]);
        $this->assertStringContainsString('停擺', $lines[1]);
    }

    public function test_list_mirrored_tables_reflects_state_rows(): void
    {
        $before = (new SchemaOperationService)->listMirroredTables();
        $this->assertArrayNotHasKey('fumasw_bet_records', $before);

        // 進度列 fixture 由 DatabaseTransactions 回滾；本方法為唯讀
        DB::insert(
            'INSERT INTO mirror_backfill_state (src_table, next_id, min_id, done) VALUES (?, ?, ?, ?)',
            ['fumasw_bet_records', 100, 0, 0],
        );

        $options = (new SchemaOperationService)->listMirroredTables();

        $this->assertSame('fumasw_bet_records（回填中）', $options['fumasw_bet_records']);
    }

    public function test_status_report_without_state_rows_says_so(): void
    {
        $lines = (new SchemaOperationService)->statusReport('fumasw_bet_records');

        $this->assertSame(['fumasw_bet_records 沒有回填進度紀錄。'], $lines);
    }

    public function test_build_add_column_sql_rejects_quotes_in_literal(): void
    {
        // 引號被字面值白名單擋下，DEFAULT／COMMENT 不可能拼出跳脫攻擊
        $this->expectException(InvalidArgumentException::class);

        SchemaOperationService::buildAddColumnSql('orders', 'remark', 'VARCHAR(50)', true, "x', ALGORITHM=COPY --", null);
    }

    public function test_execute_shortens_lock_wait_timeout_only_while_running_statements(): void
    {
        $read = fn (): int => (int) DB::select('SELECT @@SESSION.lock_wait_timeout AS v', [], false)[0]->v;
        $before = $read();
        $this->assertNotSame(SchemaOperationService::LOCK_WAIT_TIMEOUT_SECONDS, $before, '測試前提：session 預設值不該剛好等於短 timeout');

        $during = null;
        // DO 0 不回結果集也不是 DDL：unprepared() 跑 SELECT 會留下未讀結果集，這裡只驗 timeout 包裹
        app(SchemaOperationService::class)->execute(['DO 0'], function () use (&$during, $read): void {
            $during = $read();
        });

        $this->assertSame(SchemaOperationService::LOCK_WAIT_TIMEOUT_SECONDS, $during, '執行期間應為短 timeout');
        $this->assertSame($before, $read(), '結束後應還原，不能留給 queue worker 的下一個 job');
    }

    public function test_execute_restores_lock_wait_timeout_when_a_statement_fails(): void
    {
        $read = fn (): int => (int) DB::select('SELECT @@SESSION.lock_wait_timeout AS v', [], false)[0]->v;
        $before = $read();

        try {
            app(SchemaOperationService::class)->execute(['SELECT * FROM `__no_such_table_for_schema_operation_test__`']);
            $this->fail('應拋出 RuntimeException');
        } catch (RuntimeException) {
        }

        $this->assertSame($before, $read());
    }

    public function test_retired_base_table_only_accepts_table_with_eight_digit_date_suffix(): void
    {
        $this->assertSame('total_bet_records', SchemaOperationService::retiredBaseTable('total_bet_records_20260511'));
        $this->assertSame('fumasw_bet_records', SchemaOperationService::retiredBaseTable('fumasw_bet_records_20260903'));

        // 不是退場表形狀：原表本身、鏡像表、位數不對、日期後還有東西
        $this->assertNull(SchemaOperationService::retiredBaseTable('total_bet_records'));
        $this->assertNull(SchemaOperationService::retiredBaseTable('total_bet_records_mirror'));
        $this->assertNull(SchemaOperationService::retiredBaseTable('total_bet_records_2026'));
        $this->assertNull(SchemaOperationService::retiredBaseTable('total_bet_records_20260511_bak'));
    }

    public function test_plan_drop_retired_rejects_non_retired_table_name_before_touching_db(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('不是退場表');

        app(SchemaOperationService::class)->planDropRetired('orders');
    }

    public function test_execute_reports_wall_time_and_restores_stage_profiling_setup(): void
    {
        $consumers = fn (): ?array => $this->stageConsumerStates();
        $before = $consumers();

        $seen = null;
        app(SchemaOperationService::class)->execute(['DO 0'], function (string $sql, float $seconds, ?array $stages) use (&$seen): void {
            $seen = [$sql, $seconds, $stages];
        });

        $this->assertNotNull($seen);
        $this->assertSame('DO 0', $seen[0]);
        $this->assertGreaterThanOrEqual(0.0, $seen[1]);
        // 有 performance_schema 權限時是陣列（每筆 stage/秒數），沒權限時是 null；兩者都合法
        if ($seen[2] !== null) {
            foreach ($seen[2] as $stage) {
                $this->assertIsString($stage['stage']);
                $this->assertIsFloat($stage['seconds']);
            }
        }

        // 全域 stage 儀表開關只能在執行期間開，結束後必須回到執行前的狀態
        $this->assertSame($before, $consumers());
    }

    /** @return array<string, string>|null NAME => ENABLED；測試帳號讀不到 performance_schema 時回 null */
    private function stageConsumerStates(): ?array
    {
        try {
            $rows = DB::select(
                "SELECT NAME, ENABLED FROM performance_schema.setup_consumers WHERE NAME IN ('events_stages_current', 'events_stages_history_long') ORDER BY NAME",
                [],
                false,
            );
        } catch (\Throwable) {
            return null;
        }

        return array_column(array_map(static fn (object $r): array => [(string) $r->NAME, (string) $r->ENABLED], $rows), 1, 0);
    }
}
