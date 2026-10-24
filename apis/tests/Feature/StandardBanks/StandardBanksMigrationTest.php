<?php

declare(strict_types=1);

namespace Tests\Feature\StandardBanks;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StandardBanksMigrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_standard_banks_has_rich_schema_columns(): void
    {
        $expected = ['id', 'code', 'currency_id', 'currency_code', 'type', 'name', 'swift', 'aliases', 'remark', 'status', 'created_at', 'updated_at'];
        $actual = Schema::getColumnListing('standard_banks');
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_standard_banks_has_no_soft_delete_column(): void
    {
        $this->assertFalse(Schema::hasColumn('standard_banks', 'deleted_at'));
    }

    public function test_banks_has_standard_bank_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('banks', 'standard_bank_id'));
    }
}
