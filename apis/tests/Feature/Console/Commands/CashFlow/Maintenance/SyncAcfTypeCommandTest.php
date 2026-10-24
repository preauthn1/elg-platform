<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Maintenance;

use App\Enums\CashFlowTypeEnum;
use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Console/Commands/CashFlow/Maintenance/SyncAcfTypeCommandTest.php
 *
 * cash-flow:sync-acf-type：收斂 agent_cash_flows.cash_flow_type 便利欄＝cash_flows.type。
 * Observer 只攔得到 Eloquent 路徑；舊系統（API_Agent 後台建 ACF）與 raw SQL 寫入的列由本命令收斂。
 * 測試以 DB::table 直改模擬「繞過 Observer 的外部寫入者」。
 */
class SyncAcfTypeCommandTest extends TestCase
{
    private const int CURRENCY_MYR = 1;

    private const int AGENT_GROUP = 688;

    public function test_dry_run_reports_pending_rows_without_writing(): void
    {
        $acf = $this->makeAcfWithRawType(CashFlowTypeEnum::WITHDRAW->value, rawCashFlowType: null);

        $this->artisan('cash-flow:sync-acf-type')->assertSuccessful();

        $this->assertNull($this->rawCashFlowType($acf), 'dry-run 不得寫入');
    }

    public function test_execute_fills_null_and_fixes_mismatch(): void
    {
        $nullRow = $this->makeAcfWithRawType(CashFlowTypeEnum::WITHDRAW->value, rawCashFlowType: null);
        $mismatch = $this->makeAcfWithRawType(
            CashFlowTypeEnum::WITHDRAW->value,
            rawCashFlowType: CashFlowTypeEnum::DEPOSIT->value,
        );

        $this->artisan('cash-flow:sync-acf-type --execute')->assertSuccessful();

        $this->assertSame(CashFlowTypeEnum::WITHDRAW->value, $this->rawCashFlowType($nullRow), 'NULL 應補齊為 CF type');
        $this->assertSame(CashFlowTypeEnum::WITHDRAW->value, $this->rawCashFlowType($mismatch), '與 CF 不一致者應被矯正');
    }

    public function test_execute_covers_soft_deleted_acf(): void
    {
        $acf = $this->makeAcfWithRawType(CashFlowTypeEnum::DEPOSIT->value, rawCashFlowType: null);
        $acf->delete();

        $this->artisan('cash-flow:sync-acf-type --execute')->assertSuccessful();

        $this->assertSame(
            CashFlowTypeEnum::DEPOSIT->value,
            $this->rawCashFlowType($acf),
            '軟刪 ACF 仍供對帳／客服查歷史，一樣要收斂',
        );
    }

    public function test_execute_skips_dangling_cash_flow_id(): void
    {
        $acf = $this->makeDanglingAcf();

        $this->artisan('cash-flow:sync-acf-type --execute')->assertSuccessful();

        $this->assertNull($this->rawCashFlowType($acf), '懸空 cash_flow_id 無真值可繼承，應跳過留 NULL');
    }

    public function test_execute_is_idempotent(): void
    {
        $this->makeAcfWithRawType(CashFlowTypeEnum::WITHDRAW->value, rawCashFlowType: null);

        $this->artisan('cash-flow:sync-acf-type --execute')->assertSuccessful();

        $this->artisan('cash-flow:sync-acf-type --execute')
            ->expectsOutputToContain('待同步 0 筆')
            ->assertSuccessful();
    }

    private function rawCashFlowType(AgentCashFlow $acf): ?int
    {
        $value = DB::table('agent_cash_flows')->where('id', $acf->id)->value('cash_flow_type');

        return $value === null ? null : (int) $value;
    }

    /**
     * 建 CF＋ACF 後以 raw SQL 直改 cash_flow_type，模擬繞過 Observer 的外部寫入者（舊系統／raw SQL）
     */
    private function makeAcfWithRawType(int $cashFlowType, ?int $rawCashFlowType): AgentCashFlow
    {
        $cashFlow = CashFlow::create([
            'name' => 'Sync Type ' . uniqid(),
            'type' => $cashFlowType,
            'third_party_code' => 'D_TTPAY',
            'status' => 1,
            'admin' => 0,
        ]);

        $acf = $this->makeAcf($cashFlow->id);

        DB::table('agent_cash_flows')->where('id', $acf->id)->update(['cash_flow_type' => $rawCashFlowType]);

        return $acf;
    }

    private function makeDanglingAcf(): AgentCashFlow
    {
        return $this->makeAcf(99999999);
    }

    private function makeAcf(int $cashFlowId): AgentCashFlow
    {
        return AgentCashFlow::create([
            'cash_flow_id' => $cashFlowId,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => self::CURRENCY_MYR,
            'cash_flow_info_id' => 0,
            'name' => '(MYR) Sync Test',
            'rate' => 1,
            'info' => [],
            'status' => 1,
            'admin' => 0,
        ]);
    }
}
