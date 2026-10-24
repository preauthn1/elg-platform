<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Currency;

use App\Enums\AgentMigrateLogTypeEnum;
use App\Enums\CurrencyEnum;
use App\Enums\MemberStatusEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberAgent;
use App\Models\Organization;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Tests\TestCase;

/**
 * currency:audit-orphan-parent 的行為測試。
 *
 * 🔴 不要在這裡加 RefreshDatabase：它對 MySQL 測試庫會跑 migrate:fresh，無條件 drop 全部資料表重建，
 *    procedure_hashes 會被清空、procedure 測試全紅，在 CI 上還會依 paratest 的分配變成隨機紅。
 *    父類 Tests\TestCase 已 use DatabaseTransactions，每個測試結束自動回滾。
 *
 * ⚠️ 命令的輸出是「全表」統計，會被測試庫裡任何殘留的 members 列影響。因此查統計數的測試都先跑一次
 *    取基準值、建好夾具再跑一次，只斷言兩次之間的差量；缺失 parent 用不可能存在的超大 id，
 *    並以 --top 放寬到足以把它列進來。退出碼／輸出格式的測試不查統計數，以隔離 id 與固定字串斷言。
 *
 * CI 的 currencies 是空表（migrate:fresh 不灌幣別），setUp 先灌 currencies.php 讓「resolved」
 * 這一跳有主檔可對，測試結束由 DatabaseTransactions 回滾。
 *
 * php artisan test tests/Feature/Console/Commands/Currency/AuditOrphanParentCommandTest.php
 */
class AuditOrphanParentCommandTest extends TestCase
{
    /** 遠大於任何測試庫會用到的 id，但仍在 int unsigned 範圍內。 */
    private const int ABSENT_ID_BASE = 2_000_000_000;

