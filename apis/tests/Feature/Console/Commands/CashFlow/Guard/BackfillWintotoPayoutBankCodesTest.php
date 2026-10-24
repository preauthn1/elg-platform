<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Guard;

use App\Console\Commands\CashFlow\Guard\BackfillWintotoPayoutBankCodes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DetachesBanksActiveGuard;
use Tests\TestCase;

/**
 * BackfillWintotoPayoutBankCodes 測試
 *
 * Wintoto TTPay/DGPay Manual 渠道 available_banks 缺 canonical 碼（MayBank/OCBC）補碼：
 * 追加式（不動既有佔位條目）、冪等、防呆整體攔截。
 * 用 DatabaseTransactions 避免污染共享庫；以配對表的真實 ACF id 建列（先清同 id 殘留）。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/Patch/BackfillWintotoPayoutBankCodesTest.php
 */
class BackfillWintotoPayoutBankCodesTest extends TestCase
{
    use DatabaseTransactions;
    use DetachesBanksActiveGuard;

    /** g70 雙碼樣本 */
    private const G70_ACF_ID = 179;

    /** g93 單碼樣本 */
    private const G93_ACF_ID = 220;

    /**
     * seed 全部目標 ACF 與 guard 用 canonical banks（先清同 id / 同碼殘留）。
     */
    private function seedTargets(): void
    {
        $acfIds = array_column(BackfillWintotoPayoutBankCodes::TARGETS, 'acf_id');

        DB::table('agent_cash_flows')->whereIn('id', $acfIds)->delete();
        DB::table('banks')
            ->whereIn('agent_group_id', [70, 93])
            ->where('type', BackfillWintotoPayoutBankCodes::BANK_TYPE)
            ->where('currency_id', BackfillWintotoPayoutBankCodes::CURRENCY_ID)
            ->whereIn('code', ['MayBank', 'OCBC'])
            ->delete();

        foreach (BackfillWintotoPayoutBankCodes::TARGETS as $target) {
            $this->insertAgentCashFlow($target['acf_id'], $target['agent_group_id']);

            foreach ($target['entries'] as $entry) {
                $this->insertCanonicalBank($target['agent_group_id'], $entry['target']);
            }
        }
    }

