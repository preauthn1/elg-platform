<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CashFlow\Bank;

use App\Services\CashFlow\Bank\BankActiveDedupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DetachesBanksActiveGuard;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Services/CashFlow/Bank/BankActiveDedupServiceTest.php
 */
class BankActiveDedupServiceTest extends TestCase
{
    use DetachesBanksActiveGuard;

    /**
     * 以 raw insert 建立 bank 列，可完整控制 created_at（model 將其列為 guarded）。
     *
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

    public function test_detects_active_duplicate_and_picks_newest_created_at_as_survivor(): void
    {
        $legacyId = $this->makeBank(['created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $newId = $this->makeBank(['created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $groups = app(BankActiveDedupService::class)->detectDuplicateGroups();

        $this->assertCount(1, $groups);
        $group = $groups->first();
        $this->assertSame(674, $group['agent_group_id']);
        $this->assertSame('MYR0023', $group['code']);
        $this->assertSame($newId, $group['survivor_id']);
        $this->assertSame([$legacyId], $group['loser_ids']);
    }

    public function test_inactive_partner_is_not_treated_as_active_duplicate(): void
    {
        // 一筆 active + 一筆已停用（同自然鍵）→ active 只有 1 筆，不算重複
        $this->makeBank(['status' => 1, 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $this->makeBank(['status' => 0, 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);

        $groups = app(BankActiveDedupService::class)->detectDuplicateGroups();

        $this->assertCount(0, $groups);
    }

    public function test_distinct_natural_keys_are_not_grouped(): void
    {
        $this->makeBank(['code' => 'MYR0023']);
        $this->makeBank(['code' => 'MYR0035']);

        $groups = app(BankActiveDedupService::class)->detectDuplicateGroups();

        $this->assertCount(0, $groups);
    }

    public function test_three_active_duplicates_keep_newest_and_list_rest_as_losers(): void
    {
        $oldest = $this->makeBank(['created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $middle = $this->makeBank(['created_at' => Carbon::parse('2025-06-01 00:00:00')]);
        $newest = $this->makeBank(['created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $groups = app(BankActiveDedupService::class)->detectDuplicateGroups();

        $this->assertCount(1, $groups);
        $group = $groups->first();
        $this->assertSame($newest, $group['survivor_id']);
        $this->assertEqualsCanonicalizing([$oldest, $middle], $group['loser_ids']);
    }

    public function test_detects_inactive_shadow_group_with_single_active_survivor(): void
    {
        // TPower AGRO 實案形態：同名同碼一列 active + 一列 inactive shadow
        $shadowId = $this->makeBank(['status' => 0, 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $activeId = $this->makeBank(['status' => 1, 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $groups = app(BankActiveDedupService::class)->detectInactiveShadowGroups();

        $this->assertCount(1, $groups);
        $group = $groups->first();
        $this->assertSame($activeId, $group['survivor_id']);
        $this->assertSame([$shadowId], $group['loser_ids']);
        $this->assertSame('(MYR) - AGRO Bank', $group['name']);
    }

    public function test_all_inactive_same_name_is_not_shadow_group(): void
    {
        // 無 active 正身可指 → 不屬 shadow 清理範疇
        $this->makeBank(['status' => 0]);
        $this->makeBank(['status' => 0, 'code' => 'MYR0099']);

        $this->assertCount(0, app(BankActiveDedupService::class)->detectInactiveShadowGroups());
    }

    public function test_multiple_active_same_name_is_not_shadow_group(): void
    {
        // 多筆 active 同名屬 active 重複（detectDuplicateGroups／人工）職責，shadow 偵測不越界
        $this->makeBank(['status' => 1]);
        $this->makeBank(['status' => 1, 'code' => 'MYR0099']);
        $this->makeBank(['status' => 0]);

        $this->assertCount(0, app(BankActiveDedupService::class)->detectInactiveShadowGroups());
    }

    public function test_already_soft_deleted_shadow_is_ignored(): void
    {
        $this->makeBank(['status' => 0, 'deleted_at' => Carbon::parse('2026-03-01 00:00:00')]);
        $this->makeBank(['status' => 1]);

        $this->assertCount(0, app(BankActiveDedupService::class)->detectInactiveShadowGroups());
    }

    public function test_shadow_groups_split_by_scope_columns(): void
    {
        // 不同 agent_group_id 不得混組
        $this->makeBank(['status' => 0]);
        $this->makeBank(['status' => 1, 'agent_group_id' => 688]);

        $this->assertCount(0, app(BankActiveDedupService::class)->detectInactiveShadowGroups());
    }

    public function test_shadow_detection_groups_case_variant_names(): void
    {
        // 大小寫變體（TPower MUFG BANK/MUFG Bank 實案）：MySQL ci collation 下同名搜尋會互相撈到，
        // 偵測分組須 case-fold 對齊，否則變體 shadow 漏清
        $shadowId = $this->makeBank(['status' => 0, 'name' => '(MYR) - MUFG BANK']);
        $activeId = $this->makeBank(['status' => 1, 'name' => '(MYR) - MUFG Bank']);

        $groups = app(BankActiveDedupService::class)->detectInactiveShadowGroups();

        $this->assertCount(1, $groups);
        $group = $groups->first();
        $this->assertSame($activeId, $group['survivor_id']);
        $this->assertSame([$shadowId], $group['loser_ids']);
        $this->assertSame('(MYR) - MUFG Bank', $group['name'], 'group name 應取 active 正身的原文');
    }
}
