<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Maintenance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MigrateMemberBankAccounts 測試
 *
 * 與 dedup 家族同屬「會員綁定兩案分流」：情況1（會員同時有基準與目標）停用目標帳戶，
 * 情況2（只有目標）把目標改指基準。測試守的核心不變式是「收斂後會員仍有 active 綁定」——
 * 前台提款銀行清單要求 member_bank_accounts.status=1，落到零有效綁定即無法提款。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/Maintenance/MigrateMemberBankAccountsTest.php
 */
class MigrateMemberBankAccountsTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE_BANK_ID = 991101;

    private const TARGET_BANK_ID = 991102;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('member_bank_accounts')->whereIn('bank_id', [self::BASE_BANK_ID, self::TARGET_BANK_ID])->delete();
        DB::table('banks')->whereIn('id', [self::BASE_BANK_ID, self::TARGET_BANK_ID])->delete();

        $this->makeBank(self::BASE_BANK_ID, 'MIGBASE1');
        $this->makeBank(self::TARGET_BANK_ID, 'MIGTGT1');
    }

    private function makeBank(int $id, string $code): void
    {
        DB::table('banks')->insert([
            'id' => $id,
            'agent_group_id' => 674,
            'type' => 1,
            'currency_id' => 1,
            'code' => $code,
            'name' => '(MYR) - Migrate Sample ' . $code,
            'status' => 1,
        ]);
    }

    private function makeBinding(int $memberId, int $bankId, int $status = 1): int
    {
        return (int) DB::table('member_bank_accounts')->insertGetId([
            'member_id' => $memberId,
            'bank_id' => $bankId,
            'account_name' => 'TEST HOLDER',
            'account_no' => 'ACC-' . $memberId . '-' . $bankId,
            'status' => $status,
            'operator_cs' => 0,
        ]);
    }

    private function runFix(): void
    {
        $this->artisan('member:migrate-bank-accounts', [
            '--base-bank' => self::BASE_BANK_ID,
            '--target-banks' => (string) self::TARGET_BANK_ID,
            '--fix' => true,
        ])
            ->expectsConfirmation('確認執行修復嗎？', 'yes')
            ->assertExitCode(0);
    }

    private function activeBindingCount(int $memberId): int
    {
        return DB::table('member_bank_accounts')
            ->where('member_id', $memberId)
            ->where('status', 1)
            ->count();
    }

    /**
     * 「基準綁定已停用＋目標綁定啟用」必須走情況2（改指），不得走情況1。
     *
     * 三處判定（checkSituation1 的子查詢、checkSituation2 的 whereNotIn、fixSituation2 的
     * exists）若都不限 status=1，這種會員會被當成「已有基準帳戶」而停掉唯一啟用的目標帳戶，
     * 落到零有效綁定、無法提款，且命令 exit 0、看起來一切正常。
     */
    public function test_member_whose_base_binding_is_inactive_keeps_an_active_binding(): void
    {
        $baseBindingId = $this->makeBinding(memberId: 991001, bankId: self::BASE_BANK_ID, status: 0);
        $targetBindingId = $this->makeBinding(memberId: 991001, bankId: self::TARGET_BANK_ID);

        $this->runFix();

        $this->assertSame(
            self::BASE_BANK_ID,
            (int) DB::table('member_bank_accounts')->where('id', $targetBindingId)->value('bank_id'),
            '基準綁定已停用者應走情況2 改指，不該被當成「已有基準帳戶」',
        );
        $this->assertSame(
            1,
            (int) DB::table('member_bank_accounts')->where('id', $targetBindingId)->value('status'),
            '改指不得順手停用該帳戶',
        );
        $this->assertSame(
            0,
            (int) DB::table('member_bank_accounts')->where('id', $baseBindingId)->value('status'),
            '原本就停用的基準綁定維持原狀',
        );

        $this->assertSame(1, $this->activeBindingCount(991001), '修復後會員必須仍有 active 綁定，否則無法提款');
    }

    /** 情況1 正常路徑：會員的基準帳戶是啟用的，目標帳戶才該被停用。 */
    public function test_situation1_deactivates_target_when_base_binding_is_active(): void
    {
        $baseBindingId = $this->makeBinding(memberId: 991002, bankId: self::BASE_BANK_ID);
        $targetBindingId = $this->makeBinding(memberId: 991002, bankId: self::TARGET_BANK_ID);

        $this->runFix();

        $this->assertSame(0, (int) DB::table('member_bank_accounts')->where('id', $targetBindingId)->value('status'));
        $this->assertSame(1, (int) DB::table('member_bank_accounts')->where('id', $baseBindingId)->value('status'));
        $this->assertSame(1, $this->activeBindingCount(991002));
    }

    /** 情況2 正常路徑：完全沒有基準帳戶者，目標帳戶改指基準。 */
    public function test_situation2_repoints_target_to_base_when_no_base_binding_exists(): void
    {
        $targetBindingId = $this->makeBinding(memberId: 991003, bankId: self::TARGET_BANK_ID);

        $this->runFix();

        $binding = DB::table('member_bank_accounts')->where('id', $targetBindingId)->first();
        $this->assertSame(self::BASE_BANK_ID, (int) $binding->bank_id);
        $this->assertSame(1, (int) $binding->status);
        $this->assertSame(1, $this->activeBindingCount(991003));
    }

    /**
     * 情況2 的改指不得蓋掉 updated_at。
     *
     * `member_bank_accounts` 與 `withdrawal_orders` 都帶 `on update CURRENT_TIMESTAMP`，
     * 而銀行收斂是「換綁到另一個 banks row」的技術搬移、不是業務事件；
     * `withdrawal_orders.updated_at` 更是前端訂單排序欄——2026-08-27 六站 2,927 筆生產
     * 訂單順序混亂即為此形態（見 `App\Traits\PreservesRowTimestamps` 檔頭）。
     *
     * 驗 SQL 字串而非欄位值：DDL 的 `on update` 會讓「比對值有沒有變」的斷言恆真
     * （`.claude/rules/php.md`「等價重構的驗證」）。
     */
    public function test_situation2_repoint_preserves_updated_at(): void
    {
        $this->makeBinding(memberId: 991004, bankId: self::TARGET_BANK_ID);

        $statements = $this->captureSql(fn () => $this->runFix());

        $repoints = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'update `member_bank_accounts`')
                && str_contains($sql, 'set `bank_id` = ?'),
        ));

        $this->assertNotEmpty($repoints, '情況2 應發出綁定改指 SQL');

        foreach ($repoints as $sql) {
            $this->assertStringContainsString('`updated_at` = `updated_at`', $sql, '綁定改指不可蓋掉 updated_at：' . $sql);
        }

        $orderSyncs = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'update `withdrawal_orders`'),
        ));

        $this->assertNotEmpty($orderSyncs, '情況2 應發出出款訂單同步 SQL（即使 0 筆命中）');

        foreach ($orderSyncs as $sql) {
            $this->assertStringContainsString('`updated_at` = `updated_at`', $sql, '出款訂單同步不可蓋掉 updated_at：' . $sql);
        }
    }

    /**
     * 情況1 的停用同樣是收斂副作用（會員自己沒做任何事），比照 MergeWintotoDriftBanks:697
     * 對 `status => 0` 也保留時間戳。
     */
    public function test_situation1_deactivation_preserves_updated_at(): void
    {
        $this->makeBinding(memberId: 991005, bankId: self::BASE_BANK_ID);
        $this->makeBinding(memberId: 991005, bankId: self::TARGET_BANK_ID);

        $statements = $this->captureSql(fn () => $this->runFix());

        $deactivations = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'update `member_bank_accounts`')
                && str_contains($sql, 'set `status` = ?'),
        ));

        $this->assertNotEmpty($deactivations, '情況1 應發出綁定停用 SQL');

        foreach ($deactivations as $sql) {
            $this->assertStringContainsString('`updated_at` = `updated_at`', $sql, '綁定停用不可蓋掉 updated_at：' . $sql);
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
}
