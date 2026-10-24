<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Tool;

use App\Console\Commands\Tool\AuditSchemaDrift;
use PHPUnit\Framework\TestCase;

/**
 * Synthetic test：純 PHP fixture 餵進 detectDrifts，不接 DB / Laravel container。
 * 7 個 case 對應 spec 的「測試策略 → Synthetic test」段：missing column（表存在）、
 * column meta drift、generated expression drift、index seq drift、index unique drift、
 * no-drift happy path、table-only missing 進 table_missing 桶不展開欄位（防灌水）。
 */
class AuditSchemaDriftTest extends TestCase
{
    private AuditSchemaDrift $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new AuditSchemaDrift;
    }

    public function test_detects_missing_column_when_table_exists_on_both(): void
    {
        $schemas = $this->buildSchemas(connectionsWithColumns: [
            'mysql' => ['acfot' => ['tax' => $this->col('decimal(18,4)')]],
            'Me77' => ['acfot' => []], // 表存在但無 tax 欄位
        ]);

        $report = $this->command->detectDrifts(['acfot'], $schemas);

        $this->assertSame([], $report['table_missing']);
        $this->assertArrayHasKey('acfot', $report['column_drift']);
        $this->assertArrayHasKey('tax', $report['column_drift']['acfot']['columns']);
        $this->assertNull($report['column_drift']['acfot']['columns']['tax']['Me77']);
    }

    public function test_table_only_missing_routes_to_table_missing_bucket(): void
    {
        // mysql 有整張表，Me77 完全沒有此表 → 進 table_missing 桶，
        // column_drift 桶不應出現該表（不展開欄位 drift 避免灌水）。
        $schemas = $this->buildSchemas(connectionsWithColumns: [
            'mysql' => ['only_in_mysql' => [
                'id' => $this->col('bigint'),
                'name' => $this->col('varchar(64)'),
                'amount' => $this->col('decimal(18,4)'),
            ]],
            'Me77' => [],
        ]);

        $report = $this->command->detectDrifts(['only_in_mysql'], $schemas);

        $this->assertArrayNotHasKey('only_in_mysql', $report['column_drift']);
        $this->assertArrayHasKey('only_in_mysql', $report['table_missing']);
        $this->assertSame(['Me77'], $report['table_missing']['only_in_mysql']['missing_in']);
        $this->assertSame(['mysql'], $report['table_missing']['only_in_mysql']['present_in']);
    }

    public function test_detects_column_type_drift(): void
    {
        $schemas = $this->buildSchemas(connectionsWithColumns: [
            'mysql' => ['t' => ['amount' => $this->col('decimal(18,4)')]],
            'Me77' => ['t' => ['amount' => $this->col('decimal(18,2)')]],
        ]);

        $report = $this->command->detectDrifts(['t'], $schemas);

        $this->assertArrayHasKey('amount', $report['column_drift']['t']['columns']);
    }

    public function test_detects_generation_expression_drift(): void
    {
        $schemas = $this->buildSchemas(connectionsWithColumns: [
            'mysql' => ['gw' => ['gameId' => $this->col('varchar(4)', generation: 'left(`themeId`,4)')]],
            'Me77' => ['gw' => ['gameId' => $this->col('varchar(4)', generation: 'left(`themeId`,6)')]],
        ]);

        $report = $this->command->detectDrifts(['gw'], $schemas);

        $this->assertArrayHasKey('gameId', $report['column_drift']['gw']['columns']);
    }

    public function test_detects_index_seq_drift(): void
    {
        $schemas = $this->buildSchemas(
            connectionsWithColumns: [
                'mysql' => ['t' => ['a' => $this->col('int'), 'b' => $this->col('int')]],
                'Me77' => ['t' => ['a' => $this->col('int'), 'b' => $this->col('int')]],
            ],
            connectionsWithIndex: [
                'mysql' => ['t' => ['idx_ab' => ['columns' => ['a', 'b'], 'unique' => false]]],
                'Me77' => ['t' => ['idx_ab' => ['columns' => ['b', 'a'], 'unique' => false]]],
            ],
        );

        $report = $this->command->detectDrifts(['t'], $schemas);

        $this->assertArrayHasKey('idx_ab', $report['column_drift']['t']['indexes']);
    }

    public function test_detects_index_unique_drift(): void
    {
        $schemas = $this->buildSchemas(
            connectionsWithColumns: [
                'mysql' => ['t' => ['a' => $this->col('int')]],
                'Me77' => ['t' => ['a' => $this->col('int')]],
            ],
            connectionsWithIndex: [
                'mysql' => ['t' => ['idx_a' => ['columns' => ['a'], 'unique' => true]]],
                'Me77' => ['t' => ['idx_a' => ['columns' => ['a'], 'unique' => false]]],
            ],
        );

        $report = $this->command->detectDrifts(['t'], $schemas);

        $this->assertArrayHasKey('idx_a', $report['column_drift']['t']['indexes']);
    }

    public function test_no_drift_returns_empty_buckets(): void
    {
        $col = $this->col('decimal(18,4)');
        $schemas = $this->buildSchemas(connectionsWithColumns: [
            'mysql' => ['t' => ['amount' => $col]],
            'Me77' => ['t' => ['amount' => $col]],
        ]);

        $report = $this->command->detectDrifts(['t'], $schemas);

        $this->assertSame([], $report['table_missing']);
        $this->assertSame([], $report['column_drift']);
    }

    public function test_detects_view_drift(): void
    {
        $schemas = $this->buildSchemas(connectionsWithViews: [
            'mysql' => ['v_orders' => 'sha-aaa'],
            'Me77' => ['v_orders' => 'sha-bbb'],
        ]);

        $report = $this->command->detectDrifts([], $schemas);

        $this->assertArrayHasKey('v_orders', $report['view_drift']);
        $this->assertSame('sha-aaa', $report['view_drift']['v_orders']['mysql']);
        $this->assertSame('sha-bbb', $report['view_drift']['v_orders']['Me77']);
    }

    public function test_detects_view_missing_in_subset(): void
    {
        $schemas = $this->buildSchemas(connectionsWithViews: [
            'mysql' => ['v_only_mysql' => 'sha-x'],
            'Me77' => [],
        ]);

        $report = $this->command->detectDrifts([], $schemas);

        $this->assertArrayHasKey('v_only_mysql', $report['view_drift']);
        $this->assertNull($report['view_drift']['v_only_mysql']['Me77']);
    }

    public function test_detects_trigger_drift(): void
    {
        $schemas = $this->buildSchemas(connectionsWithTriggers: [
            'mysql' => ['after_insert_x' => 'AFTER INSERT ON x | sha256:abc123'],
            'Me77' => [],
        ]);

        $report = $this->command->detectDrifts([], $schemas);

        $this->assertArrayHasKey('after_insert_x', $report['trigger_drift']);
        $this->assertNull($report['trigger_drift']['after_insert_x']['Me77']);
    }

    public function test_detects_routine_drift(): void
    {
        $schemas = $this->buildSchemas(connectionsWithRoutines: [
            'mysql' => ['SP_calc_profit_share' => 'PROCEDURE | sha256:aaa'],
            'Me77' => [],
        ]);

        $report = $this->command->detectDrifts([], $schemas);

        $this->assertArrayHasKey('SP_calc_profit_share', $report['routine_drift']);
        $this->assertNull($report['routine_drift']['SP_calc_profit_share']['Me77']);
    }

    /**
     * @return array{type:string,nullable:string,default:?string,extra:string,generation:string,position:int}
     */
    private function col(string $type, string $generation = ''): array
    {
        return [
            'type' => $type,
            'nullable' => 'NO',
            'default' => null,
            'extra' => $generation === '' ? '' : 'VIRTUAL GENERATED',
            'generation' => $generation,
            'position' => 1,
        ];
    }

    /**
     * @param  array<string, array<string, array<string, array>>>  $connectionsWithColumns
     * @param  array<string, array<string, array<string, array{columns:string[],unique:bool}>>>  $connectionsWithIndex
     * @param  array<string, array<string, string>>  $connectionsWithViews
     * @param  array<string, array<string, string>>  $connectionsWithTriggers
     * @param  array<string, array<string, string>>  $connectionsWithRoutines
     * @return array<string, array{columns: array, indexes: array, views: array, triggers: array, routines: array, failed: bool}>
     */
    private function buildSchemas(
        array $connectionsWithColumns = [],
        array $connectionsWithIndex = [],
        array $connectionsWithViews = [],
        array $connectionsWithTriggers = [],
        array $connectionsWithRoutines = [],
    ): array {
        $all = array_unique(array_merge(
            array_keys($connectionsWithColumns),
            array_keys($connectionsWithIndex),
            array_keys($connectionsWithViews),
            array_keys($connectionsWithTriggers),
            array_keys($connectionsWithRoutines),
        ));
        $schemas = [];
        foreach ($all as $conn) {
            $schemas[$conn] = [
                'columns' => $connectionsWithColumns[$conn] ?? [],
                'indexes' => $connectionsWithIndex[$conn] ?? [],
                'views' => $connectionsWithViews[$conn] ?? [],
                'triggers' => $connectionsWithTriggers[$conn] ?? [],
                'routines' => $connectionsWithRoutines[$conn] ?? [],
                'failed' => false,
            ];
        }

        return $schemas;
    }
}