    private function insertAgentCashFlow(int $id, int $agentGroupId, ?array $availableBanks = null): void
    {
        DB::table('agent_cash_flows')->insert([
            'id' => $id,
            'cash_flow_info_id' => 0,
            'cash_flow_id' => 39,
            'agent_group_id' => $agentGroupId,
            'currency_id' => BackfillWintotoPayoutBankCodes::CURRENCY_ID,
            'name' => 'Test Payout Channel ' . $id,
            'info' => json_encode([
                'DisplayName' => 'Test Channel ' . $id,
                'available_banks' => $availableBanks ?? [
                    ['BankCode_ELG' => 'WTMY01', 'BankCode_Third' => 'PBBB'],
                    ['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'MAYB'],
                    ['BankCode_ELG' => 'WTMY09', 'BankCode_Third' => 'OCBC'],
                ],
            ]),
            'status' => 1,
            'admin' => 0,
        ]);
    }

    private function insertCanonicalBank(int $agentGroupId, string $code): void
    {
        // ⚠️ seed 只清 MayBank／OCBC 兩碼；TARGETS 內同 group 的多張 acf（179/297、220/298）
        // entries 相同，會插出同 (group, type, currency, code) 的多筆 active 列——這正是本類
        // 需要 DetachesBanksActiveGuard 卸鎖的原因
        DB::table('banks')->insert([
            'agent_group_id' => $agentGroupId,
            'type' => BackfillWintotoPayoutBankCodes::BANK_TYPE,
            'currency_id' => BackfillWintotoPayoutBankCodes::CURRENCY_ID,
            'code' => $code,
            'name' => 'Bank ' . $code,
            'status' => 1,
        ]);
    }

    /**
     * @return list<array{BankCode_ELG: string, BankCode_Third: string}>
     */
    private function availableBanksOf(int $acfId): array
    {
        $info = json_decode((string) DB::table('agent_cash_flows')->where('id', $acfId)->value('info'), true);

        return $info['available_banks'] ?? [];
    }

    private function runExecute(int $expectedExitCode = 0): void
    {
        $this->artisan('cash-flow:backfill-wintoto-payout-bankcodes', ['--execute' => true, '--force' => true])
            ->assertExitCode($expectedExitCode);
    }

    // -------------------------------------------------------------------------
    // Test 1: 預設 dry-run 零寫入
    // -------------------------------------------------------------------------
    public function test_dry_run_writes_nothing(): void
    {
        $this->seedTargets();

        $this->artisan('cash-flow:backfill-wintoto-payout-bankcodes')->assertExitCode(0);

        $this->assertCount(3, $this->availableBanksOf(self::G70_ACF_ID));
        $this->assertCount(3, $this->availableBanksOf(self::G93_ACF_ID));
    }

    // -------------------------------------------------------------------------
    // Test 2: execute 追加缺漏條目、既有條目與其他 info key 不動
    // -------------------------------------------------------------------------
    public function test_execute_appends_missing_entries(): void
    {
        $this->seedTargets();

        $this->runExecute();

        // g70：追加 MayBank + OCBC（3 → 5）
        $g70Banks = $this->availableBanksOf(self::G70_ACF_ID);
        $this->assertCount(5, $g70Banks);
        $this->assertContains(['BankCode_ELG' => 'MayBank', 'BankCode_Third' => 'MAYB'], $g70Banks);
        $this->assertContains(['BankCode_ELG' => 'OCBC', 'BankCode_Third' => 'OCBC'], $g70Banks);
        // 既有佔位條目保留
        $this->assertContains(['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'MAYB'], $g70Banks);

        // g93：只追加 OCBC（3 → 4）
        $g93Banks = $this->availableBanksOf(self::G93_ACF_ID);
        $this->assertCount(4, $g93Banks);
        $this->assertContains(['BankCode_ELG' => 'OCBC', 'BankCode_Third' => 'OCBC'], $g93Banks);

        // 其他 info key 不動
        $info = json_decode((string) DB::table('agent_cash_flows')->where('id', self::G70_ACF_ID)->value('info'), true);
        $this->assertSame('Test Channel ' . self::G70_ACF_ID, $info['DisplayName']);
    }

    // -------------------------------------------------------------------------
    // Test 3: 冪等：第二次執行零新增
    // -------------------------------------------------------------------------
    public function test_execute_is_idempotent(): void
    {
        $this->seedTargets();

        $this->runExecute();
        $this->runExecute();

        $this->assertCount(5, $this->availableBanksOf(self::G70_ACF_ID));
        $this->assertCount(4, $this->availableBanksOf(self::G93_ACF_ID));
    }

    // -------------------------------------------------------------------------
    // Test 4: canonical bank 不存在（跑錯環境）→ 整體攔截零寫入
    // -------------------------------------------------------------------------
    public function test_aborts_when_canonical_bank_missing(): void
    {
        $this->seedTargets();
        DB::table('banks')
            ->where('agent_group_id', 70)
            ->where('code', 'MayBank')
            ->delete();

        $this->runExecute(1);

        $this->assertCount(3, $this->availableBanksOf(self::G70_ACF_ID));
        $this->assertCount(3, $this->availableBanksOf(self::G93_ACF_ID));
    }

    // -------------------------------------------------------------------------
    // Test 5: 既有同 target 條目但三方碼不同 → 整體攔截零寫入（轉人工）
    // -------------------------------------------------------------------------
    public function test_aborts_when_existing_elg_entry_has_different_third_code(): void
    {
        $this->seedTargets();
        DB::table('agent_cash_flows')->where('id', self::G70_ACF_ID)->delete();
        $this->insertAgentCashFlow(self::G70_ACF_ID, 70, [
            ['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'MAYB'],
            ['BankCode_ELG' => 'MayBank', 'BankCode_Third' => 'WRONG'],
        ]);

        $this->runExecute(1);

        $this->assertCount(3, $this->availableBanksOf(self::G93_ACF_ID));
    }

    // -------------------------------------------------------------------------
    // Test 6: 三方碼在該 ACF 既有清單坐實不了 → 整體攔截零寫入
    // -------------------------------------------------------------------------
    public function test_aborts_when_third_code_not_substantiated(): void
    {
        $this->seedTargets();
        DB::table('agent_cash_flows')->where('id', self::G70_ACF_ID)->delete();
        // 清單裡沒有任何 MAYB 三方碼 → 無法坐實
        $this->insertAgentCashFlow(self::G70_ACF_ID, 70, [
            ['BankCode_ELG' => 'WTMY01', 'BankCode_Third' => 'PBBB'],
            ['BankCode_ELG' => 'WTMY09', 'BankCode_Third' => 'OCBC'],
        ]);

        $this->runExecute(1);

        $this->assertCount(2, $this->availableBanksOf(self::G70_ACF_ID));
        $this->assertCount(3, $this->availableBanksOf(self::G93_ACF_ID));
    }
}
