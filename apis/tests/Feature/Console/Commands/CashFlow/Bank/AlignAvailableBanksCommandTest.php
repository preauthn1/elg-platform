<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlignAvailableBanksCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const CASH_FLOW_ID = 99555;

    private const AGENT_GROUP = 99700;

    private function seedDriftCase(): AgentCashFlow
    {
        $c = (int) DB::table('currencies')->insertGetId([
            'code' => 'TST', 'name' => 'Test Currency', 'rate' => 1, 'admin' => 0,
        ]);
        CashFlow::create([
            'id' => self::CASH_FLOW_ID, 'name' => 'Test Payout', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ]);
        foreach ([['MayBank', 1], ['WTMY02', 0]] as [$code, $status]) {
            Bank::create([
                'agent_group_id' => self::AGENT_GROUP, 'type' => 1, 'currency_id' => $c,
                'name' => '(TST) - Maybank', 'code' => $code, 'status' => $status,
                'rate' => 1, 'tax_type' => 0, 'tax' => 0, 'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
            ]);
        }

        return AgentCashFlow::create([
            'cash_flow_id' => self::CASH_FLOW_ID, 'agent_group_id' => self::AGENT_GROUP, 'currency_id' => $c,
            'info' => ['available_banks' => [['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'TTP_MB']]], 'admin' => 0,
        ]);
    }

    public function test_dry_run_reports_but_does_not_write(): void
    {
        $acf = $this->seedDriftCase();

        $this->artisan('cash-flow:align-available-banks', ['--cash-flow' => self::CASH_FLOW_ID])
            ->expectsOutputToContain('WTMY02')
            ->assertExitCode(0);

        $this->assertSame('WTMY02', $acf->fresh()->info['available_banks'][0]['BankCode_ELG']);
        $this->assertSame(0, DB::table('available_banks_align_logs')->count());
    }

    public function test_execute_force_aligns(): void
    {
        $acf = $this->seedDriftCase();

        $this->artisan('cash-flow:align-available-banks', [
            '--cash-flow' => self::CASH_FLOW_ID, '--execute' => true, '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame('MayBank', $acf->fresh()->info['available_banks'][0]['BankCode_ELG']);
        $this->assertSame(1, DB::table('available_banks_align_logs')->where('action', 'align')->count());
    }

    public function test_execute_without_scope_is_rejected(): void
    {
        $this->seedDriftCase();

        // 無 --agent-group 也無 --cash-flow 的 execute → 拒絕、不寫
        $this->artisan('cash-flow:align-available-banks', ['--execute' => true, '--force' => true])
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('available_banks_align_logs')->count());
    }

    public function test_no_drift_exits_success(): void
    {
        $this->artisan('cash-flow:align-available-banks', ['--cash-flow' => self::CASH_FLOW_ID])
            ->expectsOutputToContain('未發現 available_banks drift')
            ->assertExitCode(0);
    }
}
