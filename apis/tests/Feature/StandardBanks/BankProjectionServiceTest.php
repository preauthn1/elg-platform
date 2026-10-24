<?php

declare(strict_types=1);

namespace Tests\Feature\StandardBanks;

use App\Models\AgentBank;
use App\Models\Bank;
use App\Models\StandardBank;
use App\Services\CashFlow\Bank\BankProjectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BankProjectionServiceTest extends TestCase
{
    use DatabaseTransactions;

    private BankProjectionService $service;

    private int $currencyId;

    private int $stdId;

    private int $stdId2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BankProjectionService::class);

        // currencies 的 rate / admin 是 NOT NULL 無預設；用 DB::table 直插以繞過 model $guarded
        $this->currencyId = DB::table('currencies')->insertGetId([
            'code' => 'TSP',
            'name' => 'Test Projection Currency',
            'rate' => 1,
            'admin' => 0,
        ]);

        $this->stdId = StandardBank::query()->create([
            'code' => 'PROJBANK',
            'currency_id' => $this->currencyId,
            'currency_code' => 'TSP',
            'type' => 1,
            'name' => 'Projection Bank',
            'aliases' => [],
            'status' => 1,
        ])->id;

        $this->stdId2 = StandardBank::query()->create([
            'code' => 'PROJBANK2',
            'currency_id' => $this->currencyId,
            'currency_code' => 'TSP',
            'type' => 1,
            'name' => 'Projection Bank 2',
            'aliases' => [],
            'status' => 1,
        ])->id;
    }

    private function makeBank(int $group, string $code, ?int $stdId, int $status = 1): Bank
    {
        return Bank::query()->create([
            'agent_group_id' => $group,
            'currency_id' => $this->currencyId,
            'type' => 1,
            'code' => $code,
            'name' => 'Bank ' . $code,
            'standard_bank_id' => $stdId,
            'min_amount' => '10.0000',
            'max_amount' => '5000.0000',
            'status' => $status,
        ]);
    }

    /**
     * 只取本測試自己那個 group 的條目：agentBankCandidates() 掃全表，
     * 共用測試庫裡的既有資料會讓「整份清單為空」這類斷言隨環境變紅。
     *
     * @param  list<array{agent_group_id: int, ...}>  $entries
     * @return list<array<string, mixed>>
     */
    private function entriesOfGroup(array $entries, int $group): array
    {
        return array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['agent_group_id'] === $group,
        ));
    }

    public function test_projects_mapped_group_bank_with_source_and_limits(): void
    {
        $bank = $this->makeBank(77001, 'PROJBANK', $this->stdId);

        $candidates = $this->service->agentBankCandidates();
        $written = DB::transaction(fn (): int => $this->service->rebuildAgentBanks($candidates['rows']));

        $this->assertSame(1, $written);
        $this->assertDatabaseHas('agent_banks', [
            'agent_group_id' => 77001,
            'standard_bank_id' => $this->stdId,
            'source_bank_id' => $bank->id,
            'status' => 1,
        ]);
    }

    public function test_skips_ambiguous_group_and_reports(): void
    {
        // 同 (group, standard) 兩筆存活列且同時啟用 → 代表無法自動決定，整組跳過
        $this->makeBank(77002, 'PROJBANK', $this->stdId);
        $this->makeBank(77002, 'PROJBANK-ALT', $this->stdId);

        $candidates = $this->service->agentBankCandidates();
        DB::transaction(fn (): int => $this->service->rebuildAgentBanks($candidates['rows']));

        $this->assertDatabaseMissing('agent_banks', ['agent_group_id' => 77002]);
        $this->assertCount(1, $candidates['skipped']);
        $this->assertSame(77002, $candidates['skipped'][0]['agent_group_id']);
        $this->assertCount(2, $candidates['skipped'][0]['bank_ids']);
        $this->assertSame(BankProjectionService::SKIP_MULTIPLE_ACTIVE, $candidates['skipped'][0]['reason']);
        $this->assertSame([], $this->entriesOfGroup($candidates['resolved'], 77002));
    }

    public function test_resolves_ambiguous_group_when_exactly_one_row_is_active(): void
    {
        // 多存活列但只有一列啟用 → 取該啟用列當代表，不再整組跳過
        $stale = $this->makeBank(77021, 'PROJBANK-OLD', $this->stdId, status: 0);
        $active = $this->makeBank(77021, 'PROJBANK-NEW', $this->stdId);
        $stale2 = $this->makeBank(77021, 'PROJBANK-OLD2', $this->stdId, status: 0);

        // 停用列給不同限額，用來證明投影取的是啟用列的值而非停用列的
        DB::table('banks')->whereIn('id', [$stale->id, $stale2->id])
            ->update(['min_amount' => '777.0000', 'max_amount' => '888.0000']);

        $candidates = $this->service->agentBankCandidates();
        DB::transaction(fn (): int => $this->service->rebuildAgentBanks($candidates['rows']));

        $this->assertSame([], $this->entriesOfGroup($candidates['skipped'], 77021));
        $this->assertDatabaseHas('agent_banks', [
            'agent_group_id' => 77021,
            'standard_bank_id' => $this->stdId,
            'source_bank_id' => $active->id,
            'min_amount' => '10.0000',
            'max_amount' => '5000.0000',
            'status' => 1,
        ]);
    }

    public function test_resolved_group_is_reported_with_every_bank_id(): void
    {
        // resolved 要留下全組 bank_ids：投影通過不代表碼層重複已收斂
        $stale = $this->makeBank(77022, 'PROJBANK-OLD', $this->stdId, status: 0);
        $active = $this->makeBank(77022, 'PROJBANK-NEW', $this->stdId);

        $entries = $this->entriesOfGroup($this->service->agentBankCandidates()['resolved'], 77022);

        $this->assertCount(1, $entries);
        $this->assertSame($active->id, $entries[0]['source_bank_id']);
        $this->assertSame([$stale->id, $active->id], $entries[0]['bank_ids']);
    }

    public function test_skips_ambiguous_group_when_every_row_is_inactive(): void
    {
        // 多存活列但全部停用 → 無代表可選，仍然跳過（該軟刪的未軟刪）
        $this->makeBank(77023, 'PROJBANK-D1', $this->stdId, status: 0);
        $this->makeBank(77023, 'PROJBANK-D2', $this->stdId, status: 0);

        $candidates = $this->service->agentBankCandidates();
        DB::transaction(fn (): int => $this->service->rebuildAgentBanks($candidates['rows']));

        $entries = $this->entriesOfGroup($candidates['skipped'], 77023);

        $this->assertDatabaseMissing('agent_banks', ['agent_group_id' => 77023]);
        $this->assertCount(1, $entries);
        $this->assertSame(BankProjectionService::SKIP_NO_ACTIVE, $entries[0]['reason']);
    }

    public function test_excludes_base_rows_deleted_rows_and_unmapped_rows(): void
    {
        $this->makeBank(0, 'PROJBANK', $this->stdId);          // group=0 基底列不投
        $this->makeBank(77003, 'PROJBANK-X', null);            // 未映射不投（品質閘門）
        $deleted = $this->makeBank(77004, 'PROJBANK2', $this->stdId2);
        // Bank model 未掛 SoftDeletes trait，deleted_at 為一般欄位，直接寫入
        DB::table('banks')->where('id', $deleted->id)->update(['deleted_at' => now()]);

        $candidates = $this->service->agentBankCandidates();
        DB::transaction(fn (): int => $this->service->rebuildAgentBanks($candidates['rows']));

        $this->assertDatabaseMissing('agent_banks', ['agent_group_id' => 0]);
        $this->assertDatabaseMissing('agent_banks', ['agent_group_id' => 77003]);
        $this->assertDatabaseMissing('agent_banks', ['agent_group_id' => 77004]);
    }

    public function test_rebuild_is_idempotent_and_removes_stale_rows(): void
    {
        // 預塞一筆來源已不存在的 stale 投影列，全量重建後應消失
        AgentBank::query()->create([
            'agent_group_id' => 77005,
            'standard_bank_id' => $this->stdId,
            'source_bank_id' => 999999999,
            'status' => 1,
        ]);
        $this->makeBank(77006, 'PROJBANK', $this->stdId, status: 0);

        $candidates = $this->service->agentBankCandidates();
        $written = DB::transaction(fn (): int => $this->service->rebuildAgentBanks($candidates['rows']));

        $this->assertSame(count($candidates['rows']), $written);
        $this->assertDatabaseMissing('agent_banks', ['agent_group_id' => 77005]);
        // status=0 下架列仍存活、照投（代理層狀態隨列帶過去）
        $this->assertDatabaseHas('agent_banks', ['agent_group_id' => 77006, 'status' => 0]);
    }

    public function test_gap_report_counts_unmapped_alive_banks(): void
    {
        $this->makeBank(77007, 'PROJBANK-Y', null);
        $this->makeBank(0, 'PROJBANK-Z', null);

        $report = $this->service->gapReport();

        $this->assertGreaterThanOrEqual(1, $report['unmapped_alive_group_banks']);
        $this->assertGreaterThanOrEqual(1, $report['unmapped_alive_base_banks']);
    }

    public function test_syncs_cfb_standard_bank_id_with_drift_fix_and_clear(): void
    {
        $bank = $this->makeBank(0, 'PROJBANK', $this->stdId);
        $bankUnmapped = $this->makeBank(0, 'PROJBANK-N', null);

        // cash_flow_banks 直插（無 factory；cash_flow_id 用不存在的大值即可，無 FK 約束）
        $cfbFill = DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => 999901, 'currency_id' => $this->currencyId,
            'bank_id' => $bank->id, 'bank_code' => 'BC1', 'provider_bank_code' => 'P1', 'status' => 1,
        ]);
        $cfbDrift = DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => 999902, 'currency_id' => $this->currencyId,
            'bank_id' => $bank->id, 'bank_code' => 'BC2', 'provider_bank_code' => 'P2', 'status' => 1,
            'standard_bank_id' => $this->stdId2, // 錯值，應被修正
        ]);
        $cfbClear = DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => 999903, 'currency_id' => $this->currencyId,
            'bank_id' => $bankUnmapped->id, 'bank_code' => 'BC3', 'provider_bank_code' => 'P3', 'status' => 1,
            'standard_bank_id' => $this->stdId, // 來源未映射，應被清空
        ]);
        // 註：repo migration 的 cfb.bank_id 為 NOT NULL，「bank_id 缺失」屬生產 schema drift 現象
        // （測試庫造不出該列）；此情境由 inner join 天然跳過，無需資料層測資。

        $previewCount = $this->service->cfbLinkDriftQuery()->count();
        $changed = DB::transaction(fn (): int => $this->service->syncCfbStandardBankIds());

        $this->assertSame($previewCount, $changed); // D-5：preview＝實跑
        $this->assertSame(3, $changed);
        $this->assertSame($this->stdId, (int) DB::table('cash_flow_banks')->where('id', $cfbFill)->value('standard_bank_id'));
        $this->assertSame($this->stdId, (int) DB::table('cash_flow_banks')->where('id', $cfbDrift)->value('standard_bank_id'));
        $this->assertNull(DB::table('cash_flow_banks')->where('id', $cfbClear)->value('standard_bank_id'));
    }
}
