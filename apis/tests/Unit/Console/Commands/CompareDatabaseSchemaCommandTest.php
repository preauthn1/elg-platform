<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\Tool\CompareDatabaseSchemaCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * php artisan test tests/Unit/Console/Commands/CompareDatabaseSchemaCommandTest.php
 */
final class CompareDatabaseSchemaCommandTest extends TestCase
{
    public function test_empty_schemas_are_identical(): void
    {
        $diff = CompareDatabaseSchemaCommand::compareSchemaColumns([], []);

        $this->assertSame([], $diff['tables_only_left']);
        $this->assertSame([], $diff['tables_only_right']);
        $this->assertSame([], $diff['tables']);
    }

    public function test_detects_table_only_on_one_side(): void
    {
        $left = [
            'users' => [
                'id' => ['fingerprint' => 'a', 'label' => 'L'],
            ],
        ];
        $right = [
            'orders' => [
                'id' => ['fingerprint' => 'b', 'label' => 'R'],
            ],
        ];

        $diff = CompareDatabaseSchemaCommand::compareSchemaColumns($left, $right);

        $this->assertSame(['users'], $diff['tables_only_left']);
        $this->assertSame(['orders'], $diff['tables_only_right']);
        $this->assertSame([], $diff['tables']);
    }

    public function test_detects_column_and_definition_mismatch(): void
    {
        $left = [
            't' => [
                'a' => ['fingerprint' => '1', 'label' => 'left-a'],
                'b' => ['fingerprint' => 'x', 'label' => 'left-b'],
            ],
        ];
        $right = [
            't' => [
                'b' => ['fingerprint' => 'y', 'label' => 'right-b'],
                'c' => ['fingerprint' => '2', 'label' => 'right-c'],
            ],
        ];

        $diff = CompareDatabaseSchemaCommand::compareSchemaColumns($left, $right);

        $this->assertArrayHasKey('t', $diff['tables']);
        $detail = $diff['tables']['t'];

        $this->assertSame(['a'], $detail['columns_only_left']);
        $this->assertSame(['c'], $detail['columns_only_right']);
        $this->assertArrayHasKey('b', $detail['columns_mismatch']);
        $this->assertSame('left-b', $detail['columns_mismatch']['b']['left']);
        $this->assertSame('right-b', $detail['columns_mismatch']['b']['right']);
    }

    public function test_identical_tables_produce_no_table_section(): void
    {
        $schema = [
            't' => [
                'id' => ['fingerprint' => 'same', 'label' => 'x'],
            ],
        ];

        $diff = CompareDatabaseSchemaCommand::compareSchemaColumns($schema, $schema);

        $this->assertSame([], $diff['tables']);
    }

    public function test_presence_only_mode_ignores_definition_mismatch(): void
    {
        $left = [
            't' => [
                'id' => ['fingerprint' => '1', 'label' => 'left-id'],
            ],
        ];
        $right = [
            't' => [
                'id' => ['fingerprint' => '2', 'label' => 'right-id'],
            ],
        ];

        $diff = CompareDatabaseSchemaCommand::compareSchemaColumns($left, $right, onlyColumnPresence: true);

        $this->assertSame([], $diff['tables']);
    }

    public function test_presence_only_mode_still_detects_extra_columns(): void
    {
        $left = [
            't' => [
                'id' => ['fingerprint' => '1', 'label' => 'L'],
                'extra' => ['fingerprint' => 'e', 'label' => 'E'],
            ],
        ];
        $right = [
            't' => [
                'id' => ['fingerprint' => '2', 'label' => 'R'],
            ],
        ];

        $diff = CompareDatabaseSchemaCommand::compareSchemaColumns($left, $right, onlyColumnPresence: true);

        $this->assertArrayHasKey('t', $diff['tables']);
        $this->assertSame(['extra'], $diff['tables']['t']['columns_only_left']);
        $this->assertSame([], $diff['tables']['t']['columns_only_right']);
        $this->assertSame([], $diff['tables']['t']['columns_mismatch']);
    }

    public function test_should_exclude_table_names_with_bak_baks_or_numeric_segments(): void
    {
        $m = new ReflectionMethod(CompareDatabaseSchemaCommand::class, 'shouldExcludeTableByUnderscoreSegments');
        $exclude = static fn (string $table): bool => (bool) $m->invoke(null, $table);

        $this->assertTrue($exclude('users_bak'));
        $this->assertTrue($exclude('Users_BAK'));
        $this->assertTrue($exclude('archive_baks'));
        $this->assertTrue($exclude('orders_bak260217'));
        $this->assertTrue($exclude('t_BAK20250912'));
        $this->assertTrue($exclude('x_baks0001'));
        $this->assertTrue($exclude('log_2024'));
        $this->assertTrue($exclude('t_01'));
        $this->assertFalse($exclude('members'));
        $this->assertFalse($exclude('user_backup'));
        $this->assertFalse($exclude('member_v2'));
    }

    public function test_build_tables_like_sql_adds_or_clauses_and_bindings(): void
    {
        $bindings = ['xsgcasino'];
        $sql = CompareDatabaseSchemaCommand::buildTablesLikeSql(['shareholder', 'profit_share'], $bindings);

        $this->assertSame(
            ' AND (LOWER(c.TABLE_NAME) LIKE ? OR LOWER(c.TABLE_NAME) LIKE ?)',
            $sql
        );
        $this->assertSame(
            ['xsgcasino', '%shareholder%', '%profit_share%'],
            $bindings
        );
    }

    public function test_build_tables_like_sql_returns_empty_when_no_keywords(): void
    {
        $bindings = ['xsgcasino'];
        $sql = CompareDatabaseSchemaCommand::buildTablesLikeSql([], $bindings);

        $this->assertSame('', $sql);
        $this->assertSame(['xsgcasino'], $bindings);
    }
}
