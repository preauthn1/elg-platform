<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Models\Bank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ActivateBankRowsCommand 測試（把停用的 banks 列重新啟用）
 *
 * 守備重點有三：
 * 1. 撞鍵預檢。active 唯一約束的判準是四元組 agent_group_id + type + currency_id + code，
 *    以 generated column 實作、只有 status=1 的列才有值。啟用一列等於憑空生出一個鍵，
 *    同鍵已有 active 列時必須擋下——約束尚未全站上線，擋不擋只能靠命令自己。
 * 2. 已標刪的列不給啟用。AgentBankSyncService、AvailableBanksAlignService、
 *    BackfillStandardBanksCommand 都帶 whereNull('deleted_at')，啟用它只會做出一列
 *    「看得到但多條同步路徑視為不存在」的矛盾資料。
 * 3. 只寫 status，不碰 deleted_at。啟用是停用的反向，但兩者不對稱：停用要並寫
 *    deleted_at 才不會被 sync 打回，啟用則因為標刪列一律阻斷，deleted_at 必然已是 NULL。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/Bank/ActivateBankRowsCommandTest.php
 */
class ActivateBankRowsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const AGENT_GROUP = 99880;

    private const BASE_GROUP = 0;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = (int) DB::table('currencies')->insertGetId([
            'code' => 'TS9', 'name' => 'Test Currency 9', 'rate' => 1, 'admin' => 0,
        ]);
    }

    private function seedBank(
        string $code,
        int $status = 0,
        int $agentGroupId = self::AGENT_GROUP,
        int $type = 4,
        float $minAmount = 50,
        float $maxAmount = 10000,
    ): Bank {
        return Bank::create([
            'agent_group_id' => $agentGroupId,
            'type' => $type,
            'currency_id' => $this->currencyId,
            'name' => '(TS9) - Dormant Bank ' . $code,
            'code' => $code,
            'status' => $status,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => $minAmount, 'max_amount' => $maxAmount, 'sort' => 0, 'admin' => 0,
        ]);
    }

    private function softDelete(Bank $bank): void
    {
        DB::table('banks')->where('id', $bank->id)->update(['deleted_at' => now()]);
    }

    private function bankRow(int $id): object
    {
        return DB::table('banks')->where('id', $id)->first();
    }

    /** @return list<object> */
    private function auditRows(int $bankId): array
    {
        return DB::table('bank_dedup_logs')
            ->where('action', 'activate_row')
            ->where('loser_bank_id', $bankId)
            ->orderBy('id')
            ->get()
            ->all();
    }

    // =====================================================================
    // 正常路徑
    // =====================================================================

    public function test_activates_dormant_row_writing_status_only(): void
    {
        $bank = $this->seedBank('TSACT01');

        $this->artisan('bank:activate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '銀行收斂目標列，需先啟用才能作為 survivor',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $fresh = $this->bankRow($bank->id);
        $this->assertSame(1, (int) $fresh->status);
        $this->assertNull($fresh->deleted_at, '啟用不得動 deleted_at——標刪列在預檢就該被擋下');
    }

    public function test_writes_audit_with_before_and_after_state(): void
    {
        $bank = $this->seedBank('TSACT02');

        $this->artisan('bank:activate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '啟用理由要能回推',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $audits = $this->auditRows($bank->id);
        $this->assertCount(1, $audits);

        $audit = $audits[0];
        $this->assertSame((int) $bank->agent_group_id, (int) $audit->agent_group_id);
        $this->assertSame('TSACT02', $audit->code);
        $this->assertSame($bank->id, (int) $audit->survivor_bank_id, '純啟用沒有 survivor，自我參照表示本列即標的');

        $detail = json_decode((string) $audit->detail, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('banks', $detail['t']);
        $this->assertSame($bank->id, $detail['id']);
        $this->assertSame(0, $detail['was']['status']);
        $this->assertSame(1, $detail['aft']['status']);
        $this->assertSame('啟用理由要能回推', $detail['reason']);
    }

    public function test_dry_run_reports_plan_without_writing(): void
    {
        $bank = $this->seedBank('TSACT03');

        $this->artisan('bank:activate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '只看報表',
        ])->assertExitCode(0);

        $this->assertSame(0, (int) $this->bankRow($bank->id)->status, '未帶 --execute 不得寫入');
        $this->assertCount(0, $this->auditRows($bank->id));
    }

    public function test_skips_row_that_is_already_active(): void
    {
        $bank = $this->seedBank('TSACT04', status: 1);

        $this->artisan('bank:activate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '重複執行應該安全',
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('已是啟用中')
            ->assertExitCode(0);

        $this->assertCount(0, $this->auditRows($bank->id), '跳過的列不得寫審計');
    }

    // =====================================================================
    // 撞鍵預檢
    // =====================================================================

    public function test_blocks_when_same_key_already_has_an_active_row(): void
    {
        $occupant = $this->seedBank('TSACT05', status: 1);
        $dormant = $this->seedBank('TSACT05', status: 0);

        $this->artisan('bank:activate-rows', [
            '--bank' => [$dormant->id],
            '--reason' => '這會做出兩列同鍵 active',
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain((string) $occupant->id)
            ->assertExitCode(1);

        $this->assertSame(0, (int) $this->bankRow($dormant->id)->status);
    }

    public function test_ignores_inactive_rows_when_checking_for_key_conflicts(): void
    {
        $this->seedBank('TSACT06', status: 0);
        $target = $this->seedBank('TSACT06', status: 0);

        $this->artisan('bank:activate-rows', [
            '--bank' => [$target->id],
            '--reason' => '停用列不佔鍵位，唯一索引只認 status=1',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, (int) $this->bankRow($target->id)->status);
    }

    public function test_allows_activation_when_conflicting_row_has_a_different_type(): void
    {
        $this->seedBank('TSACT07', status: 1, type: 3);
        $target = $this->seedBank('TSACT07', status: 0, type: 4);

        $this->artisan('bank:activate-rows', [
            '--bank' => [$target->id],
            '--reason' => '四元組含 type，不同 type 不算同鍵',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, (int) $this->bankRow($target->id)->status);
    }

    public function test_blocks_when_two_rows_in_the_same_batch_share_one_key(): void
    {
        $first = $this->seedBank('TSACT08', status: 0);
        $second = $this->seedBank('TSACT08', status: 0);

        $this->artisan('bank:activate-rows', [
            '--bank' => [$first->id, $second->id],
            '--reason' => '同批兩列同鍵，逐列查庫都看不出衝突',
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('同一批次')
            ->assertExitCode(1);

        $this->assertSame(0, (int) $this->bankRow($first->id)->status, '整批零寫入');
        $this->assertSame(0, (int) $this->bankRow($second->id)->status);
    }

    // =====================================================================
    // 標刪列與其他阻斷
    // =====================================================================

    public function test_blocks_soft_deleted_row(): void
    {
        $bank = $this->seedBank('TSACT09');
        $this->softDelete($bank);

        $this->artisan('bank:activate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '標刪列不該被復活',
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('已標記 deleted_at')
            ->assertExitCode(1);

        $this->assertSame(0, (int) $this->bankRow($bank->id)->status);
    }

    public function test_blocks_missing_id(): void
    {
        $this->artisan('bank:activate-rows', [
            '--bank' => [99999999],
            '--reason' => '不存在的 id',
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('不存在')
            ->assertExitCode(1);
    }

    public function test_one_bad_row_aborts_the_whole_batch(): void
    {
        $clean = $this->seedBank('TSACT10');
        $deleted = $this->seedBank('TSACT11');
        $this->softDelete($deleted);

        $this->artisan('bank:activate-rows', [
            '--bank' => [$clean->id, $deleted->id],
            '--reason' => '一列不合格就整批停手',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, (int) $this->bankRow($clean->id)->status, '合格的那列也不得寫入');
        $this->assertCount(0, $this->auditRows($clean->id));
    }

    // =====================================================================
    // 限額警告（不阻斷）
    // =====================================================================

    public function test_warns_when_agent_group_row_has_zero_amount_limits(): void
    {
        $bank = $this->seedBank('TSACT12', minAmount: 0, maxAmount: 0);

        $this->artisan('bank:activate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '限額為 0 要提醒，但不擋',
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('bank:set-amount-limits')
            ->assertExitCode(0);

        $this->assertSame(1, (int) $this->bankRow($bank->id)->status, '警告不得阻斷寫入');
    }

    public function test_does_not_warn_for_base_layer_zero_limits(): void
    {
        $bank = $this->seedBank('TSACT13', agentGroupId: self::BASE_GROUP, minAmount: 0, maxAmount: 0);

        $this->artisan('bank:activate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '平台共用層的 0 是「不限制」的正常語意',
            '--execute' => true,
            '--force' => true,
        ])
            ->doesntExpectOutputToContain('bank:set-amount-limits')
            ->assertExitCode(0);
    }

    // =====================================================================
    // 參數防呆
    // =====================================================================

    public function test_requires_bank_option(): void
    {
        $this->artisan('bank:activate-rows', ['--reason' => '沒指定 id'])
            ->expectsOutputToContain('--bank 必填')
            ->assertExitCode(1);
    }

    public function test_requires_reason(): void
    {
        $bank = $this->seedBank('TSACT14');

        $this->artisan('bank:activate-rows', ['--bank' => [$bank->id]])
            ->expectsOutputToContain('--reason 必填')
            ->assertExitCode(1);
    }

    public function test_rejects_comma_separated_bank_ids(): void
    {
        $this->artisan('bank:activate-rows', [
            '--bank' => ['7,9'],
            '--reason' => '逗號分隔不支援',
        ])
            ->expectsOutputToContain('純數字')
            ->assertExitCode(1);
    }

    public function test_rejects_invalid_utf8_reason(): void
    {
        $bank = $this->seedBank('TSACT15');

        $this->artisan('bank:activate-rows', [
            '--bank' => [$bank->id],
            '--reason' => "\xB1\x31 壞位元組",
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('UTF-8')
            ->assertExitCode(1);

        $this->assertSame(0, (int) $this->bankRow($bank->id)->status);
    }

    public function test_rejects_overlong_reason(): void
    {
        $bank = $this->seedBank('TSACT16');

        $this->artisan('bank:activate-rows', [
            '--bank' => [$bank->id],
            '--reason' => str_repeat('長', 2001),
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('超過')
            ->assertExitCode(1);
    }
}
