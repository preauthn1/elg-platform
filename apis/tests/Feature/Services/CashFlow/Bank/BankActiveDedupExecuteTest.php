<?php

declare(strict_types=1);

namespace Tests\Feature\Services\CashFlow\Bank;

use App\Models\WithdrawalOrder;
use App\Services\CashFlow\Bank\BankActiveDedupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DetachesBanksActiveGuard;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Services/CashFlow/Bank/BankActiveDedupExecuteTest.php
 */
class BankActiveDedupExecuteTest extends TestCase
{
    use DetachesBanksActiveGuard;

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeBank(array $attrs = []): int
    {
        return (int) DB::table('banks')->insertGetId(array_merge([
            'agent_group_id' => 674,
            'type' => 1,
            'name' => '(MYR) - AGRO Bank',
            'code' => 'MYR0023',
            'currency_id' => 1,
            'status' => 1,
            'created_at' => Carbon::parse('2026-01-01 00:00:00'),
            'updated_at' => Carbon::parse('2026-01-01 00:00:00'),
        ], $attrs));
    }

    private function makeBinding(int $memberId, int $bankId, int $status = 1): int
    {
        return (int) DB::table('member_bank_accounts')->insertGetId([
            'member_id' => $memberId,
            'bank_id' => $bankId,
            'account_name' => 'Tester',
            'account_no' => '1234567890',
            'status' => $status,
            'sort' => 0,
            'operator_cs' => 0,
            'created_at' => Carbon::parse('2026-01-01 00:00:00'),
            'updated_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
    }

    private function makeRealnameRequest(int $memberId, int $bankId, int $status = 0): int
    {
        return (int) DB::table('realname_validation_requests')->insertGetId([
            'member_id' => $memberId,
            'realname' => 'Tester',
            'bank_id' => $bankId,
            'bank_name' => '(MYR) - AGRO Bank',
            'bank_account' => '1234567890',
            'status' => $status,
            'username' => 'tester01',
            'created_at' => Carbon::parse('2026-01-01 00:00:00'),
            'updated_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
    }

    public function test_situation2_member_only_on_loser_is_repointed_and_orders_synced(): void
    {
        // 合法重複：名稱僅大小寫差異（case-fold 相等），不觸發安全閘
        $loserId = $this->makeBank(['name' => '(MYR) - AGRO BANK', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['name' => '(MYR) - AGRO Bank', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $bindingId = $this->makeBinding(memberId: 5001, bankId: $loserId);
        $order = WithdrawalOrder::factory()->create([
            'member_id' => 5001,
            'bank_id' => $loserId,
            'bank_code' => 'OLDCODE',
            'bank_name' => 'OLD NAME',
        ]);

        app(BankActiveDedupService::class)->execute();

        // 會員綁定 re-point 到 survivor
        $this->assertSame($survivorId, (int) DB::table('member_bank_accounts')->where('id', $bindingId)->value('bank_id'));
        // 出款訂單同步為 survivor 的 id/code/name
        $order->refresh();
        $this->assertSame($survivorId, (int) $order->bank_id);
        $this->assertSame('MYR0023', $order->bank_code);
        $this->assertSame('(MYR) - AGRO Bank', $order->bank_name);
        // loser 停用、survivor 維持 active
        $this->assertSame(0, (int) DB::table('banks')->where('id', $loserId)->value('status'));
        $this->assertSame(1, (int) DB::table('banks')->where('id', $survivorId)->value('status'));
        // 審計：repoint + deactivate_bank
        $this->assertDatabaseHas('bank_dedup_logs', [
            'loser_bank_id' => $loserId,
            'survivor_bank_id' => $survivorId,
            'member_id' => 5001,
            'action' => 'repoint',
        ]);
        $this->assertDatabaseHas('bank_dedup_logs', [
            'loser_bank_id' => $loserId,
            'action' => 'deactivate_bank',
        ]);
    }

    public function test_situation1_member_on_both_keeps_survivor_and_deactivates_loser_binding(): void
    {
        // 合法重複：名稱僅大小寫差異（case-fold 相等），不觸發安全閘
        $loserId = $this->makeBank(['name' => '(MYR) - AGRO BANK', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['name' => '(MYR) - AGRO Bank', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $survivorBindingId = $this->makeBinding(memberId: 6001, bankId: $survivorId);
        $loserBindingId = $this->makeBinding(memberId: 6001, bankId: $loserId);
        // 情況1 會員的歷史訂單留在 loser id（不 re-point）
        $order = WithdrawalOrder::factory()->create(['member_id' => 6001, 'bank_id' => $loserId]);

        app(BankActiveDedupService::class)->execute();

        // loser 綁定停用、survivor 綁定不動
        $this->assertSame(0, (int) DB::table('member_bank_accounts')->where('id', $loserBindingId)->value('status'));
        $this->assertSame(1, (int) DB::table('member_bank_accounts')->where('id', $survivorBindingId)->value('status'));
        // 歷史訂單仍指 loser id（loser 已停用但 belongsTo 不過濾 status，仍解析得到）
        $order->refresh();
        $this->assertSame($loserId, (int) $order->bank_id);
        // loser 銀行停用
        $this->assertSame(0, (int) DB::table('banks')->where('id', $loserId)->value('status'));
        $this->assertDatabaseHas('bank_dedup_logs', [
            'loser_bank_id' => $loserId,
            'member_id' => 6001,
            'action' => 'deactivate_binding',
        ]);
    }

    /**
     * 「survivor 綁定已停用＋loser 綁定啟用」必須走情況2（re-point），不得走情況1。
     *
     * 兩案分流的子查詢若只比對 bank_id 而不限 status=1，這種會員會兩邊都不接：情況1 認定
     * 他已綁 survivor 而停掉那筆**唯一啟用**的，情況2 又因同一理由被 whereNotIn 排除。
     * 結果是零個有效綁定——前台提款銀行清單同時要求 member_bank_accounts.status=1 與
     * banks.status=1，會員因此無法提款，且命令 exit 0、審計看起來正常，完全靜默。
     */
    public function test_member_whose_survivor_binding_is_inactive_keeps_an_active_binding(): void
    {
        $loserId = $this->makeBank(['name' => '(MYR) - AGRO BANK', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['name' => '(MYR) - AGRO Bank', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $survivorBindingId = $this->makeBinding(memberId: 7001, bankId: $survivorId, status: 0);
        $loserBindingId = $this->makeBinding(memberId: 7001, bankId: $loserId);

        app(BankActiveDedupService::class)->execute();

        $this->assertSame(
            $survivorId,
            (int) DB::table('member_bank_accounts')->where('id', $loserBindingId)->value('bank_id'),
            'survivor 綁定已停用者應走 re-point，不該被當成「已綁 survivor」',
        );
        $this->assertSame(
            1,
            (int) DB::table('member_bank_accounts')->where('id', $loserBindingId)->value('status'),
            're-point 不得順手停用該綁定',
        );
        $this->assertSame(
            0,
            (int) DB::table('member_bank_accounts')->where('id', $survivorBindingId)->value('status'),
            '原本就停用的 survivor 綁定維持原狀',
        );

        // 真正要守住的不變式：收斂後該會員仍提得了款。
        $this->assertSame(
            1,
            DB::table('member_bank_accounts')->where('member_id', 7001)->where('status', 1)->count(),
            '收斂後會員必須仍有 active 綁定，否則無法提款',
        );
    }

    public function test_flags_code_reuse_with_bindings_instead_of_merging(): void
    {
        // 同 code 不同銀行（BSN vs Duitnow QR），loser 有會員綁定 → 應 flag 不合併
        $loserId = $this->makeBank(['name' => '(MYR) - BSN', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['name' => '(MYR) - Duitnow QR', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $bindingId = $this->makeBinding(memberId: 7001, bankId: $loserId);

        $summary = app(BankActiveDedupService::class)->execute();

        // 不合併：loser 仍 active、綁定仍指 loser
        $this->assertSame(1, (int) DB::table('banks')->where('id', $loserId)->value('status'));
        $this->assertSame($loserId, (int) DB::table('member_bank_accounts')->where('id', $bindingId)->value('bank_id'));
        // 標記人工複核
        $this->assertSame(1, $summary['flagged']);
        $this->assertSame(0, $summary['deactivated_banks']);
        $this->assertDatabaseHas('bank_dedup_logs', [
            'loser_bank_id' => $loserId,
            'action' => 'flagged_manual_review',
        ]);
    }

    public function test_name_mismatch_without_bindings_still_merges(): void
    {
        // 名稱不符但 loser 無綁定（如 template 群）→ 合併無害，照常停用
        $loserId = $this->makeBank(['name' => '(MYR) - UOB Bank', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['name' => '(MYR) - United Overseas Bank', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $summary = app(BankActiveDedupService::class)->execute();

        $this->assertSame(0, (int) DB::table('banks')->where('id', $loserId)->value('status'));
        $this->assertSame(0, $summary['flagged']);
        $this->assertSame(1, $summary['deactivated_banks']);
    }

    public function test_case_only_name_difference_with_bindings_still_merges(): void
    {
        // 僅大小寫差異（同一家銀行）+ 有綁定 → 照常合併，不 flag
        $loserId = $this->makeBank(['name' => '(MYR) - Bank Of China', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['name' => '(MYR) - Bank of China', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $bindingId = $this->makeBinding(memberId: 8001, bankId: $loserId);

        $summary = app(BankActiveDedupService::class)->execute();

        $this->assertSame(0, (int) DB::table('banks')->where('id', $loserId)->value('status'));
        $this->assertSame($survivorId, (int) DB::table('member_bank_accounts')->where('id', $bindingId)->value('bank_id'));
        $this->assertSame(0, $summary['flagged']);
    }

    public function test_shadow_cleanup_repoints_sole_binding_and_soft_deletes_shadow(): void
    {
        // TPower AGRO 形態：同名同碼 shadow(s0) + active(s1)，shadow 有 sole binding
        $shadowId = $this->makeBank(['status' => 0, 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['status' => 1, 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $bindingId = $this->makeBinding(memberId: 9101, bankId: $shadowId);
        $order = WithdrawalOrder::factory()->create([
            'member_id' => 9101,
            'bank_id' => $shadowId,
            'bank_code' => 'OLDCODE',
            'bank_name' => 'OLD NAME',
        ]);

        $summary = app(BankActiveDedupService::class)->executeShadowCleanup();

        // 綁定 re-point 至 active 正身、訂單同步
        $this->assertSame($survivorId, (int) DB::table('member_bank_accounts')->where('id', $bindingId)->value('bank_id'));
        $order->refresh();
        $this->assertSame($survivorId, (int) $order->bank_id);
        // shadow 標記刪除（Bank 無 SoftDeletes，deleted_at 為 dedup 清理標記），status 維持 0
        $this->assertNotNull(DB::table('banks')->where('id', $shadowId)->value('deleted_at'));
        $this->assertNull(DB::table('banks')->where('id', $survivorId)->value('deleted_at'));
        $this->assertSame(1, $summary['groups']);
        $this->assertSame(1, $summary['soft_deleted_banks']);
        $this->assertDatabaseHas('bank_dedup_logs', [
            'loser_bank_id' => $shadowId,
            'survivor_bank_id' => $survivorId,
            'action' => 'soft_delete_bank',
        ]);
    }

    public function test_shadow_cleanup_deactivates_binding_when_member_on_both(): void
    {
        $shadowId = $this->makeBank(['status' => 0, 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['status' => 1, 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $survivorBindingId = $this->makeBinding(memberId: 9102, bankId: $survivorId);
        $shadowBindingId = $this->makeBinding(memberId: 9102, bankId: $shadowId);

        app(BankActiveDedupService::class)->executeShadowCleanup();

        // 雙綁會員：shadow 綁定停用、survivor 綁定不動
        $this->assertSame(0, (int) DB::table('member_bank_accounts')->where('id', $shadowBindingId)->value('status'));
        $this->assertSame(1, (int) DB::table('member_bank_accounts')->where('id', $survivorBindingId)->value('status'));
        $this->assertNotNull(DB::table('banks')->where('id', $shadowId)->value('deleted_at'));
    }

    public function test_pending_realname_request_repointed_and_settled_left_untouched(): void
    {
        $loserId = $this->makeBank(['name' => '(MYR) - AGRO BANK', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['name' => '(MYR) - AGRO Bank', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $pendingId = $this->makeRealnameRequest(memberId: 9201, bankId: $loserId, status: 0);
        $settledId = $this->makeRealnameRequest(memberId: 9202, bankId: $loserId, status: 1);

        $summary = app(BankActiveDedupService::class)->execute();

        // 待審核件 re-point 至 survivor；已審結件為歷史快照維持原值
        $this->assertSame($survivorId, (int) DB::table('realname_validation_requests')->where('id', $pendingId)->value('bank_id'));
        $this->assertSame($loserId, (int) DB::table('realname_validation_requests')->where('id', $settledId)->value('bank_id'));
        $this->assertSame(1, $summary['repointed_realname_requests']);
        $this->assertDatabaseHas('bank_dedup_logs', [
            'loser_bank_id' => $loserId,
            'survivor_bank_id' => $survivorId,
            'member_id' => 9201,
            'action' => 'repoint_realname_request',
            'detail' => 'request_id=' . $pendingId,
        ]);
    }

    public function test_shadow_cleanup_repoints_pending_realname_request(): void
    {
        $shadowId = $this->makeBank(['status' => 0, 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['status' => 1, 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $pendingId = $this->makeRealnameRequest(memberId: 9203, bankId: $shadowId, status: 0);

        $summary = app(BankActiveDedupService::class)->executeShadowCleanup();

        $this->assertSame($survivorId, (int) DB::table('realname_validation_requests')->where('id', $pendingId)->value('bank_id'));
        $this->assertSame(1, $summary['repointed_realname_requests']);
        $this->assertDatabaseHas('bank_dedup_logs', [
            'loser_bank_id' => $shadowId,
            'action' => 'repoint_realname_request',
        ]);
    }

    public function test_flagged_code_reuse_leaves_pending_realname_request_untouched(): void
    {
        // 名稱實質不同＋loser 有綁定 → 安全閘 flagged，實名件不得被 re-point 到別家銀行
        $loserId = $this->makeBank(['name' => '(MYR) - Public Bank', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['name' => '(MYR) - AGRO Bank', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $this->makeBinding(memberId: 9204, bankId: $loserId);
        $pendingId = $this->makeRealnameRequest(memberId: 9204, bankId: $loserId, status: 0);

        $summary = app(BankActiveDedupService::class)->execute();

        $this->assertSame(1, $summary['flagged']);
        $this->assertSame(0, $summary['repointed_realname_requests']);
        $this->assertSame($loserId, (int) DB::table('realname_validation_requests')->where('id', $pendingId)->value('bank_id'));
        $this->assertSame($survivorId, (int) DB::table('banks')->where('id', $survivorId)->value('id'));
    }
}