    /** 放寬到足以把測試建立的缺失 parent 列進「前 N 名」。 */
    private const int TOP = 500;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CurrencySeeder::class);
    }

    public function test_member_whose_chain_reaches_master_is_resolved(): void
    {
        $before = $this->audit();

        $agent = $this->makeAgent($this->makeOrganization(CurrencyEnum::MYR->value)->id);
        $this->makeMember($agent->id);

        $after = $this->audit();

        $this->assertSame(1, $this->delta($before, $after, 'resolved'));
        $this->assertSame(1, $after['members_total'] - $before['members_total']);
        $this->assertSame(0, $after['unresolved_total'] - $before['unresolved_total']);
        // factory 建的代理沒有 hierarchy_path，進不了子代理索引，要被誠實報出來
        $this->assertSame(1, $after['missing_parents']['agents_without_hierarchy_path'] - $before['missing_parents']['agents_without_hierarchy_path']);
    }

    public function test_member_without_parent_is_parent_zero_and_has_no_hint(): void
    {
        $before = $this->audit();

        $this->makeMember(0);

        $after = $this->audit();

        $this->assertSame(1, $this->delta($before, $after, 'parent_zero'));
        $this->assertSame(1, $after['hints']['parent_zero']['total'] - $before['hints']['parent_zero']['total']);
        $this->assertSame(1, $after['hints']['parent_zero']['no_hint'] - $before['hints']['parent_zero']['no_hint']);
    }

    public function test_orphan_parent_is_listed_with_descendant_and_member_agent_hints(): void
    {
        $missing = $this->absentId();
        $before = $this->audit();

        $organization = $this->makeOrganization(CurrencyEnum::MYR->value);
        // 子代理：路徑裡帶著已不存在的 parent，代表它曾經存在、下線仍在。
        $descendant = $this->makeAgent($organization->id);
        $descendant->update(['hierarchy_path' => sprintf('/1/%d/%d', $missing, $descendant->id)]);

        $hinted = $this->makeMember($missing);
        $this->makeMember($missing, MemberStatusEnum::UNVERIFIED);
        MemberAgent::query()->create(['member_id' => $hinted->id, 'parent_id' => $descendant->id]);

        $after = $this->audit();

        $this->assertSame(2, $this->delta($before, $after, 'parent_missing'));
        $this->assertSame(1, $after['missing_parents']['distinct_total'] - $before['missing_parents']['distinct_total']);
        // 前 N 名有把這個 parent 列進來，覆蓋人數就要多 2；線索來源表也多了 1 列
        $this->assertSame(2, $after['missing_parents']['covered_members'] - $before['missing_parents']['covered_members']);
        $this->assertSame(1, $after['hint_sources']['member_agent_rows'] - $before['hint_sources']['member_agent_rows']);

        $row = $this->missingParentRow($after, $missing);
        $this->assertSame(2, $row['members']);
        $this->assertSame(1, $row['active']);
        $this->assertSame(1, $row['descendant_agents']);
        $this->assertSame(1, $row['descendant_organizations']);
        $this->assertSame($organization->id, $row['inferred_organization_id']);

        $hints = $this->hintDelta($before, $after, 'parent_missing');
        $this->assertSame(['total' => 2, 'via_member_agent' => 1, 'via_migrate_log' => 0, 'any_hint' => 1, 'no_hint' => 1], $hints);
    }

    public function test_descendants_spread_over_organizations_do_not_infer_one(): void
    {
        $missing = $this->absentId();

        foreach ([CurrencyEnum::MYR->value, CurrencyEnum::SGD->value] as $currencyId) {
            $descendant = $this->makeAgent($this->makeOrganization($currencyId)->id);
            $descendant->update(['hierarchy_path' => sprintf('/1/%d/%d', $missing, $descendant->id)]);
        }
        $this->makeMember($missing);

        $row = $this->missingParentRow($this->audit(), $missing);

        $this->assertSame(2, $row['descendant_agents']);
        $this->assertSame(2, $row['descendant_organizations']);
        $this->assertNull($row['inferred_organization_id']);
    }

    public function test_member_agent_pointing_to_absent_agent_is_not_a_hint(): void
    {
        $missing = $this->absentId();
        $before = $this->audit();

        // TPower 實況：member_agent.parent_id 與 members.parent 指向同一個已不存在的代理，推不回任何東西。
        $member = $this->makeMember($missing);
        MemberAgent::query()->create(['member_id' => $member->id, 'parent_id' => $missing]);

        $hints = $this->hintDelta($before, $this->audit(), 'parent_missing');

        $this->assertSame(['total' => 1, 'via_member_agent' => 0, 'via_migrate_log' => 0, 'any_hint' => 0, 'no_hint' => 1], $hints);
    }

    public function test_migrate_log_to_existing_agent_is_a_hint(): void
    {
        $before = $this->audit();

        $target = $this->makeAgent($this->makeOrganization(CurrencyEnum::MYR->value)->id);
        $member = $this->makeMember($this->absentId());
        // agent_migrate_logs 沒有 Model，夾具直接寫表；DatabaseTransactions 會回滾。
        DB::table('agent_migrate_logs')->insert([
            'type' => AgentMigrateLogTypeEnum::MEMBER->value,
            'agent_id' => $target->id,
            'member_id' => $member->id,
            'orig_agent_id' => $member->parent,
            'admin' => 0,
        ]);

        $after = $this->audit();

        // 線索來源列數要跟著動：讀者靠它分辨「無線索」是推不回還是表根本沒資料
        $this->assertSame(1, $after['hint_sources']['migrate_log_member_rows'] - $before['hint_sources']['migrate_log_member_rows']);
        $hints = $this->hintDelta($before, $after, 'parent_missing');

        $this->assertSame(['total' => 1, 'via_member_agent' => 0, 'via_migrate_log' => 1, 'any_hint' => 1, 'no_hint' => 0], $hints);
    }

    public function test_agent_pointing_to_absent_organization_breaks_at_hop_two(): void
    {
        $before = $this->audit();

        $agent = $this->makeAgent($this->absentId());
        $this->makeMember($agent->id);

        $this->assertSame(1, $this->delta($before, $this->audit(), 'organization_missing'));
    }

    public function test_organization_without_currency_breaks_at_hop_three(): void
    {
        $before = $this->audit();

        $agent = $this->makeAgent($this->makeOrganization(0)->id);
        $this->makeMember($agent->id);

        $this->assertSame(1, $this->delta($before, $this->audit(), 'organization_currency_zero'));
    }

    public function test_organization_currency_absent_from_master_breaks_at_hop_four(): void
    {
        $before = $this->audit();

        $agent = $this->makeAgent($this->makeOrganization($this->absentId())->id);
        $this->makeMember($agent->id);

        $this->assertSame(1, $this->delta($before, $this->audit(), 'currency_not_in_master'));
    }

    public function test_exit_code_is_failure_while_any_member_is_unresolved(): void
    {
        $this->makeMember(0);

        $this->assertSame(SymfonyCommand::FAILURE, Artisan::call('currency:audit-orphan-parent', ['--json' => true]));
    }

    public function test_non_positive_top_is_rejected(): void
    {
        $this->assertSame(SymfonyCommand::INVALID, Artisan::call('currency:audit-orphan-parent', ['--top' => 0]));
        $this->assertStringContainsString('--top 必須是正整數', Artisan::output());
    }

    public function test_table_output_lists_every_chain_state(): void
    {
        Artisan::call('currency:audit-orphan-parent', ['--top' => 1]);
        $output = Artisan::output();

        foreach (['parent_zero', 'parent_missing', 'organization_missing', 'organization_currency_zero', 'currency_not_in_master', 'resolved'] as $state) {
            $this->assertStringContainsString($state, $output);
        }
        $this->assertStringContainsString('缺失 parent 共', $output);
    }

    // ---------- 夾具 ----------

    private function makeOrganization(int $currencyId): Organization
    {
        return Organization::query()->create([
            'name' => 'orphan-audit-' . Str::random(8),
            'currency_id' => $currencyId,
            'memo' => '',
        ]);
    }

    private function makeAgent(int $organizationId): Agent
    {
        return Agent::factory()->create(['organization_id' => $organizationId]);
    }

    private function makeMember(int $parent, MemberStatusEnum $status = MemberStatusEnum::VERIFIED): Member
    {
        return Member::factory()->create([
            'parent' => $parent,
            'status' => $status->value,
            'username' => 'orphan_' . Str::lower(Str::random(10)),
        ]);
    }

    /**
     * 每次呼叫給一個不同的、不可能存在於測試庫的 id。
     */
    private function absentId(): int
    {
        static $offset = 0;

        return self::ABSENT_ID_BASE + (++$offset) * 1_000 + random_int(0, 999);
    }

    // ---------- 讀輸出 ----------

    /**
     * @return array<string, mixed>
     */
    private function audit(): array
    {
        Artisan::call('currency:audit-orphan-parent', ['--json' => true, '--top' => self::TOP]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * 某個鏈路狀態在兩次稽核之間的會員數差量。
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function delta(array $before, array $after, string $state): int
    {
        return $this->stateTotal($after, $state) - $this->stateTotal($before, $state);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function stateTotal(array $report, string $state): int
    {
        foreach ($report['states'] as $row) {
            if ($row['state'] === $state) {
                return (int) $row['total'];
            }
        }

        $this->fail(sprintf('稽核輸出裡沒有鏈路狀態「%s」', $state));
    }

    /**
     * 某一群會員的五個線索數字在兩次稽核之間的差量。
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, int>
     */
    private function hintDelta(array $before, array $after, string $cohort): array
    {
        $delta = [];

        foreach (['total', 'via_member_agent', 'via_migrate_log', 'any_hint', 'no_hint'] as $key) {
            $delta[$key] = (int) $after['hints'][$cohort][$key] - (int) $before['hints'][$cohort][$key];
        }

        return $delta;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function missingParentRow(array $report, int $parent): array
    {
        foreach ($report['missing_parents']['top'] as $row) {
            if ((int) $row['parent'] === $parent) {
                return $row;
            }
        }

        $this->fail(sprintf(
            '前 %d 名缺失 parent 裡找不到 %d，實際有：%s',
            self::TOP,
            $parent,
            implode('、', array_column($report['missing_parents']['top'], 'parent')),
        ));
    }
}
