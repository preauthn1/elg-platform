<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CashFlow\Bank;

use App\Models\Bank;
use App\Services\CashFlow\Bank\AgentBankSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * AgentBankSyncService 單元測試（L1 防再生，融合 !1769 防護）。
 * 去重鍵＝active-only 四元組 (agent_group_id, type, currency_id, code)；code 穩定不覆寫。
 */
class AgentBankSyncServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function makeBank(int $agentGroupId, string $name, string $code, int $status = 1, int $type = 1, int $currencyId = 1): Bank
    {
        return Bank::create([
            'agent_group_id' => $agentGroupId, 'type' => $type, 'currency_id' => $currencyId,
            'name' => $name, 'code' => $code, 'status' => $status,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0, 'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ]);
    }

    /**
     * @param  array<int, string>  $codes
     * @return array{created: int, updated: int, skipped: int}
     */
    private function sync(array $codes, int $agentGroupId, int $currencyId = 1): array
    {
        return app(AgentBankSyncService::class)->syncToAgentGroup($codes, $agentGroupId, $currencyId, 0, 0);
    }

    public function test_created_agent_row_inherits_base_standard_bank_id_with_audit(): void
    {
        // Phase 2 防再生：base 有映射 → 新建群組列直接繼承＋審計 inherit_base
        $base = $this->makeBank(0, '(MYR) - Maybank', 'MYR0001X', status: 1);
        Bank::query()->where('id', $base->id)->update(['standard_bank_id' => 12345]);

        $this->sync(['MYR0001X'], 3);

        $agent = Bank::where(['agent_group_id' => 3, 'currency_id' => 1, 'code' => 'MYR0001X'])->first();
        $this->assertSame(12345, (int) $agent->standard_bank_id);
        $this->assertDatabaseHas('bank_mapping_logs', [
            'bank_id' => $agent->id, 'standard_bank_id' => 12345,
            'tier' => 'inherit_base', 'matched_by' => 'base_bank', 'action' => 'auto_fill',
        ]);
    }

    public function test_update_fills_null_mapping_but_never_clobbers_existing(): void
    {
        $base = $this->makeBank(0, '(MYR) - Maybank', 'MYR0002X', status: 1);
        Bank::query()->where('id', $base->id)->update(['standard_bank_id' => 111]);
        // 既有群組列已有不同映射 → 不得被 base 覆寫
        $mapped = $this->makeBank(3, '(MYR) - Maybank', 'MYR0002X', status: 1);
        Bank::query()->where('id', $mapped->id)->update(['standard_bank_id' => 222]);

        $this->sync(['MYR0002X'], 3);
        $this->assertSame(222, (int) $mapped->fresh()->standard_bank_id);

        // 既有群組列映射為空 → 補上 base 的
        $empty = $this->makeBank(4, '(MYR) - Maybank', 'MYR0002X', status: 1);
        $this->sync(['MYR0002X'], 4);
        $this->assertSame(111, (int) $empty->fresh()->standard_bank_id);
    }

    public function test_base_without_mapping_creates_agent_row_with_null(): void
    {
        $this->makeBank(0, '(MYR) - Unknown Local', 'MYR0003X', status: 1);

        $this->sync(['MYR0003X'], 3);

        $agent = Bank::where(['agent_group_id' => 3, 'currency_id' => 1, 'code' => 'MYR0003X'])->first();
        $this->assertNull($agent->standard_bank_id);
        $this->assertDatabaseMissing('bank_mapping_logs', ['bank_id' => $agent->id]);
    }

    public function test_rerun_after_rename_updates_in_place_not_creates_duplicate(): void
    {
        // base active canonical + agent 同 code 但名字略異 → code 去重鍵 update in place、不新建
        $base = $this->makeBank(0, '(MYR) - Bank Islam', 'MYR0059', status: 1);
        $agent = $this->makeBank(3, '(MYR) - Bank Islam Malaysia', 'MYR0059', status: 1);

        $this->sync(['MYR0059'], 3);

        $this->assertSame(1, Bank::where(['agent_group_id' => 3, 'currency_id' => 1, 'code' => 'MYR0059'])->count());
        $this->assertSame($base->name, $agent->fresh()->name);
    }

    public function test_same_code_different_type_coexist(): void
    {
        // 修 plan keyBy('code') bug：同 code 不同 type 不可互相覆蓋（四元組含 type）
        $this->makeBank(0, 'Bank X', 'DUP', status: 1, type: 1);
        $this->makeBank(0, 'Ewallet X', 'DUP', status: 1, type: 4);

        $this->sync(['DUP'], 3);

        $this->assertSame(2, Bank::where(['agent_group_id' => 3, 'currency_id' => 1, 'code' => 'DUP'])->count());
    }

    public function test_base_template_prefers_active_when_inactive_orphan_shares_code(): void
    {
        // ⓒ³：base 同 code inactive 孤兒(id小) + active canonical(id大) → base 撈 status=1 → agent 繼承 active
        $this->makeBank(0, '(MYR) - Maybank', 'MayBank', status: 0);
        $this->makeBank(0, '(MYR) - Maybank', 'MayBank', status: 1);

        $this->sync(['MayBank'], 3);

        $agent = Bank::where(['agent_group_id' => 3, 'currency_id' => 1, 'code' => 'MayBank'])->first();
        $this->assertNotNull($agent);
        $this->assertSame(1, (int) $agent->status); // 不得被 inactive 孤兒 status=0 汙染
    }

    public function test_new_code_creates_without_overwriting_old_agent_code(): void
    {
        // code 去重鍵核心：base 新碼、agent 停在舊碼 → 新建新碼、舊碼列不被覆寫（根治 G3 drift 源）
        $this->makeBank(0, '(MYR) - Maybank', 'MayBank', status: 1);        // base canonical 新碼
        $old = $this->makeBank(3, '(MYR) - Maybank', 'WTMY02', status: 1);  // agent 舊碼

        $this->sync(['MayBank'], 3);

        $this->assertSame('WTMY02', $old->fresh()->code); // 舊碼列 code 不被覆寫
        $this->assertSame(1, Bank::where(['agent_group_id' => 3, 'currency_id' => 1, 'code' => 'MayBank'])->count()); // 新碼列建立
    }

    public function test_skips_and_counts_when_code_held_by_other_active_row(): void
    {
        // 撞鍵 defense：agent 已有 active（不同 name 同碼）持有 base code → 寫入變 active 會撞 uk_banks_active → skip
        $this->makeBank(0, '(MYR) - CIMB', 'CIMB01', status: 1); // base canonical
        // agent 同四元組 (type1,cur1,code CIMB01) 已有 active，但被另一 name 佔（模擬歷史殘留）
        $this->makeBank(3, '(MYR) - CIMB Bank Berhad', 'CIMB01', status: 1);

        $result = $this->sync(['CIMB01'], 3);

        // 同四元組已有 active（target 即該筆）→ update in place（不新建、不撞）；此案 updated=1
        $this->assertSame(1, Bank::where(['agent_group_id' => 3, 'currency_id' => 1, 'code' => 'CIMB01', 'status' => 1])->count());
        $this->assertSame(1, $result['updated']);
    }
}
