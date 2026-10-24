<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CashFlow\Bank;

use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Services\CashFlow\Bank\BankRepairService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DetachesBanksActiveGuard;
use Tests\Fixtures\Payment\PayoutBankCodeHarness;
use Tests\TestCase;

/**
 * 測試接縫：模擬「預檢當下沒看到撞鍵」，讓改指真的撞唯一鍵。
 *
 * 單執行緒測試無法製造真正的並發，但第二層防線（真撞鍵 → 只回滾該筆 → 同組其他列照常提交）
 * 是這支命令最重要的安全性質，必須有回歸守衛。
 */
final class BlindPrecheckRepairService extends BankRepairService
{
    protected function lockAndDetectConflicts(int $dupBankId, Bank $primary): array
    {
        return [];
    }
}

/** 測試接縫：拋出「來源不是 cash_flow_banks」的唯一鍵例外，驗證它不被吞成「並發」。 */
final class ForeignUniqueViolationRepairService extends BankRepairService
{
    protected function lockAndDetectConflicts(int $dupBankId, Bank $primary): array
    {
        throw new UniqueConstraintViolationException(
            'testing',
            'insert into `agent_banks` (`agent_group_id`, `standard_bank_id`) values (?, ?)',
            [0, 1],
            new \PDOException('Duplicate entry'),
        );
    }
}

/**
 * php artisan test tests/Unit/Services/CashFlow/Bank/BankRepairServiceTest.php
 */
class BankRepairServiceTest extends TestCase
{
    use DetachesBanksActiveGuard;

    private BankRepairService $repair;

    private int $currencyId = 1;

    /** 測試專用的 cash_flow_id，避開真實渠道 */
    private const TEST_CASH_FLOW_ID = 999001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repair = new BankRepairService;

