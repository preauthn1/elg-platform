<?php

declare(strict_types=1);

namespace Tests\Feature\StandardBanks;

use App\Models\Bank;
use App\Models\StandardBank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncProjectionCommandTest extends TestCase
{
    use DatabaseTransactions;

    private int $currencyId;

    private int $stdId;

    protected function setUp(): void
    {
        parent::setUp();

        // currencies 的 rate / admin 是 NOT NULL 無預設；用 DB::table 直插以繞過 model $guarded
        $this->currencyId = DB::table('currencies')->insertGetId([
            'code' => 'TSQ',
            'name' => 'Test Projection Cmd Currency',
            'rate' => 1,
            'admin' => 0,
        ]);

        $this->stdId = StandardBank::query()->create([
            'code' => 'PROJCMD',
            'currency_id' => $this->currencyId,
            'currency_code' => 'TSQ',
            'type' => 1,
            'name' => 'Projection Cmd Bank',
            'aliases' => [],
            'status' => 1,
        ])->id;

        Bank::query()->create([
            'agent_group_id' => 88001,
            'currency_id' => $this->currencyId,
            'type' => 1,
            'code' => 'PROJCMD',
            'name' => 'Bank PROJCMD',
            'standard_bank_id' => $this->stdId,
            'status' => 1,
        ]);
    }

    private function makeBank(string $code, int $status): Bank
    {
        return Bank::query()->create([
            'agent_group_id' => 88001,
            'currency_id' => $this->currencyId,
            'type' => 1,
            'code' => $code,
            'name' => 'Bank ' . $code,
            'standard_bank_id' => $this->stdId,
            'status' => $status,
        ]);
    }

    public function test_reports_auto_resolved_ambiguous_group(): void
    {
        $this->makeBank('PROJCMD-OLD', status: 0);

        $this->artisan('bank:sync-projection --dry-run')
            ->expectsOutputToContain('自動解歧義：group=88001')
            ->assertSuccessful();
    }

    public function test_reports_skip_reason_when_multiple_rows_are_active(): void
    {
        $this->makeBank('PROJCMD-B', status: 1);

        $this->artisan('bank:sync-projection --dry-run')
            ->expectsOutputToContain('原因=multiple_active')
            ->assertSuccessful();
    }

    public function test_dry_run_previews_without_writing(): void
    {
        $this->artisan('bank:sync-projection --dry-run')
            ->expectsOutputToContain('[dry-run] 未寫入')
            ->assertSuccessful();

        $this->assertDatabaseMissing('agent_banks', ['agent_group_id' => 88001]);
    }

    public function test_run_projects_and_reports(): void
    {
        $this->artisan('bank:sync-projection')->assertSuccessful();

        $this->assertDatabaseHas('agent_banks', [
            'agent_group_id' => 88001,
            'standard_bank_id' => $this->stdId,
        ]);
    }
}
