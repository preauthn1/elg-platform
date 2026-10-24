<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\SQLHelper;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * SQLHelper 測試
 *
 * php artisan test tests/Unit/Helpers/SQLHelperTest.php
 */
class SQLHelperTest extends TestCase
{
    public function test_insert_ignore_empty_returns_zero(): void
    {
        $this->assertSame(0, SQLHelper::insertIgnore('any_table', []));
    }

    public function test_insert_ignore_builds_statement_and_bindings(): void
    {
        DB::shouldReceive('affectingStatement')
            ->once()
            ->with(
                Mockery::on(function (string $sql): bool {
                    return str_contains($sql, 'INSERT IGNORE INTO test_table')
                        && str_contains($sql, 'VALUES (?, ?), (?, ?)');
                }),
                [1, 2, 3, 4]
            )
            ->andReturn(2);

        $rows = SQLHelper::insertIgnore('test_table', [
            ['a' => 1, 'b' => 2],
            ['a' => 3, 'b' => 4],
        ]);

        $this->assertSame(2, $rows);
    }
}