        // 唯一鍵是 (cash_flow_id, currency_id, bank_id)，殘留列會讓重跑撞鍵
        DB::table('cash_flow_banks')->where('cash_flow_id', self::TEST_CASH_FLOW_ID)->delete();
        DB::table('bank_dedup_logs')->where('action', 'like', 'mismatch_%')->delete();
        DB::table('agent_cash_flows')->where('cash_flow_id', self::TEST_CASH_FLOW_ID)->delete();
        DB::table('cash_flows')->where('id', self::TEST_CASH_FLOW_ID)->delete();
    }

    /** 代付渠道（type=3）；updateAgentCashFlowAvailableBanks 只掃 type=3 的 ACF */
    private function makePayoutCashFlow(): void
    {
        DB::table('cash_flows')->insert([
            'id' => self::TEST_CASH_FLOW_ID,
            'name' => 'TEST payout',
            'type' => 3,
            'status' => 1,
            'admin' => 0,
        ]);
    }

    /** @param list<array{BankCode_ELG: string, BankCode_Third: string}> $availableBanks */
    private function makeAcf(array $availableBanks): AgentCashFlow
    {
        return AgentCashFlow::create([
            'cash_flow_id' => self::TEST_CASH_FLOW_ID,
            'currency_id' => $this->currencyId,
            // info 是 array cast，這裡不能再 json_encode（會雙重編碼、讀回來是字串）
            'info' => ['available_banks' => $availableBanks],
            'admin' => 0,
        ]);
    }

    /** @return list<string> */
    private function elgCodes(AgentCashFlow $acf): array
    {
        return array_column($acf->fresh()->info['available_banks'] ?? [], 'BankCode_ELG');
    }

    private function auditDetail(int $loserBankId, string $action): string
    {
        return (string) DB::table('bank_dedup_logs')
            ->where('loser_bank_id', $loserBankId)
            ->where('action', $action)
            ->value('detail');
    }

    private function makeCashFlowBank(int $bankId, string $bankCode, int $status = 1, ?string $providerBankCode = null): int
    {
        return (int) DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => self::TEST_CASH_FLOW_ID,
            'currency_id' => $this->currencyId,
            'bank_id' => $bankId,
            'bank_code' => $bankCode,
            'provider_bank_code' => $providerBankCode ?? $bankCode,
            'min_amount' => 0,
            'max_amount' => 999999,
            'status' => $status,
        ]);
    }

    private function makeBank(string $name, string $code, int $status = 1, int $type = 1): Bank
    {
        return Bank::create([
            'agent_group_id' => 0,
            'type' => $type,
            'name' => $name,
            'code' => $code,
            'currency_id' => $this->currencyId,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 999999,
            'sort' => 0,
            'status' => $status,
            'admin' => 0,
        ]);
    }

    /**
     * 改指與碼表同步都必須自我賦值 `updated_at`，否則帶 `on update CURRENT_TIMESTAMP`
     * 的表會被 MySQL 連帶蓋成執行時間——`withdrawal_orders.updated_at` 是前端訂單排序欄，
     * 2026-08-27 曾因此打亂生產訂單順序（六站 550 筆）。用 SQL 斷言而非時間戳斷言：
     * 測試庫的 schema 是否真帶 `on update` 不影響本守衛的有效性。
     */
    public function test_foreign_key_repoint_preserves_updated_at(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_TS_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_TS_DUP');

        $statements = $this->captureSql(fn () => $this->repair->applyDuplicateMerge($primary->id, [$dup->id]));

        $repoints = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'set `bank_id` = ?'),
        ));

        $this->assertNotEmpty($repoints, '應有改指 SQL（每個 FK 表各一條，即使 0 筆命中）');

        foreach ($repoints as $sql) {
            $this->assertStringContainsString(
                '`updated_at` = `updated_at`',
                $sql,
                '改指不可讓 on update CURRENT_TIMESTAMP 蓋掉 updated_at：' . $sql,
            );
        }
    }

    public function test_cash_flow_bank_code_sync_preserves_updated_at(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_TSC_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_TSC_DUP');
        $this->makeCashFlowBank($dup->id, 'TEST_TSC_DUP');

        $statements = $this->captureSql(fn () => $this->repair->applyDuplicateMerge($primary->id, [$dup->id]));

        $syncs = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'set `bank_code` = ?'),
        ));

        $this->assertNotEmpty($syncs, '應有碼表同步 SQL');

        foreach ($syncs as $sql) {
            $this->assertStringContainsString('`updated_at` = `updated_at`', $sql, $sql);
        }
    }

    /**
     * @param  callable(): mixed  $action
     * @return list<string>
     */
    private function captureSql(callable $action): array
    {
        $statements = [];

        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $action();

        return $statements;
    }

    public function test_apply_mismatch_rename_updates_bank_name(): void
    {
        $bank = $this->makeBank('Old Name', 'TEST_RENAME_1');

        $this->repair->applyMismatchRename($bank->id, 'New Name');

        $this->assertSame('New Name', $bank->fresh()->name);
    }

    public function test_apply_duplicate_merge_sets_status_zero_on_duplicates(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_DUP_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_DUP_DUP');

        $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $this->assertSame(1, $primary->fresh()->status, 'Primary stays active');
        $this->assertSame(0, $dup->fresh()->status, 'Duplicate marked inactive');
    }

    public function test_apply_duplicate_merge_handles_empty_duplicates_array(): void
    {
        $primary = $this->makeBank('Primary Only', 'TEST_DUP_EMPTY');

        // Should not throw, no-op
        $this->repair->applyDuplicateMerge($primary->id, []);

        $this->assertSame(1, $primary->fresh()->status);
    }

    public function test_cash_flow_bank_collision_leaves_duplicate_untouched(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_CFB_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_CFB_DUP');

        // 同一個 (cash_flow_id, currency_id) 兩邊都有列 ⇒ 改指會撞唯一鍵
        $primaryCfb = $this->makeCashFlowBank($primary->id, 'PRI_CODE');
        $dupCfb = $this->makeCashFlowBank($dup->id, 'AGENT_LAYER_CODE');

        $result = $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $this->assertSame([], $result['merged'], '撞鍵的 duplicate 不該被合併');
        $this->assertCount(1, $result['skipped']);
        $this->assertSame($dup->id, $result['skipped'][0]['bank_id']);
        $this->assertStringContainsString('撞唯一鍵', $result['skipped'][0]['reason']);

        // 留置＝什麼都沒動：status 仍啟用、cfb 仍指原本的 bank、agent 層碼沒被改成 base 碼
        $this->assertSame(1, $dup->fresh()->status, '留置的 duplicate 不該被停用');
        $this->assertSame($dup->id, (int) DB::table('cash_flow_banks')->where('id', $dupCfb)->value('bank_id'));
        $this->assertSame(
            'AGENT_LAYER_CODE',
            (string) DB::table('cash_flow_banks')->where('id', $dupCfb)->value('bank_code'),
            'agent 層碼不該被改成 base 碼'
        );
        $this->assertSame($primary->id, (int) DB::table('cash_flow_banks')->where('id', $primaryCfb)->value('bank_id'));
    }

    /**
     * gate 5（`--collision-keep=duplicate`，2026-08-27 RB 拍板）：兩側皆活且碼不同時 gate 4 會擋，
     * 人工挑碼指名保 duplicate ⇒ 刪 primary 側 cfb、duplicate 側那列改指 primary bank 並同步碼。
     *
     * 這是 VWorld `709←731`／`721←728` 的形狀：存活的 banks 列要是 primary（碼與其他站對齊），
     * 但送三方的有效碼在 duplicate 側 ⇒ banks 與 cfb 的存活側刻意分離。
     */
    public function test_collision_keep_duplicate_prunes_primary_side_and_repoints(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_CK_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_CK_DUP');

        // bank_code 是各自的 target 碼、provider_bank_code 才是送三方的值——與生產實況一致，
        // 這樣存活列才會走「同步 bank_code」分支（碼相同時會被歸為 agent 層碼、刻意不動）
        $primaryCfb = $this->makeCashFlowBank($primary->id, 'TEST_CK_PRI', providerBankCode: 'INVALID_CODE');
        $dupCfb = $this->makeCashFlowBank($dup->id, 'TEST_CK_DUP', providerBankCode: 'VALID_CODE');

        $result = $this->repair->applyDuplicateMerge(
            $primary->id,
            [$dup->id],
            pruneInactiveCollisions: true,
            collisionKeep: BankRepairService::COLLISION_KEEP_DUPLICATE,
        );

        $this->assertSame([$dup->id], $result['merged']);
        $this->assertSame([$primaryCfb], $result['pruned_cfb'], 'primary 側那列才是要刪的');
        $this->assertNull(DB::table('cash_flow_banks')->find($primaryCfb));

        $survivor = DB::table('cash_flow_banks')->where('id', $dupCfb);
        $this->assertSame($primary->id, (int) $survivor->value('bank_id'), '存活的 cfb 列要改指 primary bank');
        $this->assertSame('TEST_CK_PRI', (string) $survivor->value('bank_code'), 'bank_code 要同步成 primary 的碼');
        $this->assertSame('VALID_CODE', (string) $survivor->value('provider_bank_code'), '送三方的有效碼必須原樣保留');
        $this->assertSame(0, (int) $dup->fresh()->status);

        $detail = (string) DB::table('bank_dedup_logs')
            ->where('action', 'mismatch_cfb_pruned')
            ->where('loser_bank_id', $dup->id)
            ->value('detail');
        $this->assertStringContainsString('"keep":"duplicate"', $detail, '人工放行必須留下可回查的標記');
        $this->assertStringContainsString('"side":"primary"', $detail);
    }

    /**
     * gate 5 唯一仍 fail-closed 的情形：指名保留的那側 status=0 ⇒ 整組零寫入留置。
     */
    public function test_collision_keep_blocks_when_kept_side_is_inactive(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_CKB_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_CKB_DUP');

        $primaryCfb = $this->makeCashFlowBank($primary->id, 'TEST_CKB_PRI', providerBankCode: 'ACTIVE_CODE');
        $dupCfb = $this->makeCashFlowBank($dup->id, 'TEST_CKB_DUP', status: 0, providerBankCode: 'DEAD_CODE');

        $result = $this->repair->applyDuplicateMerge(
            $primary->id,
            [$dup->id],
            pruneInactiveCollisions: true,
            collisionKeep: BankRepairService::COLLISION_KEEP_DUPLICATE,
        );

        $this->assertSame([], $result['merged']);
        $this->assertSame([], $result['pruned_cfb']);
        $this->assertStringContainsString('指定保留的那側 cfb status=0', $result['skipped'][0]['reason']);
        $this->assertNotNull(DB::table('cash_flow_banks')->find($primaryCfb));
        $this->assertNotNull(DB::table('cash_flow_banks')->find($dupCfb));
        $this->assertSame(1, (int) $dup->fresh()->status);
    }

    /**
     * 預演與實跑必須對同一撞點給出一致的 gate 5 判定（不帶旗標時維持留置）。
     */
    public function test_collision_keep_preview_agrees_with_apply(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_CKP_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_CKP_DUP');

        $primaryCfb = $this->makeCashFlowBank($primary->id, 'TEST_CKP_PRI', providerBankCode: 'INVALID_CODE');
        $this->makeCashFlowBank($dup->id, 'TEST_CKP_DUP', providerBankCode: 'VALID_CODE');

        $withoutFlag = $this->repair->previewDuplicateMerge($primary->id, [$dup->id], pruneInactiveCollisions: true);
        $this->assertSame('skip', $withoutFlag[0]['verdict'], '不帶旗標時 gate 4 仍須擋下');

        $withFlag = $this->repair->previewDuplicateMerge(
            $primary->id,
            [$dup->id],
            pruneInactiveCollisions: true,
            collisionKeep: BankRepairService::COLLISION_KEEP_DUPLICATE,
        );
        $this->assertSame('merge', $withFlag[0]['verdict']);
        $this->assertSame([$primaryCfb], $withFlag[0]['cfb_pruned']);

        $this->assertNotNull(DB::table('cash_flow_banks')->find($primaryCfb), '預演不得刪列');
    }

    public function test_non_colliding_duplicate_still_merges_when_sibling_collides(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_MIX_PRI');
        $colliding = $this->makeBank('Colliding', 'TEST_MIX_HIT');
        $clean = $this->makeBank('Clean', 'TEST_MIX_OK');

        $this->makeCashFlowBank($primary->id, 'PRI_CODE');
        $this->makeCashFlowBank($colliding->id, 'HIT_CODE');

        $result = $this->repair->applyDuplicateMerge($primary->id, [$colliding->id, $clean->id]);

        $this->assertSame([$clean->id], $result['merged'], '一筆撞鍵不該拖垮同組另一筆');
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(1, $colliding->fresh()->status);
        $this->assertSame(0, $clean->fresh()->status);
    }

    public function test_merge_writes_audit_rows_with_mismatch_prefix(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_AUDIT_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_AUDIT_DUP');

        $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $actions = DB::table('bank_dedup_logs')
            ->where('survivor_bank_id', $primary->id)
            ->where('loser_bank_id', $dup->id)
            ->pluck('action')
            ->all();

        $this->assertContains('mismatch_repoint', $actions);
        $this->assertContains('mismatch_deactivate_bank', $actions);

        // 前綴是為了與 bank:dedup-active 的無前綴 action 隔離（rollback 只消費無前綴者）
        foreach ($actions as $action) {
            $this->assertStringStartsWith('mismatch_', $action);
        }
    }

    public function test_rename_writes_audit_and_skips_when_name_unchanged(): void
    {
        $bank = $this->makeBank('Same Name', 'TEST_AUD_RENAME');

        $this->repair->applyMismatchRename($bank->id, 'Renamed');
        $this->assertSame(1, DB::table('bank_dedup_logs')->where('action', 'mismatch_rename')->count());

        // 名稱沒變就不該再留一筆審計
        $this->repair->applyMismatchRename($bank->id, 'Renamed');
        $this->assertSame(1, DB::table('bank_dedup_logs')->where('action', 'mismatch_rename')->count());
    }

    public function test_synced_cash_flow_bank_code_follows_the_repoint(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_SYNC_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_SYNC_DUP');

        // 原本同步：cfb.bank_code === dup.code（primary 側沒有列 ⇒ 不撞鍵）
        $cfb = $this->makeCashFlowBank($dup->id, 'TEST_SYNC_DUP');

        $result = $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $this->assertSame([$dup->id], $result['merged']);

        // 關鍵：新提款單會帶 primary.code，代付以 (cash_flow_id, currency_id, bank_code)
        // 查 provider_bank_code。bank_code 不跟著改就會查空而擋單。
        $row = DB::table('cash_flow_banks')->where('id', $cfb)->first();
        $this->assertSame($primary->id, (int) $row->bank_id);
        $this->assertSame('TEST_SYNC_PRI', (string) $row->bank_code, '原本同步的列必須跟著改指一起同步');

        // 查表鏈實測：用新單會帶的 code 查得到 provider_bank_code
        $lookup = DB::table('cash_flow_banks')
            ->where('cash_flow_id', self::TEST_CASH_FLOW_ID)
            ->where('currency_id', $this->currencyId)
            ->where('bank_code', $primary->code)
            ->where('status', 1)
            ->value('provider_bank_code');
        $this->assertNotNull($lookup, '同步後必須查得到，否則 TakaPay／VaderPay 會回 not configured');
    }

    public function test_drifted_cash_flow_bank_code_is_kept_untouched(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_KEEP_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_KEEP_DUP');

        // 原本就不同步：帶白標 agent 層碼，與 dup.code 不同
        $cfb = $this->makeCashFlowBank($dup->id, 'WTMY02');

        $result = $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $this->assertSame([$dup->id], $result['merged']);

        $row = DB::table('cash_flow_banks')->where('id', $cfb)->first();
        $this->assertSame($primary->id, (int) $row->bank_id, 'bank_id 仍要改指');
        $this->assertSame('WTMY02', (string) $row->bank_code, '既有 drift 的碼不可被改寫，否則改變它的查表結果');
    }

    public function test_type_mismatch_is_skipped_not_merged(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_TYPE_PRI', 1, 1);
        $dup = $this->makeBank('Crypto', 'TEST_TYPE_DUP', 1, 3);

        $result = $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $this->assertSame([], $result['merged']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('身分不一致', $result['skipped'][0]['reason']);
        $this->assertStringContainsString('type', $result['skipped'][0]['reason']);
        $this->assertSame(1, $dup->fresh()->status, '不同 type 不是重複，不該被停用');
    }

    public function test_type_mismatch_merges_with_coerce_flag_and_audits(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_COERCE_PRI', 1, 4);
        $dup = $this->makeBank('Duplicate', 'TEST_COERCE_DUP', 1, 1);

        $result = $this->repair->applyDuplicateMerge($primary->id, [$dup->id], coerceTypeToPrimary: true);

        $this->assertSame([$dup->id], $result['merged']);
        $this->assertSame(0, $dup->fresh()->status, '旗標放行後 dup 應被停用');
        $this->assertSame(4, (int) $primary->fresh()->type, 'primary 的 type 不動＝以 primary 為準');
        $this->assertSame(1, (int) $dup->fresh()->type, 'dup 停用留原 type（歷史）');

        $audit = DB::table('bank_dedup_logs')
            ->where('action', 'mismatch_type_coerced')
            ->where('loser_bank_id', $dup->id)
            ->first();
        $this->assertNotNull($audit, 'type 覆蓋必須獨立留審計');
        $detail = json_decode((string) $audit->detail, true);
        $this->assertSame(['dup_type' => 1, 'primary_type' => 4], $detail);
    }

    public function test_preview_reports_type_coerce_direction(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_COERCE_PV_P', 1, 4);
        $dup = $this->makeBank('Duplicate', 'TEST_COERCE_PV_D', 1, 1);

        $noFlag = $this->repair->previewDuplicateMerge($primary->id, [$dup->id]);
        $this->assertSame('skip', $noFlag[0]['verdict'], '不帶旗標維持留置');

        $withFlag = $this->repair->previewDuplicateMerge($primary->id, [$dup->id], coerceTypeToPrimary: true);
        $this->assertSame('merge', $withFlag[0]['verdict']);
        $this->assertSame('1→4', $withFlag[0]['type_coerced'], '預演必須標示覆蓋方向');
        $this->assertSame(1, $dup->fresh()->status, '預演不寫入');
    }

    public function test_same_code_duplicate_still_reports_existing_drift_rows(): void
    {
        // duplicate 是依 standard name 分組，兩列同碼不同名是合法輸入
        $primary = $this->makeBank('Primary', 'TEST_SAMECODE');
        $dup = $this->makeBank('Duplicate', 'TEST_SAMECODE');

        $cfb = $this->makeCashFlowBank($dup->id, 'WTMY02');

        $result = $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $this->assertSame([$dup->id], $result['merged']);
        $this->assertSame(
            'WTMY02',
            (string) DB::table('cash_flow_banks')->where('id', $cfb)->value('bank_code'),
            '同碼情形下 drift 列一樣不可被改寫'
        );

        // 早退會讓這筆 drift 靜默消失在審計外——改指後它與 primary 自己的 drift 混在一起，事後分不出
        $kept = DB::table('bank_dedup_logs')
            ->where('loser_bank_id', $dup->id)
            ->where('action', 'mismatch_code_kept')
            ->pluck('detail')
            ->all();

        $this->assertCount(1, $kept, '同碼也要逐列分類並留下 drift 清單');
        $this->assertStringContainsString((string) $cfb, (string) $kept[0]);
    }

    public function test_case_differing_bank_code_is_treated_as_drift(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_CASE_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_CASE_DUP');

        // 只差大小寫。SQL 的 utf8mb4_unicode_ci 會把它當成「原本同步」而改寫，
        // 但 bank_code 大小寫不同就是不同的碼 ⇒ 必須在 PHP 端逐列嚴格比對。
        $cfb = $this->makeCashFlowBank($dup->id, 'test_case_dup');

        $result = $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $this->assertSame([$dup->id], $result['merged']);
        $this->assertSame(
            'test_case_dup',
            (string) DB::table('cash_flow_banks')->where('id', $cfb)->value('bank_code'),
            '大小寫不同的碼屬既有 drift，不可被 collation 誤判成同步而改寫'
        );
    }

    public function test_soft_deleted_row_is_skipped_even_when_status_active(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_DEL_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_DEL_DUP');

        // VWorld 實測存在的矛盾狀態（banks 1453）：deleted_at 有值卻 status=1。
        // Bank model 沒有 SoftDeletes ⇒ 沒有全域 scope 會幫忙擋。
        Bank::query()->where('id', $dup->id)->update(['deleted_at' => '2026-07-13 00:00:00']);

        $result = $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $this->assertSame([], $result['merged']);
        $this->assertStringContainsString('已軟刪除', $result['skipped'][0]['reason']);
        $this->assertSame(1, $dup->fresh()->status, '軟刪除列不該被當成待併的重複列而改動');
    }

    public function test_standard_bank_id_mismatch_is_skipped(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_STD_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_STD_DUP');

        // 兩側都已回填且指向不同 standard ⇒ 名稱撞了，不是同一家銀行
        Bank::query()->where('id', $primary->id)->update(['standard_bank_id' => 8001]);
        Bank::query()->where('id', $dup->id)->update(['standard_bank_id' => 8002]);

        $result = $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $this->assertSame([], $result['merged']);
        $this->assertStringContainsString('standard_bank_id', $result['skipped'][0]['reason']);
    }

    public function test_real_unique_violation_rolls_back_only_that_duplicate(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_RACE_PRI');
        $colliding = $this->makeBank('Colliding', 'TEST_RACE_HIT');
        $clean = $this->makeBank('Clean', 'TEST_RACE_OK');

        $this->makeCashFlowBank($primary->id, 'PRI_CODE');
        // 這列原本同步 ⇒ 交易內會先被改成 primary.code，之後改指才撞鍵。
        // 回滾必須把 bank_code 也還原，否則就是「已寫入的部分留在庫裡」。
        $collidingCfb = $this->makeCashFlowBank($colliding->id, 'TEST_RACE_HIT');

        $repair = new BlindPrecheckRepairService;
        $result = $repair->applyDuplicateMerge($primary->id, [$colliding->id, $clean->id]);

        $this->assertSame([$clean->id], $result['merged'], '真撞鍵不該拖垮同組其他列');
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('並發寫入', $result['skipped'][0]['reason']);

        $row = DB::table('cash_flow_banks')->where('id', $collidingCfb)->first();
        $this->assertSame($colliding->id, (int) $row->bank_id, '改指必須回滾');
        $this->assertSame('TEST_RACE_HIT', (string) $row->bank_code, '交易內已改的 bank_code 必須一併回滾');
        $this->assertSame(1, $colliding->fresh()->status);
        $this->assertSame(0, $clean->fresh()->status, '同組後續的列必須照常提交');
    }

    public function test_unique_violation_from_other_table_is_not_swallowed(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_OTHER_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_OTHER_DUP');

        $repair = new ForeignUniqueViolationRepairService;

        $this->expectException(UniqueConstraintViolationException::class);

        $repair->applyDuplicateMerge($primary->id, [$dup->id]);
    }

    public function test_merged_row_is_reachable_through_real_payout_lookup(): void
    {
        config(['payment.payout_bank_lookup_providers' => ['SurePay']]);

        $primary = $this->makeBank('Primary', 'TEST_PAYOUT_P');
        $dup = $this->makeBank('Duplicate', 'TEST_PAYOUT_D');

        // 原本同步的列，provider_bank_code 由 makeCashFlowBank 填成 bank_code
        $this->makeCashFlowBank($dup->id, 'TEST_PAYOUT_D');

        $this->assertSame([$dup->id], $this->repair->applyDuplicateMerge($primary->id, [$dup->id])['merged']);

        $harness = new PayoutBankCodeHarness('SurePay');
        $harness->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => self::TEST_CASH_FLOW_ID,
            'currency_id' => $this->currencyId,
            'info' => json_encode([]),
            'admin' => 0,
        ]);

        // 新提款單帶的是改指後 bank 的 code（WithdrawalController 建單 bank_code => $bank->code），
        // 這裡走的是真正的 convertPayoutBankCode()，不是重寫的 SQL。
        $this->assertSame(
            'TEST_PAYOUT_D',
            $harness->call($primary->code),
            'merge 後以新單會帶的 code 必須查得到 provider_bank_code，否則 TakaPay／VaderPay 回 not configured'
        );
    }

    public function test_self_merge_is_rejected(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_SELF_PRI');

        $result = $this->repair->applyDuplicateMerge($primary->id, [$primary->id]);

        $this->assertSame([], $result['merged']);
        $this->assertStringContainsString('自我合併', $result['skipped'][0]['reason']);
        $this->assertSame(1, $primary->fresh()->status, '自我合併不可停用存活方');
    }

    public function test_acf_already_holding_primary_code_is_skipped_and_audited(): void
    {
        $this->makePayoutCashFlow();
        $primary = $this->makeBank('Primary', 'TEST_ACF_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_ACF_DUP');

        // 同一張 ACF 同時含兩碼。替換會產生兩個相同的 BankCode_ELG，而舊 payout JSON 路徑
        // 取第一筆命中、兩筆各自保留原本的 Third ⇒ 送三方的碼由陣列順序決定。
        $acf = $this->makeAcf([
            ['BankCode_ELG' => 'TEST_ACF_PRI', 'BankCode_Third' => 'THIRD_A'],
            ['BankCode_ELG' => 'TEST_ACF_DUP', 'BankCode_Third' => 'THIRD_B'],
        ]);

        $this->assertSame([$dup->id], $this->repair->applyDuplicateMerge($primary->id, [$dup->id])['merged']);

        $this->assertSame(
            ['TEST_ACF_PRI', 'TEST_ACF_DUP'],
            $this->elgCodes($acf),
            '已含 primary 碼的 ACF 不可被改寫，否則製造重複 entry'
        );
        $this->assertStringContainsString(
            (string) $acf->id,
            $this->auditDetail($dup->id, 'mismatch_acf_conflict'),
            '留置的 ACF id 必須落審計供人工合併'
        );
    }

    public function test_acf_holding_only_dup_code_is_rewritten_and_audited(): void
    {
        $this->makePayoutCashFlow();
        $primary = $this->makeBank('Primary', 'TEST_ACF2_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_ACF2_DUP');

        $acf = $this->makeAcf([['BankCode_ELG' => 'TEST_ACF2_DUP', 'BankCode_Third' => 'THIRD_B']]);

        $this->assertSame([$dup->id], $this->repair->applyDuplicateMerge($primary->id, [$dup->id])['merged']);

        $this->assertSame(['TEST_ACF2_PRI'], $this->elgCodes($acf), '只含 dup 碼的 ACF 要跟著改');
        $this->assertStringContainsString(
            (string) $acf->id,
            $this->auditDetail($dup->id, 'mismatch_acf_changed'),
            '被改寫的 ACF id 必須落審計——這個命令沒有回滾工具，只能靠審計回推'
        );
    }

    public function test_synced_cash_flow_bank_ids_are_audited(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_SID_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_SID_DUP');

        $cfb = $this->makeCashFlowBank($dup->id, 'TEST_SID_DUP');

        $this->assertSame([$dup->id], $this->repair->applyDuplicateMerge($primary->id, [$dup->id])['merged']);

        // 改指後這列與 primary 原生的同步列 code 與 bank_id 全同、完全不可分，所以 id 要落審計
        $this->assertStringContainsString(
            (string) $cfb,
            $this->auditDetail($dup->id, 'mismatch_code_synced'),
            '被同步的 cfb id 必須落審計'
        );
    }

    // ── previewDuplicateMerge（--dry-run 的唯讀預檢） ──────────────────────────

    public function test_preview_writes_nothing_at_all(): void
    {
        $this->makePayoutCashFlow();

        $primary = $this->makeBank('Primary', 'TEST_PV_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_PV_DUP');

        $cfb = $this->makeCashFlowBank($dup->id, 'TEST_PV_DUP');
        $acf = $this->makeAcf([['BankCode_ELG' => 'TEST_PV_DUP', 'BankCode_Third' => 'THIRD_A']]);

        $this->repair->previewDuplicateMerge($primary->id, [$dup->id]);

        // 預演報得再詳細，也不能動到任何一個位元
        $this->assertSame(1, $dup->fresh()->status, '預演不得停用 duplicate');
        $this->assertSame(
            'TEST_PV_DUP',
            (string) DB::table('cash_flow_banks')->where('id', $cfb)->value('bank_code'),
            '預演不得同步 bank_code'
        );
        $this->assertSame(
            (string) $dup->id,
            (string) DB::table('cash_flow_banks')->where('id', $cfb)->value('bank_id'),
            '預演不得改指 bank_id'
        );
        $this->assertSame(['TEST_PV_DUP'], $this->elgCodes($acf), '預演不得改寫 available_banks');
        $this->assertSame(
            0,
            DB::table('bank_dedup_logs')->where('action', 'like', 'mismatch_%')->count(),
            '預演不得寫審計'
        );
    }

    public function test_preview_reports_merge_with_affected_row_ids(): void
    {
        $this->makePayoutCashFlow();

        $primary = $this->makeBank('Primary', 'TEST_PVID_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_PVID_DUP');

        $synced = $this->makeCashFlowBank($dup->id, 'TEST_PVID_DUP');
        $acf = $this->makeAcf([['BankCode_ELG' => 'TEST_PVID_DUP', 'BankCode_Third' => 'THIRD_A']]);

        $row = $this->repair->previewDuplicateMerge($primary->id, [$dup->id])[0];

        $this->assertSame('merge', $row['verdict']);
        $this->assertNull($row['reason']);
        $this->assertSame([$synced], $row['cfb_code_synced']);
        $this->assertSame([], $row['cfb_code_kept']);
        $this->assertSame([$acf->id], $row['acf_changed']);
        $this->assertSame([], $row['acf_conflicts']);
        $this->assertSame(1, $row['fk']['cash_flow_banks'] ?? 0, '外鍵改指筆數要預告');
    }

    public function test_preview_flags_agent_cash_flow_holding_both_codes(): void
    {
        $this->makePayoutCashFlow();

        $primary = $this->makeBank('Primary', 'TEST_PVAC_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_PVAC_DUP');

        // 同一張 ACF 同時含兩碼 ⇒ 實跑會留置不改，預演必須先講出來
        $acf = $this->makeAcf([
            ['BankCode_ELG' => 'TEST_PVAC_PRI', 'BankCode_Third' => 'THIRD_A'],
            ['BankCode_ELG' => 'TEST_PVAC_DUP', 'BankCode_Third' => 'THIRD_B'],
        ]);

        $row = $this->repair->previewDuplicateMerge($primary->id, [$dup->id])[0];

        $this->assertSame('merge', $row['verdict']);
        $this->assertSame([$acf->id], $row['acf_conflicts'], '兩碼同張的 ACF 必須在預演就被點名');
        $this->assertSame([], $row['acf_changed']);

        // 與實跑一致：那張 ACF 不被改寫、且進了 conflict 審計
        $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $this->assertSame(['TEST_PVAC_PRI', 'TEST_PVAC_DUP'], $this->elgCodes($acf), '實跑同樣不改它');
    }

    public function test_preview_reports_drifted_cash_flow_bank_codes_as_kept(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_PVK_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_PVK_DUP');

        // bank_code 與 dup.code 不同 ＝ 既有 drift，實跑保留不動
        $drifted = $this->makeCashFlowBank($dup->id, 'AGENT_LAYER_CODE');

        $row = $this->repair->previewDuplicateMerge($primary->id, [$dup->id])[0];

        $this->assertSame([$drifted], $row['cfb_code_kept']);
        $this->assertSame([], $row['cfb_code_synced']);
    }

    public function test_preview_and_apply_agree_on_collision_skip(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_PVC_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_PVC_DUP');

        $this->makeCashFlowBank($primary->id, 'PRI_CODE');
        $this->makeCashFlowBank($dup->id, 'AGENT_LAYER_CODE');

        $preview = $this->repair->previewDuplicateMerge($primary->id, [$dup->id])[0];
        $applied = $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $this->assertSame('skip', $preview['verdict']);
        $this->assertSame([], $applied['merged'], '實跑同樣留置');

        // 預演報「會留置」實跑卻合併（或反之）比沒有預演更糟，兩邊的說明必須逐字一致
        $this->assertSame($applied['skipped'][0]['reason'], $preview['reason']);
    }

    public function test_preview_and_apply_agree_on_identity_mismatch_skip(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_PVI_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_PVI_DUP', type: 3);

        $preview = $this->repair->previewDuplicateMerge($primary->id, [$dup->id])[0];
        $applied = $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $this->assertSame('skip', $preview['verdict']);
        $this->assertSame([], $applied['merged']);
        $this->assertSame($applied['skipped'][0]['reason'], $preview['reason']);
        $this->assertSame(1, $dup->fresh()->status, '身分不符不得停用');
    }

    public function test_preview_and_apply_agree_on_self_merge_rejection(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_PVS_PRI');

        $preview = $this->repair->previewDuplicateMerge($primary->id, [$primary->id])[0];
        $applied = $this->repair->applyDuplicateMerge($primary->id, [$primary->id]);

        $this->assertSame('skip', $preview['verdict']);
        $this->assertSame($applied['skipped'][0]['reason'], $preview['reason']);
        $this->assertSame(1, $primary->fresh()->status);
    }

    public function test_preview_skips_missing_rows(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_PVM_PRI');
        $missingId = $primary->id + 999_000;

        $row = $this->repair->previewDuplicateMerge($primary->id, [$missingId])[0];

        $this->assertSame('skip', $row['verdict']);
        $this->assertStringContainsString((string) $missingId, (string) $row['reason']);
    }

    // ---------------------------------------------------------------------
    // absorbInactiveDuplicate（`--absorb-inactive`：人工點名停用列併入服務中列）
    // ---------------------------------------------------------------------

    public function test_inactive_duplicate_is_skipped_without_absorb_flag(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_ABS_PRI');
        $dup = $this->makeBank('Duplicate', 'TEST_ABS_DUP', status: 0);

        $preview = $this->repair->previewDuplicateMerge($primary->id, [$dup->id])[0];
        $applied = $this->repair->applyDuplicateMerge($primary->id, [$dup->id]);

        $this->assertSame('skip', $preview['verdict']);
        $this->assertStringContainsString('duplicate 已停用', (string) $preview['reason']);
        $this->assertFalse($preview['inactive_absorbed']);
        $this->assertSame([], $applied['merged']);
        $this->assertSame($preview['reason'], $applied['skipped'][0]['reason']);
    }

    public function test_absorb_inactive_repoints_bindings_and_audits_without_redeactivating(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_ABS_PRI2');
        $dup = $this->makeBank('Duplicate', 'TEST_ABS_DUP2', status: 0);
        $bindingId = (int) DB::table('member_bank_accounts')->insertGetId([
            'member_id' => 1, 'bank_id' => $dup->id, 'account_name' => 'Tester', 'account_no' => '0000000000',
            'operator_cs' => 0, 'status' => 1,
        ]);

        $result = $this->repair->applyDuplicateMerge($primary->id, [$dup->id], absorbInactiveDuplicate: true);

        $this->assertSame([$dup->id], $result['merged']);
        $this->assertSame(
            $primary->id,
            (int) DB::table('member_bank_accounts')->where('id', $bindingId)->value('bank_id'),
            '停用列上的活綁定要改指到承接列',
        );
        $this->assertSame(0, (int) $dup->fresh()->status, 'dup 維持停用');
        $this->assertSame(1, (int) $primary->fresh()->status, 'primary 不動');

        $absorbed = json_decode($this->auditDetail($dup->id, 'mismatch_inactive_merged'), true);
        $this->assertSame(['dup_status' => 0], $absorbed, '人工點名併入停用列必須獨立留審計');
        $this->assertSame(
            0,
            DB::table('bank_dedup_logs')->where('loser_bank_id', $dup->id)->where('action', 'mismatch_deactivate_bank')->count(),
            '本來就停用的列不再記一次停用',
        );
        $repoint = json_decode($this->auditDetail($dup->id, 'mismatch_repoint'), true);
        $this->assertSame(1, $repoint['fk']['member_bank_accounts']);
    }

    public function test_absorb_inactive_rejects_active_duplicate(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_ABS_PRI3');
        $dup = $this->makeBank('Duplicate', 'TEST_ABS_DUP3');

        $preview = $this->repair->previewDuplicateMerge($primary->id, [$dup->id], absorbInactiveDuplicate: true)[0];
        $applied = $this->repair->applyDuplicateMerge($primary->id, [$dup->id], absorbInactiveDuplicate: true);

        $this->assertSame('skip', $preview['verdict']);
        $this->assertStringContainsString('未停用', (string) $preview['reason']);
        $this->assertSame([], $applied['merged']);
        $this->assertSame($preview['reason'], $applied['skipped'][0]['reason']);
        $this->assertSame(1, (int) $dup->fresh()->status, '服務中的列不得被旗標順手停用');
    }

    public function test_absorb_inactive_rejects_previously_converged_loser(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_ABS_PRI4');
        $dup = $this->makeBank('Duplicate', 'TEST_ABS_DUP4', status: 0);
        DB::table('bank_dedup_logs')->insert([
            'connection' => DB::connection()->getName(),
            'agent_group_id' => 0,
            'code' => 'TEST_ABS',
            'survivor_bank_id' => $primary->id,
            'loser_bank_id' => $dup->id,
            'action' => 'mismatch_deactivate_bank',
            'detail' => '[]',
        ]);

        $preview = $this->repair->previewDuplicateMerge($primary->id, [$dup->id], absorbInactiveDuplicate: true)[0];
        $applied = $this->repair->applyDuplicateMerge($primary->id, [$dup->id], absorbInactiveDuplicate: true);

        $this->assertSame('skip', $preview['verdict']);
        $this->assertStringContainsString('曾作為 loser', (string) $preview['reason']);
        $this->assertSame([], $applied['merged']);
        $this->assertSame($preview['reason'], $applied['skipped'][0]['reason']);
        $this->assertSame(
            0,
            DB::table('bank_dedup_logs')->where('loser_bank_id', $dup->id)->where('action', 'mismatch_inactive_merged')->count(),
            '被擋下就不得留下「已併入」審計',
        );
    }

    public function test_absorb_inactive_preview_agrees_with_apply(): void
    {
        $primary = $this->makeBank('Primary', 'TEST_ABS_PRI5');
        $dup = $this->makeBank('Duplicate', 'TEST_ABS_DUP5', status: 0);

        $preview = $this->repair->previewDuplicateMerge($primary->id, [$dup->id], absorbInactiveDuplicate: true)[0];

        $this->assertSame('merge', $preview['verdict']);
        $this->assertTrue($preview['inactive_absorbed'], '預演必須標示這是人工點名的停用列');
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('loser_bank_id', $dup->id)->count(), '預演不寫入');

        $applied = $this->repair->applyDuplicateMerge($primary->id, [$dup->id], absorbInactiveDuplicate: true);

        $this->assertSame([$dup->id], $applied['merged']);
    }
}
