<?php

declare(strict_types=1);

namespace Tests\Feature\StandardBanks;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentBanksRebuildMigrationTest extends TestCase
{
    public function test_agent_banks_has_projection_schema(): void
    {
        $this->assertTrue(Schema::hasColumns('agent_banks', [
            'id', 'agent_group_id', 'standard_bank_id', 'source_bank_id',
            'min_amount', 'max_amount', 'status', 'created_at', 'updated_at',
        ]));
        // 決策 2：渠道歸 cash_flow_banks，代理層不再持有 cash_flow_id / bank_id
        $this->assertFalse(Schema::hasColumn('agent_banks', 'cash_flow_id'));
        $this->assertFalse(Schema::hasColumn('agent_banks', 'bank_id'));
        $this->assertTrue(Schema::hasIndex('agent_banks', 'uq_group_standard_bank'));
    }

    public function test_cash_flow_banks_has_standard_bank_id(): void
    {
        $this->assertTrue(Schema::hasColumn('cash_flow_banks', 'standard_bank_id'));
        $this->assertTrue(Schema::hasIndex('cash_flow_banks', 'idx_cfb_standard_bank_id'));
    }
}
