<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\AgentBank;
use App\Models\StandardBank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgentBankTest extends TestCase
{
    use DatabaseTransactions;

    public function test_create_and_standard_bank_relation(): void
    {
        // currencies 的 rate / admin 是 NOT NULL 無預設；用 DB::table 直插以繞過 model $guarded
        $currencyId = DB::table('currencies')->insertGetId([
            'code' => 'TSM',
            'name' => 'Test AgentBank Currency',
            'rate' => 1,
            'admin' => 0,
        ]);

        $std = StandardBank::query()->create([
            'code' => 'AGBTEST',
            'currency_id' => $currencyId,
            'currency_code' => 'TSM',
            'type' => 1,
            'name' => 'AgentBank Relation Test',
            'aliases' => [],
            'status' => 1,
        ]);

        $agentBank = AgentBank::query()->create([
            'agent_group_id' => 99999,
            'standard_bank_id' => $std->id,
            'source_bank_id' => 1,
            'min_amount' => '10.0000',
            'max_amount' => '0.0000',
            'status' => 1,
        ]);

        $this->assertSame($std->id, $agentBank->standardBank->id);
    }
}
